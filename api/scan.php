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
$block_size = 64;
$current_block = isset($_GET['block']) ? (int)$_GET['block'] : 0;

// If start/end provided, use them. Otherwise calculate based on block.
$range_start = isset($_GET['start']) ? (int)$_GET['start'] : ($start_long + ($current_block * $block_size));
$range_end = isset($_GET['end']) ? (int)$_GET['end'] : min($end_long, $range_start + $block_size - 1);

$results = [
    'scanned' => 0,
    'found' => 0,
    'ips' => [],
    'offline_ips' => [],
    'discovery_method' => 'legacy' // or 'masscan'
];

try {
    // ====== DECIDE DISCOVERY METHOD ======
    $masscan_hosts = [];
    $use_masscan = defined('ENABLE_MASSCAN') && ENABLE_MASSCAN && has_masscan_binary();

    if ($use_masscan) {
        // MASSCAN PATH: Ultra-fast stateless SYN scan first
        $masscan_hosts = masscan_discover_hosts($range_start, $range_end, MASSCAN_RATE);
        $results['discovery_method'] = 'masscan';
        $results['masscan_discovered'] = count($masscan_hosts);

        // Still seed ARP cache for MAC enrichment (lightweight)
        $arp_map = refresh_arp_map();
    } else {
        // LEGACY PATH: Batch ARP Pre-seeding (slower)
        $arp_map = preseed_arp_batch($range_start, $range_end, 200);
    }

    // ====== PHASE 2: Process each IP ======
    for ($i = $range_start; $i <= $range_end; $i++) {
        if (!is_usable_host_long($i, $start_long, $end_long, (int)$subnet['mask'])) {
            continue;
        }

        $ip = long2ip($i);
        $results['scanned']++;

        if ($use_masscan) {
            // MASSCAN MODE: Only enrich hosts found by masscan
            if (!isset($masscan_hosts[$ip])) {
                // Not found by masscan — increment fail_count (same threshold as cron)
                $stmt = $db->prepare("UPDATE ip_addresses SET fail_count = fail_count + 1 WHERE subnet_id = ? AND ip_addr = ? AND state = 'active'");
                $stmt->execute([$subnet_id, $ip]);

                $stmt = $db->prepare("SELECT fail_count FROM ip_addresses WHERE subnet_id = ? AND ip_addr = ? AND state = 'active'");
                $stmt->execute([$subnet_id, $ip]);
                $fail_row = $stmt->fetch();

                if ($fail_row && (int)$fail_row['fail_count'] >= (int)Settings::get('offline_fail_threshold', 3)) {
                    $db->prepare("UPDATE ip_addresses SET state = 'offline', fail_count = ? WHERE subnet_id = ? AND ip_addr = ?")
                       ->execute([$fail_row['fail_count'], $subnet_id, $ip]);
                    $results['offline_ips'][] = $ip;
                }
                continue;
            }

            // Host found by masscan — enrich it
            $is_active = true;
            $signals = [
                'ping' => false,
                'arp' => false,
                'port' => true,
                'masscan' => true
            ];
        } else {
            // LEGACY MODE: Fast host detection
            $signals = fast_detect_host_signals($ip, $arp_map);
            $is_active = $signals['active'];
        }

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

            // Quick ping to confirm reachability + populate ARP
            if ($use_masscan && !$mac) {
                if (ping_ip($ip, 1, 200)) {
                    $signals['ping'] = true;
                    $fresh_arp = refresh_arp_map();
                    if (!empty($fresh_arp)) {
                        $arp_map = array_merge($arp_map, $fresh_arp);
                        $mac = $arp_map[$ip] ?? null;
                        if ($mac) $vendor = get_vendor_by_mac($mac);
                        $signals['arp'] = true;
                    }
                }
            }

            // SNMP discovery - attempt if host responded or masscan found it
            if ($signals['ping'] || $signals['arp'] || $use_masscan) {
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

            // OS Fingerprinting (Respect global settings — nmap only for confirmed active)
            if (Settings::get('nmap_enabled') == '1') {
                $os = nmap_fingerprint_os($ip);
            }

            $confidence = calculate_discovery_confidence([
                'ping' => $signals['ping'] ?? false,
                'arp' => $signals['arp'] ?? false,
                'port' => $signals['port'] ?? false,
                'masscan' => $signals['masscan'] ?? false,
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
                    last_seen = CURRENT_TIMESTAMP,
                    fail_count = 0
            ");
            $stmt->execute([$subnet_id, $ip, $hostname, $mac, $vendor, $os, $conflict_detected, $description, $confidence['score'], $confidence['sources']]);
            
            $results['ips'][] = ['ip' => $ip, 'state' => 'active', 'hostname' => $hostname, 'mac' => $mac];
        } else {
            // LEGACY MODE offline handling: use fail_count threshold (not immediate offline)
            $stmt = $db->prepare("SELECT fail_count FROM ip_addresses WHERE subnet_id = ? AND ip_addr = ? AND state = 'active'");
            $stmt->execute([$subnet_id, $ip]);
            $active_row = $stmt->fetch();

            if ($active_row) {
                $new_fail = (int)$active_row['fail_count'] + 1;
                $threshold = (int)Settings::get('offline_fail_threshold', 3);

                if ($new_fail >= $threshold) {
                    $db->prepare("UPDATE ip_addresses SET state = 'offline', fail_count = ? WHERE subnet_id = ? AND ip_addr = ?")
                       ->execute([$new_fail, $subnet_id, $ip]);
                    $results['offline_ips'][] = $ip;
                } else {
                    $db->prepare("UPDATE ip_addresses SET fail_count = ? WHERE subnet_id = ? AND ip_addr = ?")
                       ->execute([$new_fail, $subnet_id, $ip]);
                }
            }
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
