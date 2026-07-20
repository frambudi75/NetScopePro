<?php
/**
 * IPManager Pro - Parallel Scanner Worker
 * This script is executed in the background to scan specific IP chunks.
 * Supports both legacy (ARP/ping) and masscan-first discovery modes.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run via CLI.");
}

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/network.php';

// Arguments: php scanner_worker.php [subnet_id] [start_ip_long] [end_ip_long]
$subnet_id = (int)($argv[1] ?? 0);
$start_long = (int)($argv[2] ?? 0);
$end_long = (int)($argv[3] ?? 0);

if (!$subnet_id || !$start_long || !$end_long) {
    die("Invalid arguments.\n");
}

$db = get_db_connection();

// Fetch subnet info
$stmt = $db->prepare("SELECT * FROM subnets WHERE id = ?");
$stmt->execute([$subnet_id]);
$subnet = $stmt->fetch();
if (!$subnet) die("Subnet not found.\n");

// ====== DECIDE DISCOVERY METHOD ======
$use_masscan = defined('ENABLE_MASSCAN') && ENABLE_MASSCAN && has_masscan_binary();
$masscan_hosts = [];

if ($use_masscan) {
    // Masscan mode: discover active hosts first, then enrich only those
    echo "Worker [$start_long-$end_long]: Using MASSCAN discovery (rate=" . MASSCAN_RATE . ")\n";
    $masscan_hosts = masscan_discover_hosts($start_long, $end_long, MASSCAN_RATE);
    $arp_cache = refresh_arp_map();
} else {
    // Legacy mode: standard ARP/ping per-host scan
    $arp_cache = refresh_arp_map();
}

for ($i = $start_long; $i <= $end_long; $i++) {
    $ip = long2ip($i);

    // Refresh ARP cache periodically (every 64 IPs) to stay current
    if (($i - $start_long) % 64 === 0) {
        $fresh = refresh_arp_map();
        if (!empty($fresh)) {
            $arp_cache = array_merge($arp_cache, $fresh);
        }
    }

    if ($use_masscan) {
        // MASSCAN MODE: Skip IPs not found by masscan
        if (!isset($masscan_hosts[$ip])) {
            // Check if previously active — increment fail_count
            $stmt = $db->prepare("SELECT id, fail_count FROM ip_addresses WHERE subnet_id = ? AND ip_addr = ? AND state = 'active'");
            $stmt->execute([$subnet_id, $ip]);
            $existing = $stmt->fetch();

            if ($existing) {
                $new_fail = (int)$existing['fail_count'] + 1;
                $threshold = (int)Settings::get('offline_fail_threshold', 3);
                if ($new_fail >= $threshold) {
                    $db->prepare("UPDATE ip_addresses SET state = 'offline', fail_count = ? WHERE id = ?")
                       ->execute([$new_fail, $existing['id']]);
                    echo "IP $ip marked OFFLINE (Fail: $new_fail)\n";
                } else {
                    $db->prepare("UPDATE ip_addresses SET fail_count = ? WHERE id = ?")
                       ->execute([$new_fail, $existing['id']]);
                }
            }
            continue;
        }

        // Found by masscan — quick ping for ARP enrichment
        $signals = ['ping' => false, 'arp' => false, 'port' => true, 'nmap' => false, 'active' => true, 'masscan' => true];
        if (ping_ip($ip, 1, 200)) {
            $signals['ping'] = true;
            $fresh = refresh_arp_map();
            if (!empty($fresh)) $arp_cache = array_merge($arp_cache, $fresh);
            $signals['arp'] = isset($arp_cache[$ip]);
        }
        $signals['mac'] = $arp_cache[$ip] ?? null;
    } else {
        // LEGACY MODE: Standard multi-probe detection
        $signals = detect_host_signals($ip, $arp_cache);
    }

    // Fetch existing record
    $stmt = $db->prepare("SELECT * FROM ip_addresses WHERE subnet_id = ? AND ip_addr = ?");
    $stmt->execute([$subnet_id, $ip]);
    $existing = $stmt->fetch();

    if ($signals['active']) {
        $new_mac = $signals['mac'] ?? ($arp_cache[$ip] ?? null);
        $vendor = $new_mac ? get_vendor_by_mac($new_mac) : 'Unknown';

        $confidence_data = calculate_discovery_confidence([
            'ping' => $signals['ping'] ?? false,
            'arp'  => $signals['arp'] ?? false,
            'port' => $signals['port'] ?? false,
            'nmap' => $signals['nmap'] ?? false,
            'masscan' => $signals['masscan'] ?? false
        ]);

        $conflict_detected = 0;
        if ($existing && !empty($existing['mac_addr']) && !empty($new_mac)) {
            if (strtolower($existing['mac_addr']) !== strtolower($new_mac)) {
                $conflict_detected = 1;
                NotificationHelper::notifyConflict($ip, $existing['mac_addr'], $new_mac, $subnet['subnet'] . '/' . $subnet['mask']);
            }
        }

        // OS Fingerprinting (only if nmap is enabled and os not yet known)
        $os_detected = null;
        if (defined('ENABLE_NMAP_FALLBACK') && ENABLE_NMAP_FALLBACK && has_nmap_binary()) {
            $skip_os = ($existing && !empty($existing['os']) && $existing['os'] !== 'Unknown');
            if (!$skip_os) {
                $os_detected = nmap_fingerprint_os($ip);
                if ($os_detected && $os_detected !== 'Unknown') {
                    echo "OS detected for $ip: $os_detected\n";
                }
            } else {
                $os_detected = $existing['os']; // Keep existing
            }
        }

        // Update DB: Mark ACTIVE, Reset fail_count
        $stmt = $db->prepare("
            INSERT INTO ip_addresses (subnet_id, ip_addr, mac_addr, vendor, os, state, confidence_score, data_sources, conflict_detected, fail_count, last_seen)
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, 0, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                mac_addr = IF(VALUES(mac_addr) IS NOT NULL, VALUES(mac_addr), mac_addr),
                vendor = IF(VALUES(vendor) IS NOT NULL, VALUES(vendor), vendor),
                os = IF(VALUES(os) IS NOT NULL AND VALUES(os) != 'Unknown', VALUES(os), os),
                state = 'active',
                confidence_score = VALUES(confidence_score),
                data_sources = VALUES(data_sources),
                conflict_detected = VALUES(conflict_detected),
                fail_count = 0,
                last_seen = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $subnet_id,
            $ip,
            $new_mac,
            $vendor,
            $os_detected,
            $confidence_data['score'],
            $confidence_data['sources'],
            $conflict_detected
        ]);

        if (!$existing) {
            NotificationHelper::notifyNewDevice($ip, $new_mac, $vendor, $subnet['subnet'] . '/' . $subnet['mask']);
        }
    } else {
        // If not detected by regular scan, but was previously active
        if ($existing && $existing['state'] === 'active') {
            // Run Intensive Verification
            $intensive = intensive_detect_host($ip, $arp_cache);

            if ($intensive['active']) {
                // False negative fixed by intensive scan! Update last_seen and reset fail_count
                $db->prepare("UPDATE ip_addresses SET last_seen = CURRENT_TIMESTAMP, fail_count = 0, data_sources = CONCAT(data_sources, ',intensive') WHERE id = ?")
                   ->execute([$existing['id']]);
            } else {
                // Truly not responding. Increment fail count.
                $new_fail_count = (int)$existing['fail_count'] + 1;
                $threshold = (int)Settings::get('offline_fail_threshold', 3);

                if ($new_fail_count >= $threshold) {
                    // Mark OFFLINE only after threshold reached
                    $db->prepare("UPDATE ip_addresses SET state = 'offline', fail_count = ? WHERE id = ?")
                       ->execute([$new_fail_count, $existing['id']]);
                    echo "IP $ip marked OFFLINE (Fail count: $new_fail_count)\n";
                } else {
                    // Keep ACTIVE but increment fail_count
                    $db->prepare("UPDATE ip_addresses SET fail_count = ? WHERE id = ?")
                       ->execute([$new_fail_count, $existing['id']]);
                    echo "IP $ip missed scan. Fail count: $new_fail_count / $threshold\n";
                }
            }
        }
    }
}
echo "Worker finished chunk $start_long - $end_long\n";
