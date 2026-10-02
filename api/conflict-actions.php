<?php
/**
 * NetScope Pro - IP Conflict Action Endpoint
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/conflict.helper.php';
require_once __DIR__ . '/../includes/audit.helper.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = trim($_POST['action'] ?? ($_GET['action'] ?? ''));
$ip = trim($_POST['ip'] ?? ($_GET['ip'] ?? ''));
$mac = trim($_POST['mac'] ?? ($_GET['mac'] ?? ''));
$event_id = (int)($_POST['event_id'] ?? ($_GET['event_id'] ?? 0));
$username = $_SESSION['username'] ?? 'User';

$db = get_db_connection();

if ($action === 'resolve') {
    if (empty($ip)) {
        echo json_encode(['success' => false, 'error' => 'IP parameter is required']);
        exit;
    }
    $ok = ConflictHelper::resolveConflict($db, $ip, $username);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? "Conflict on {$ip} has been resolved." : "Failed to resolve conflict."
    ]);
    exit;
}

if ($action === 'accept_new') {
    if (empty($ip) || empty($mac)) {
        echo json_encode(['success' => false, 'error' => 'IP and MAC parameters are required']);
        exit;
    }
    $ok = ConflictHelper::acceptNewHost($db, $ip, $mac, $username);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? "MAC {$mac} accepted as official host for {$ip}." : "Failed to accept new host."
    ]);
    exit;
}

if ($action === 'ignore') {
    if ($event_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Event ID parameter is required']);
        exit;
    }
    $ok = ConflictHelper::ignoreEvent($db, $event_id, $username);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? "Event marked as ignored." : "Failed to update event."
    ]);
    exit;
}

if ($action === 'probe') {
    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['success' => false, 'error' => 'Valid IP parameter is required']);
        exit;
    }
    
    // Release session lock before running probe commands
    session_write_close();

    $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $probe_results = [];
    $all_ttls = [];
    $all_macs = [];

    for ($cycle = 1; $cycle <= 3; $cycle++) {
        $ping_cmd = $is_windows 
            ? "ping -n 2 -w 1000 " . escapeshellarg($ip) 
            : "ping -c 2 -W 1 " . escapeshellarg($ip);
        $raw = (string)shell_exec($ping_cmd);

        $ttl = null;
        if (preg_match('/TTL=(\d+)/i', $raw, $m)) {
            $ttl = (int)$m[1];
            $all_ttls[] = $ttl;
        }

        // Query ARP
        $arp_lines = [];
        if ($is_windows) {
            @exec("arp -a " . escapeshellarg($ip), $arp_lines);
        } else {
            @exec("arp -n " . escapeshellarg($ip), $arp_lines);
        }

        $seen_mac = null;
        foreach ($arp_lines as $line) {
            if (preg_match('/([0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2})/', $line, $matches)) {
                $seen_mac = strtolower(str_replace('-', ':', $matches[1]));
                break;
            }
        }

        if ($seen_mac && $seen_mac !== 'ff:ff:ff:ff:ff:ff' && $seen_mac !== '00:00:00:00:00:00') {
            $all_macs[] = $seen_mac;
        }

        $probe_results[] = [
            'cycle' => $cycle,
            'ttl' => $ttl,
            'mac' => $seen_mac,
            'vendor' => $seen_mac ? get_vendor_by_mac($seen_mac) : null,
            'responded' => ($ttl !== null)
        ];
        usleep(150000); // 150ms pause between cycles
    }

    $unique_macs = array_unique($all_macs);
    $unique_ttls = array_unique($all_ttls);
    $is_flapping = (count($unique_macs) > 1);
    $has_ttl_jitter = (count($unique_ttls) > 1);

    echo json_encode([
        'success' => true,
        'ip' => $ip,
        'probes' => $probe_results,
        'unique_macs' => array_values($unique_macs),
        'unique_ttls' => array_values($unique_ttls),
        'is_flapping' => $is_flapping,
        'has_ttl_jitter' => $has_ttl_jitter,
        'verdict' => $is_flapping ? 'ACTIVE_MAC_FLAP' : ($has_ttl_jitter ? 'TTL_DISCREPANCY' : 'STABLE')
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
