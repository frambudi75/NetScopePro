<?php
/**
 * IPManager Pro - Background Scanner Service
 * Run this script via Windows Task Scheduler or Cron
 * Example: php.exe c:\xampp\htdocs\ipmanage\cron_scanner.php
 */

// Disable frontend dependencies
define('IS_CRON', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/network.php';
require_once __DIR__ . '/includes/snmp.php';
require_once __DIR__ . '/includes/notifications.php';

// Security: Allow CLI, session-based admin auth, or secret key
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $key = $_GET['key'] ?? '';
    $secret = Settings::get('cron_key', 'your-secret-key-change-me');
    
    if (!isset($_SESSION['user_id']) || !is_admin()) {
        if ($key !== $secret) {
            header('HTTP/1.1 403 Forbidden');
            die("Unauthorized. Run via CLI, log in as admin, or provide a valid key.");
        }
    }
}

$db = get_db_connection();

echo "[ " . date('Y-m-d H:i:s') . " ] Background Scan Started\n";

// Find subnets that need scanning (limit to 3 subnets per run to avoid timeout)
$stmt = $db->query("
    SELECT * FROM subnets 
    WHERE scan_interval > 0 
    AND (last_scan IS NULL OR last_scan < DATE_SUB(NOW(), INTERVAL scan_interval MINUTE))
    ORDER BY last_scan ASC 
    LIMIT 3
");

$subnets = $stmt->fetchAll();
if (!empty($subnets)) {
    foreach ($subnets as $subnet) {
        $start_time = microtime(true);
        echo "Starting Parallel Scan for Subnet: " . $subnet['subnet'] . "/" . $subnet['mask'] . " (ID: " . $subnet['id'] . ")\n";
        
        list($start_long, $end_long) = cidr_to_range($subnet['subnet'] . '/' . $subnet['mask']);
        
        // Performance limit for cron: scan at most 256 IPs per subnet run to avoid congestion
        $scan_end = min($end_long, $start_long + 255);
        
        $chunk_size = 16; 
        $max_workers = 10;
        $current_batch = [];
        
        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        for ($i = $start_long; $i <= $scan_end; $i += $chunk_size) {
            $chunk_end = min($i + $chunk_size - 1, $scan_end);
            
            // Render cross-platform command
            if ($is_windows) {
                $cmd = "start /B php scanner_worker.php " . (int)$subnet['id'] . " " . (int)$i . " " . (int)$chunk_end;
            } else {
                $cmd = "php scanner_worker.php " . (int)$subnet['id'] . " " . (int)$i . " " . (int)$chunk_end . " > /dev/null 2>&1 &";
            }
            
            pclose(popen($cmd, "r"));
            $current_batch[] = $i;
            
            if (count($current_batch) >= $max_workers) {
                echo "Waiting for worker batch batch...\n";
                usleep(2500000); // 2.5s delay between batches
                $current_batch = [];
            }
        }
        
        // Wait for final batch
        usleep(4000000);

        // After all workers, perform capacity alert check

        // Update last_scan timestamp
        $db->prepare("UPDATE subnets SET last_scan = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$subnet['id']]);

        // Perform capacity alert check
        $stmt = $db->prepare("SELECT COUNT(*) FROM ip_addresses WHERE subnet_id = ? AND state = 'active'");
        $stmt->execute([$subnet['id']]);
        $active_count = $stmt->fetchColumn();
        
        // Utilization threshold: Subnet override OR global setting
        $threshold = (int)($subnet['utilization_threshold'] ?? Settings::get('subnet_limit_threshold', 80));
        $capacity = pow(2, (32 - (int)$subnet['mask']));
        if ((int)$subnet['mask'] < 31) $capacity -= 2;
        
        $usage_percent = ($active_count / max(1, $capacity)) * 100;
        
        if ($usage_percent >= $threshold) {
            $last_alert = $subnet['last_limit_alert'] ? strtotime($subnet['last_limit_alert']) : 0;
            // Alert once every 24 hours
            if (time() - $last_alert > 86400) {
                NotificationHelper::notifySubnetFull($subnet['subnet'], $subnet['mask'], round($usage_percent, 1), $active_count, $capacity);
                $db->prepare("UPDATE subnets SET last_limit_alert = CURRENT_TIMESTAMP WHERE id = ?")->execute([$subnet['id']]);
            }
        }
           
        $duration = round(microtime(true) - $start_time, 2);
        echo "Finished. Found $active_count active devices (Usage: " . round($usage_percent, 1) . "%). Time: {$duration}s\n";
    }
} else {
    echo "No subnets due for scanning.\n";
}

echo "[ " . date('Y-m-d H:i:s') . " ] All tasks completed.\n";

// Daily Trend Snapshot
$total_active = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state = 'active'")->fetchColumn();
$db->prepare("INSERT INTO stats_history (snapshot_date, total_active) VALUES (CURRENT_DATE, ?) ON DUPLICATE KEY UPDATE total_active = VALUES(total_active)")
   ->execute([$total_active]);

// Auto Database Cleanup (runs once per day if enabled)
try {
    $auto_cleanup = Settings::get('retention_auto_cleanup', '1');
    $last_cleanup = (int)Settings::get('last_db_cleanup', 0);
    $cleanup_interval = 86400; // 24 hours

    if ($auto_cleanup === '1' && (time() - $last_cleanup) >= $cleanup_interval) {
        echo "Running daily database cleanup...\n";
        include __DIR__ . '/cron_cleanup.php';
    }
} catch (Exception $e) {
    echo "Cleanup error: " . $e->getMessage() . "\n";
}
?>
