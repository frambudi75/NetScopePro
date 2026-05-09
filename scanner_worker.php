<?php
/**
 * IPManager Pro - Parallel Scanner Worker
 * This script is executed in the background to scan specific IP chunks.
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


for ($i = $start_long; $i <= $end_long; $i++) {
    $ip = long2ip($i);
    
    // Perform discovery with ARP caching for efficiency
    static $arp_cache = null;
    if ($arp_cache === null) {
        $arp_cache = refresh_arp_map();
    }

    $signals = detect_host_signals($ip, $arp_cache);
    
    // Fetch existing record
    $stmt = $db->prepare("SELECT * FROM ip_addresses WHERE subnet_id = ? AND ip_addr = ?");
    $stmt->execute([$subnet_id, $ip]);
    $existing = $stmt->fetch();

    if ($signals['active']) {
        $new_mac = $signals['mac'] ?? null;
        $conflict_detected = 0;
        
        if ($existing && !empty($existing['mac_addr']) && !empty($new_mac)) {
            if (strtolower($existing['mac_addr']) !== strtolower($new_mac)) {
                $conflict_detected = 1;
                NotificationHelper::notifyConflict($ip, $existing['mac_addr'], $new_mac, $subnet['subnet'] . '/' . $subnet['mask']);
            }
        }
        
        // Update DB: Mark ACTIVE, Reset fail_count
        $stmt = $db->prepare("
            INSERT INTO ip_addresses (subnet_id, ip_addr, mac_addr, vendor, os, state, confidence_score, data_sources, conflict_detected, fail_count, last_seen) 
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, 0, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE 
                mac_addr = IF(VALUES(mac_addr) IS NOT NULL, VALUES(mac_addr), mac_addr),
                vendor = IF(VALUES(vendor) IS NOT NULL, VALUES(vendor), vendor),
                os = IF(VALUES(os) IS NOT NULL, VALUES(os), os),
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
            $signals['vendor'] ?? 'Unknown', 
            $signals['os'] ?? null,
            $signals['confidence'] ?? 0,
            implode(',', $signals['sources'] ?? []),
            $conflict_detected
        ]);
        
        if (!$existing) {
            NotificationHelper::notifyNewDevice($ip, $new_mac, $signals['vendor'] ?? 'Unknown', $subnet['subnet'] . '/' . $subnet['mask']);
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
