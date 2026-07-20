<?php
/**
 * NetScope Pro - Database Maintenance & Cleanup
 * 
 * Automatically purges old data from time-series/history tables
 * to prevent database bloat and maintain performance.
 * 
 * Run via cron or Task Scheduler (recommended: once daily)
 * Example: 0 2 * * * php /path/to/cron_cleanup.php
 * 
 * Can also be triggered manually from Settings > Database tab.
 */

$base_dir = __DIR__ . '/';
require_once $base_dir . 'includes/config.php';
require_once $base_dir . 'includes/db.php';

$is_cli = (php_sapi_name() === 'cli');
$is_ajax = (!$is_cli && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');

// Auth check for web requests (both regular and AJAX)
if (!$is_cli && !defined('IS_CRON')) {
    session_start();
    if (!isset($_SESSION['user_id']) || !is_admin()) {
        if ($is_ajax) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        die('Unauthorized');
    }
}

$db = get_db_connection();
$results = [];
$total_deleted = 0;

// Get retention settings (days) from database
$retention = [
    'switch_port_history'    => (int)Settings::get('retention_port_history', 30),
    'switch_health_history'  => (int)Settings::get('retention_health_history', 30),
    'netwatch_history'       => (int)Settings::get('retention_netwatch_history', 30),
    'audit_logs'             => (int)Settings::get('retention_audit_logs', 90),
    'switch_port_latest_counters' => 0, // Never auto-delete, this is always current
];

$timestamp_columns = [
    'switch_port_history'    => 'recorded_at',
    'switch_health_history'  => 'recorded_at',
    'netwatch_history'       => 'recorded_at',
    'audit_logs'             => 'created_at',
];

$log = function($msg) use ($is_cli) {
    if ($is_cli) {
        echo $msg . "\n";
    }
};

$log("[" . date('Y-m-d H:i:s') . "] Starting Database Maintenance...");

// --- Custom Cleanup Rules ---

// Rule: Delete offline IP addresses older than a configured period
$offline_ip_retention_hours = (int)Settings::get('retention_offline_ips_hours', 1); // Default to 1 hour
if ($offline_ip_retention_hours > 0) {
    $log("  🗑️ Deleting offline IP addresses older than {$offline_ip_retention_hours} hours...");
    try {
        $target_timestamp = date('Y-m-d H:i:s', strtotime("-{$offline_ip_retention_hours} hours"));

        $stmt = $db->prepare("SELECT COUNT(*) FROM `ip_addresses` WHERE `state` = 'offline' AND `last_seen` < ?");
        $stmt->execute([$target_timestamp]);
        $to_delete = (int)$stmt->fetchColumn();

        if ($to_delete > 0) {
            $del_stmt = $db->prepare("DELETE FROM `ip_addresses` WHERE `state` = 'offline' AND `last_seen` < ?");
            $del_stmt->execute([$target_timestamp]);
            $deleted_count = $del_stmt->rowCount();
            $log("    ✅ Deleted $deleted_count offline IP addresses.");
            $total_deleted += $deleted_count;
        } else {
            $log("    ✨ No offline IP addresses to delete.");
        }
    } catch (Exception $e) {
        $log("    ❌ Error deleting offline IP addresses: " . $e->getMessage());
    }
} else {
    $log("  ⏭️ Deletion of offline IP addresses is disabled.");
}

foreach ($timestamp_columns as $table => $column) {
    $days = $retention[$table];
    
    if ($days <= 0) {
        $log("  ⏭  $table: retention disabled (keep forever)");
        $results[$table] = ['deleted' => 0, 'remaining' => 0, 'status' => 'skipped'];
        continue;
    }

    try {
        // Count before
        $count_before = (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        
        // Calculate target date limit in PHP
        $target_date = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        // Count how many will be deleted
        $stmt = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` < ?");
        $stmt->execute([$target_date]);
        $to_delete = (int)$stmt->fetchColumn();

        if ($to_delete > 0) {
            // Delete in batches to avoid locking
            $batch_size = 10000;
            $deleted_total = 0;
            
            do {
                $del_stmt = $db->prepare("DELETE FROM `$table` WHERE `$column` < ? LIMIT $batch_size");
                $del_stmt->execute([$target_date]);
                $deleted_batch = $del_stmt->rowCount();
                $deleted_total += $deleted_batch;
            } while ($deleted_batch >= $batch_size);

            $remaining = $count_before - $deleted_total;
            $log("  ✅ $table: deleted $deleted_total rows (retention: {$days}d, remaining: $remaining)");
            $results[$table] = ['deleted' => $deleted_total, 'remaining' => $remaining, 'status' => 'cleaned'];
            $total_deleted += $deleted_total;
        } else {
            $log("  ✨ $table: clean ($count_before rows, all within {$days}d retention)");
            $results[$table] = ['deleted' => 0, 'remaining' => $count_before, 'status' => 'clean'];
        }
    } catch (Exception $e) {
        $log("  ❌ $table: ERROR - " . $e->getMessage());
        $results[$table] = ['deleted' => 0, 'remaining' => 0, 'status' => 'error', 'error' => $e->getMessage()];
    }
}

// Optimize tables after cleanup (only if significant rows deleted)
if ($total_deleted > 1000) {
    $log("\n  🔧 Optimizing tables...");
    foreach (array_keys($timestamp_columns) as $table) {
        try {
            $db->exec("OPTIMIZE TABLE `$table`");
            $log("    OPTIMIZE $table ✓");
        } catch (Exception $e) {
            $log("    OPTIMIZE $table failed: " . $e->getMessage());
        }
    }
}

// Reset AUTO_INCREMENT on heavily cleaned tables to reclaim ID space
foreach ($timestamp_columns as $table => $column) {
    if (isset($results[$table]) && $results[$table]['deleted'] > 5000) {
        try {
            // Only reset if table is relatively small now
            $remaining = $results[$table]['remaining'];
            if ($remaining < 10000) {
                // Get max ID
                $max_id = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM `$table`")->fetchColumn();
                if ($max_id > 0) {
                    $new_auto = $max_id + 1;
                    $db->exec("ALTER TABLE `$table` AUTO_INCREMENT = $new_auto");
                    $log("  🔄 $table: reset AUTO_INCREMENT to $new_auto");
                }
            }
        } catch (Exception $e) {
            // Silently ignore ALTER errors
        }
    }
}

// Save last cleanup timestamp
try {
    if (!defined('IS_CALLED_BY_SCANNER')) { // Only update if not called by scanner
        $stmt = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('last_db_cleanup', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $stmt->execute([time()]);
    }
} catch (Exception $e) {}

$log("\n✅ Maintenance complete. Total rows deleted: $total_deleted");

// Return JSON for AJAX requests
if ($is_ajax) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'total_deleted' => $total_deleted,
        'tables' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}
