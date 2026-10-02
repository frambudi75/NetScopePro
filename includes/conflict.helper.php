<?php
/**
 * NetScope Pro - Conflict & MAC Flap Helper
 */

require_once __DIR__ . '/network.php';
require_once __DIR__ . '/audit.helper.php';

class ConflictHelper {

    /**
     * Record a conflict or MAC flapping event
     */
    public static function logEvent($db, $ip, $mac_a, $mac_b = null, $details = null, $event_type = 'flapping') {
        if (empty($ip) || empty($mac_a)) return;

        $ip = trim($ip);
        $mac_a = strtolower(trim($mac_a));
        $mac_b = !empty($mac_b) ? strtolower(trim($mac_b)) : null;

        // Determine vendor names
        $vendor_a = function_exists('get_vendor_by_mac') ? get_vendor_by_mac($mac_a) : 'Unknown';
        $vendor_b = ($mac_b && function_exists('get_vendor_by_mac')) ? get_vendor_by_mac($mac_b) : 'Unknown';

        // Lookup switch physical port attachments
        $port_a = self::findSwitchPort($db, $mac_a);
        $port_b = $mac_b ? self::findSwitchPort($db, $mac_b) : null;

        try {
            // Check for active event for this IP
            $stmt = $db->prepare("
                SELECT id, flap_count, mac_b 
                FROM ip_conflict_events 
                WHERE ip_addr = ? AND status = 'active'
                ORDER BY detected_at DESC LIMIT 1
            ");
            $stmt->execute([$ip]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update active conflict event, increment flap count
                $stmt = $db->prepare("
                    UPDATE ip_conflict_events 
                    SET flap_count = flap_count + 1,
                        mac_b = COALESCE(?, mac_b),
                        vendor_b = COALESCE(?, vendor_b),
                        switch_port_a = COALESCE(?, switch_port_a),
                        switch_port_b = COALESCE(?, switch_port_b),
                        details = ?,
                        detected_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$mac_b, $vendor_b, $port_a, $port_b, $details, $existing['id']]);
            } else {
                // Insert new active conflict event
                $stmt = $db->prepare("
                    INSERT INTO ip_conflict_events 
                    (ip_addr, mac_a, mac_b, vendor_a, vendor_b, switch_port_a, switch_port_b, event_type, details, flap_count, status, detected_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'active', NOW())
                ");
                $stmt->execute([$ip, $mac_a, $mac_b, $vendor_a, $vendor_b, $port_a, $port_b, $event_type, $details]);
            }
        } catch (Exception $e) {
            error_log("ConflictHelper Error: " . $e->getMessage());
        }
    }

    /**
     * Find physical switch & port location for a MAC address
     */
    public static function findSwitchPort($db, $mac) {
        if (empty($mac)) return null;
        try {
            $stmt = $db->prepare("
                SELECT CONCAT(s.name, ' (', spm.port_name, ')') as location
                FROM switch_port_map spm
                JOIN switches s ON spm.switch_id = s.id
                WHERE LOWER(spm.mac_addr) = ?
                ORDER BY spm.last_seen DESC
                LIMIT 1
            ");
            $stmt->execute([strtolower($mac)]);
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Resolve a conflict state completely
     */
    public static function resolveConflict($db, $ip, $resolved_by = 'Admin') {
        try {
            $db->prepare("UPDATE ip_addresses SET conflict_detected = 0, conflict_mac = NULL, conflict_details = NULL WHERE ip_addr = ?")
               ->execute([$ip]);

            $db->prepare("UPDATE ip_conflict_events SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE ip_addr = ? AND status = 'active'")
               ->execute([$resolved_by, $ip]);

            AuditLogHelper::log('resolve_conflict', 'ip_address', null, "Resolved conflict for IP {$ip} (by {$resolved_by})");
            return true;
        } catch (Exception $e) {
            error_log("ConflictHelper::resolveConflict error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Accept New Host (Migrate IP ownership from MAC A to MAC B)
     */
    public static function acceptNewHost($db, $ip, $new_mac, $resolved_by = 'Admin') {
        try {
            $new_mac = strtolower(trim($new_mac));
            $vendor = function_exists('get_vendor_by_mac') ? get_vendor_by_mac($new_mac) : 'Unknown';

            $db->prepare("
                UPDATE ip_addresses 
                SET mac_addr = ?, 
                    vendor = COALESCE(?, vendor), 
                    conflict_detected = 0, 
                    conflict_mac = NULL, 
                    conflict_details = NULL 
                WHERE ip_addr = ?
            ")->execute([$new_mac, $vendor, $ip]);

            $db->prepare("
                UPDATE ip_conflict_events 
                SET status = 'resolved', 
                    resolved_at = NOW(), 
                    resolved_by = ?, 
                    details = CONCAT(COALESCE(details, ''), ' [Accepted new MAC: ', ?, ']') 
                WHERE ip_addr = ? AND status = 'active'
            ")->execute([$resolved_by, $new_mac, $ip]);

            AuditLogHelper::log('accept_new_mac', 'ip_address', null, "Accepted new MAC {$new_mac} for IP {$ip} (by {$resolved_by})");
            return true;
        } catch (Exception $e) {
            error_log("ConflictHelper::acceptNewHost error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ignore/Dismiss a conflict event
     */
    public static function ignoreEvent($db, $event_id, $resolved_by = 'Admin') {
        try {
            $db->prepare("UPDATE ip_conflict_events SET status = 'ignored', resolved_at = NOW(), resolved_by = ? WHERE id = ?")
               ->execute([$resolved_by, $event_id]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
