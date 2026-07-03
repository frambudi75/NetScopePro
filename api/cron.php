<?php
/**
 * Background Scan Runner (Cron Script)
 * This script should be called periodically (e.g., every 5-10 minutes)
 * by Windows Task Scheduler or Linux Cron.
 */

// Define absolute path for includes to work correctly from CLI
$base_dir = __DIR__ . '/../';
require_once $base_dir . 'includes/config.php';
require_once $base_dir . 'includes/db.php';
require_once $base_dir . 'includes/network.php';
require_once $base_dir . 'includes/snmp.php';


echo "[" . date('Y-m-d H:i:s') . "] Starting Automated Scan...\n";

$db = get_db_connection();
$offline_ttl_minutes = max(5, (int)OFFLINE_TTL_MINUTES);

// Find subnets that are due for scanning
// Logic: last_scan is NULL OR (NOW - last_scan >= interval)
$query = "
    SELECT * FROM subnets 
    WHERE scan_interval > 0 
    AND (
        last_scan IS NULL 
        OR TIMESTAMPDIFF(MINUTE, last_scan, CURRENT_TIMESTAMP) >= scan_interval
    )
";

$subnets = $db->query($query)->fetchAll();

if (empty($subnets)) {
    echo "No subnets due for scanning.\n";
    exit;
}

foreach ($subnets as $subnet) {
    echo "Scanning Subnet: {$subnet['subnet']}/{$subnet['mask']} (ID: {$subnet['id']})... ";
    
    $subnet_id = $subnet['id'];
    list($start_long, $end_long) = cidr_to_range($subnet['subnet'] . '/' . $subnet['mask']);
    
    // Pre-fetch ARP table for efficiency
    exec("arp -a", $arp_cache);
    $arp_map = parse_arp_table($arp_cache);
    
    $scanned = 0;
    $found = 0;
    
    // Scan limit 64 for performance
    $limit = 64;
    for ($i = $start_long; $i <= min($end_long, $start_long + $limit - 1); $i++) {
        if (!is_usable_host_long($i, $start_long, $end_long, (int)$subnet['mask'])) {
            continue;
        }

        $ip = long2ip($i);
        $ip = normalize_ipv4($ip);
        if (!$ip) {
            continue;
        }
        $scanned++;
        
        $signals = detect_host_signals($ip, $arp_map);
        $has_ping = $signals['ping'];
        $has_arp = $signals['arp'];
        $has_port = $signals['port'];
        $has_nmap = !empty($signals['nmap']);
        if ($signals['active']) {
            $found++;
            $hostname = resolve_hostname($ip);
            $has_dns = $hostname !== '';
            
            $mac = $arp_map[$ip] ?? null;
            if (!$mac) {
                $arp_map = refresh_arp_map();
                $mac = $arp_map[$ip] ?? null;
            }
            if (!$mac) {
                $mac = get_mac_from_arp($ip, $arp_cache);
            }
            $mac = normalize_mac($mac);
            $vendor = get_vendor_by_mac($mac);
            
            // SNMP Discovery (Optional)
            $description = '';
            $has_snmp = false;
            $snmp_info = SNMPHelper::getInfo($ip, $subnet['snmp_community'] ?? 'public', $subnet['snmp_version'] ?? '2c');
            if ($snmp_info) {
                $has_snmp = true;
                if (!empty($snmp_info['name'])) {
                    $snmp_hostname = normalize_hostname($snmp_info['name']);
                    if ($snmp_hostname !== '') {
                        $hostname = $snmp_hostname;
                    }
                }
                if (!empty($snmp_info['description'])) $description = $snmp_info['description'];
            }
            $confidence = calculate_discovery_confidence([
                'ping' => $has_ping,
                'arp' => $has_arp,
                'nmap' => $has_nmap,
                'port' => $has_port,
                'dns' => $has_dns,
                'snmp' => $has_snmp
            ]);
            
            $stmt = $db->prepare("
                INSERT INTO ip_addresses (subnet_id, ip_addr, hostname, mac_addr, vendor, description, state, last_seen, confidence_score, data_sources) 
                VALUES (?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    hostname = IF(VALUES(hostname) != '', VALUES(hostname), hostname),
                    mac_addr = IF(VALUES(mac_addr) != '', VALUES(mac_addr), mac_addr),
                    vendor = IF(VALUES(vendor) != '', VALUES(vendor), vendor),
                    description = IF(VALUES(description) != '', VALUES(description), description),
                    confidence_score = VALUES(confidence_score),
                    data_sources = VALUES(data_sources),
                    state = 'active', 
                    last_seen = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$subnet_id, $ip, $hostname, $mac, $vendor, $description, $confidence['score'], $confidence['sources']]);
        }
    }
    
    // Update last_scan time
    $stmt = $db->prepare("UPDATE subnets SET last_scan = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$subnet_id]);
    
    echo "Done! (Scanned: $scanned, Found: $found)\n";
}

// Mark stale active hosts as offline when last_seen is older than TTL.
$stmt = $db->prepare("
    UPDATE ip_addresses
    SET state = 'offline'
    WHERE state = 'active'
      AND last_seen IS NOT NULL
      AND TIMESTAMPDIFF(MINUTE, last_seen, CURRENT_TIMESTAMP) >= ?
");
$stmt->execute([$offline_ttl_minutes]);
echo "Offline reconciliation applied (TTL: {$offline_ttl_minutes} min).\n";

echo "Automated Scan Finished.\n";
