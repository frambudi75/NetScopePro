<?php
/**
 * Netwatch Background Scanner
 * Runs as a cron job to check host availability
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/notifications.php';

// Set execution time limit to 5 minutes to allow for multiple pings
set_time_limit(300);

$db = get_db_connection();

// Fetch all targets that need checking based on interval
$targets = $db->query("SELECT * FROM netwatch WHERE last_check IS NULL OR TIMESTAMPDIFF(SECOND, last_check, NOW()) >= ping_interval")->fetchAll();

echo "Starting Netwatch Scan at " . date('Y-m-d H:i:s') . "\n";

foreach ($targets as $t) {
    $host = trim($t['host']);
    $id = $t['id'];
    $current_status = $t['status'];
    $fail_count = (int)$t['fail_count'];
    $threshold = (int)$t['fail_threshold'];

    // Basic Ping check
    $is_up = false;
    $output = [];
    $result = -1;
    
    // Windows-specific ping (XAMPP usually on Windows)
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec("ping -n 1 -w 1000 " . escapeshellarg($host) . " 2>&1", $output, $result);
    } else {
        exec("ping -c 1 -W 1 " . escapeshellarg($host) . " 2>&1", $output, $result);
    }

    if ($result === 0) {
        // Double check output for "Reply from" or "64 bytes from" to be sure
        $output_str = implode("\n", $output);
        if (strpos($output_str, 'Reply from') !== false || strpos($output_str, 'bytes from') !== false) {
            $is_up = true;
        }
    }

    $output_str = implode("\n", $output);
    $latency = 0;
    if (preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $output_str, $matches)) {
        $latency = (float)$matches[1];
    }

    $new_status = $current_status;
    $new_fail_count = $fail_count;
    $update_fields = [];
    $update_fields[] = "last_check = NOW()";

    if ($is_up) {
        $new_status = 'up';
        $new_fail_count = 0;
        $update_fields[] = "last_up = NOW()";
        
            // If it was down before, log it and notify recovery
            if ($current_status === 'down') {
                $duration_text = '';
                if (!empty($t['last_down'])) {
                    $down_time = strtotime($t['last_down']);
                    $up_time = time();
                    $diff = $up_time - $down_time;
                    
                    $h = floor($diff / 3600);
                    $m = floor(($diff % 3600) / 60);
                    $s = $diff % 60;
                    
                    $duration_text = ($h > 0 ? "{$h}h " : "") . ($m > 0 ? "{$m}m " : "") . "{$s}s";
                }

                log_netwatch_change($id, $host, 'up');
                send_netwatch_notification($t, 'up', $duration_text, $latency);
            }
    } else {
        $new_fail_count++;
        if ($new_fail_count >= $threshold) {
            $new_status = 'down';
            
            // If it was up or unknown before, log it (State Change DOWN)
            if ($current_status !== 'down') {
                $update_fields[] = "last_down = NOW()";
                log_netwatch_change($id, $host, 'down');
                send_netwatch_notification($t, 'down', '', $latency);
            }
        } else {
            // Even if it hasn't hit threshold, we show it's failing in the count
            $new_status = 'unknown'; 
        }
    }

    $update_fields[] = "status = '$new_status'";
    $update_fields[] = "fail_count = $new_fail_count";

    $sql = "UPDATE netwatch SET " . implode(', ', $update_fields) . " WHERE id = $id";
    $db->exec($sql);

    // Recording History
    try {
        $stmt = $db->prepare("INSERT INTO netwatch_history (netwatch_id, latency, status) VALUES (?, ?, ?)");
        $stmt->execute([$id, $latency, $new_status]);

        // Cleanup old history (> 1 week) 
        $db->exec("DELETE FROM netwatch_history WHERE recorded_at < NOW() - INTERVAL 7 DAY");
    } catch (Exception $e) { /* silent */ }

    echo "Target $host: ". strtoupper($new_status) . " ({$latency}ms) (Fails: $new_fail_count)\n";
}

/**
 * Helper to log status changes in audit_logs
 */
function log_netwatch_change($id, $host, $status) {
    global $db;
    try {
        $message = "Netwatch: Host $host is now " . strtoupper($status);
        $stmt = $db->prepare("INSERT INTO audit_logs (action, target_type, target_id, details) VALUES (?, 'netwatch', ?, ?)");
        $stmt->execute(['STATUS_CHANGE', $id, $message]);
    } catch (Exception $e) {
        // Silently fail
    }
}

/**
 * Helper to send notifications
 */
function send_netwatch_notification($target, $status, $duration = '', $latency = null) {
    if ($target['notify'] == 1) {
        // Check Maintenance Mode
        if (!empty($target['maintenance_until']) && strtotime($target['maintenance_until']) > time()) {
            return; // SNOOZED
        }
        NotificationHelper::notifyNetwatch($target['name'], $target['host'], $status, $duration, $latency);
    }
}
