<?php
/**
 * AuditLogHelper - Logs system and user actions for audit purposes.
 */
class AuditLogHelper {
    /**
     * Log a generic action.
     */
    public static function log($action, $target_type = null, $target_id = null, $details = null) {
        $db = get_db_connection();
        $user_id = $_SESSION['user_id'] ?? null;

        try {
            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $action, $target_type, $target_id, $details]);
        } catch (Exception $e) {
            // Silently fail to not block main app flow
            error_log("Audit log failed: " . $e->getMessage());
        }
    }

    /**
     * Log an IP update action.
     */
    public static function logIpUpdate($ip_addr, $old_info, $new_info) {
        $changes = [];
        if (($old_info['hostname'] ?? '') !== $new_info['hostname']) {
            $changes[] = "Hostname: '" . ($old_info['hostname'] ?? '') . "' -> '" . $new_info['hostname'] . "'";
        }
        if (($old_info['description'] ?? '') !== $new_info['description']) {
            $changes[] = "Desc: '" . ($old_info['description'] ?? '') . "' -> '" . $new_info['description'] . "'";
        }
        if (($old_info['state'] ?? '') !== $new_info['state']) {
            $changes[] = "State: '" . ($old_info['state'] ?? '') . "' -> '" . $new_info['state'] . "'";
        }

        if (!empty($changes)) {
            $details = implode(", ", $changes);
            self::log("ip_update", "ip_address", $old_info['id'] ?? null, "IP {$ip_addr}: " . $details);
        }
    }
}
