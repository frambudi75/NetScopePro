<?php
/**
 * Netwatch Background Scanner
 * Runs as a cron job to check host availability
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/notifications.php';

$is_cli = (php_sapi_name() === 'cli');

// Security: Allow CLI, session-based admin auth, or secret key
if (!$is_cli) {
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
        $temp_status = 'up';
        $new_fail_count = 0;
        $update_fields[] = "last_up = NOW()";
    } else {
        $new_fail_count++;
        if ($new_fail_count >= $threshold) {
            $temp_status = 'down';
            // Only update last_down if it just went down
            if ($current_status !== 'down') {
                $update_fields[] = "last_down = NOW()";
            }
        } else {
            $temp_status = 'unknown'; 
        }
    }

    // Intermittent Check based on history
    $new_status = $temp_status;
    try {
        $hist_stmt = $db->prepare("SELECT status FROM netwatch_history WHERE netwatch_id = ? ORDER BY id DESC LIMIT 9");
        $hist_stmt->execute([$id]);
        $history = $hist_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $hist_up = ($temp_status === 'up') ? 1 : 0;
        $hist_down = ($temp_status === 'down' || $temp_status === 'unknown') ? 1 : 0;
        
        foreach ($history as $h_status) {
            if ($h_status === 'up') $hist_up++;
            if ($h_status === 'down' || $h_status === 'unknown') $hist_down++;
        }
        
        // If it's fluctuating (at least 2 up and 2 down in the last 10 checks)
        if ($hist_up >= 2 && $hist_down >= 2) {
            $new_status = 'intermittent';
        }
    } catch (Exception $e) { /* silent on history fetch error */ }

    // State Change & Notifications Logic
    if ($new_status !== $current_status && $new_status !== 'unknown') {
        if ($new_status === 'up') {
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
        } else if ($new_status === 'down') {
            log_netwatch_change($id, $host, 'down');
            send_netwatch_notification($t, 'down', '', $latency);
        } else if ($new_status === 'intermittent') {
            log_netwatch_change($id, $host, 'intermittent');
            send_netwatch_notification($t, 'intermittent', '', $latency);
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
