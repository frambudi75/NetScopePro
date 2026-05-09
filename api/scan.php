<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/network.php';
require_once '../includes/snmp.php';
require_once '../includes/notifications.php';


session_start();

if (!isset($_SESSION['user_id'])) {
    json_response(['error' => 'Unauthorized'], 401);
}

$subnet_id = $_GET['id'] ?? 0;
if (!$subnet_id) {
    json_response(['error' => 'Invalid Subnet ID'], 400);
}

$db = get_db_connection();

// Fetch subnet info
$stmt = $db->prepare("SELECT * FROM subnets WHERE id = ?");
$stmt->execute([$subnet_id]);
$subnet = $stmt->fetch();

if (!$subnet) {
    json_response(['error' => 'Subnet not found'], 404);
}

// Increase execution time for scanning
set_time_limit(300);

// Calculate full subnet range
list($start_long, $end_long) = cidr_to_range($subnet['subnet'] . '/' . $subnet['mask']);

// Support chunked scanning via 'block' parameter from UI
// Increased to 64 since optimized detection is much faster
$block_size = 64;
$current_block = isset($_GET['block']) ? (int)$_GET['block'] : 0;

// If start/end provided, use them. Otherwise calculate based on block.
$range_start = isset($_GET['start']) ? (int)$_GET['start'] : ($start_long + ($current_block * $block_size));
$range_end = isset($_GET['end']) ? (int)$_GET['end'] : min($end_long, $range_start + $block_size - 1);

$results = [
    'scanned' => 0,
    'found' => 0,
    'ips' => [],
    'offline_ips' => []
];

try {
    // ====== PHASE 1: Batch ARP Pre-seeding ======
    // Fire concurrent pings to fill ARP cache BEFORE scanning.
    // This is the biggest single performance improvement.
    $arp_map = preseed_arp_batch($range_start, $range_end, 200);

    for ($i = $range_start; $i <= $range_end; $i++) {
        if (!is_usable_host_long($i, $start_long, $end_long, (int)$subnet['mask'])) {
            continue;
        }

        $ip = long2ip($i);
        $results['scanned']++;
        
        // ====== PHASE 2: Fast host detection (optimized for web UI) ======
        $signals = fast_detect_host_signals($ip, $arp_map);
        $is_active = $signals['active'];

        if ($is_active) {
            $results['found']++;
            $hostname = '';
            $description = '';
            $mac = $arp_map[$ip] ?? null;
            $vendor = get_vendor_by_mac($mac);
            $os = 'Unknown';
            $has_snmp = false;

            // ====== PHASE 3: Lazy enrichment (only for active hosts) ======
            
            // Hostname resolution (single attempt, fast timeout)
            $hostname = resolve_hostname($ip);
            
            // SNMP discovery - only attempt if host responded to ping
            if ($signals['ping'] || $signals['arp']) {
                $snmp_info = SNMPHelper::getInfo($ip, $subnet['snmp_community'] ?? 'public', $subnet['snmp_version'] ?? '2c');
                if ($snmp_info) {
                    $has_snmp = true;
                    if (!empty($snmp_info['name'])) {
                        $snmp_hostname = normalize_hostname($snmp_info['name']);
                        if ($snmp_hostname !== '') $hostname = $snmp_hostname;
                    }
                    if (!empty($snmp_info['description'])) $description = $snmp_info['description'];
                }
            }

            // OS Fingerprinting (Respect global settings)
            if (Settings::get('nmap_enabled') == '1') {
                $os = nmap_fingerprint_os($ip);
            }

            $confidence = calculate_discovery_confidence([
                'ping' => $signals['ping'],
                'arp' => $signals['arp'],
                'port' => $signals['port'],
                'dns' => ($hostname !== ''),
                'snmp' => $has_snmp
            ]);

            // Database Persistence
            $stmt = $db->prepare("SELECT state, mac_addr FROM ip_addresses WHERE subnet_id = ? AND ip_addr = ?");
            $stmt->execute([$subnet_id, $ip]);
            $current_data = $stmt->fetch();

            $conflict_detected = 0;
            if (!$current_data) {
                try { NotificationHelper::notifyNewDevice($ip, $mac, $vendor, $hostname, $subnet['subnet']); } catch (Exception $e) {}
            } elseif ($current_data['mac_addr'] && $mac && $current_data['mac_addr'] !== $mac) {
                $conflict_detected = 1;
                try { NotificationHelper::notifyConflict($ip, $current_data['mac_addr'], $mac, $subnet['subnet']); } catch (Exception $e) {}
            }

            $stmt = $db->prepare("
                INSERT INTO ip_addresses (subnet_id, ip_addr, hostname, mac_addr, vendor, os, conflict_detected, description, state, last_seen, confidence_score, data_sources) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    hostname = IF(VALUES(hostname) != '', VALUES(hostname), hostname),
                    mac_addr = IF(VALUES(mac_addr) != '', VALUES(mac_addr), mac_addr),
                    vendor = IF(VALUES(vendor) != '', VALUES(vendor), vendor),
                    os = IF(VALUES(os) != '', VALUES(os), os),
                    conflict_detected = VALUES(conflict_detected),
                    description = IF(VALUES(description) != '', VALUES(description), description),
                    confidence_score = VALUES(confidence_score),
                    data_sources = VALUES(data_sources),
                    state = 'active', 
                    last_seen = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$subnet_id, $ip, $hostname, $mac, $vendor, $os, $conflict_detected, $description, $confidence['score'], $confidence['sources']]);
            
            $results['ips'][] = ['ip' => $ip, 'state' => 'active', 'hostname' => $hostname, 'mac' => $mac];
        } else {
            // Mark as offline if it was previously active
            $stmt = $db->prepare("UPDATE ip_addresses SET state = 'offline' WHERE subnet_id = ? AND ip_addr = ? AND state = 'active'");
            $stmt->execute([$subnet_id, $ip]);
            if ($stmt->rowCount() > 0) $results['offline_ips'][] = $ip;
        }
    }

    // Final mark of subnet scan time
    $db->prepare("UPDATE subnets SET last_scan = CURRENT_TIMESTAMP WHERE id = ?")->execute([$subnet_id]);

} catch (Exception $e) {
    // If something fatal happens, we still return the partial results
    $results['error_occurred'] = $e->getMessage();
}

json_response([
    'success' => true, 
    'message' => "Scanning complete. Scanned {$results['scanned']} IPs, found {$results['found']} active hosts.",
    'data' => $results
]);
