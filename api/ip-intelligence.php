<?php
/**
 * NetScope Pro - IP Intelligence Dossier API
 * Returns unified L2 physical attachment, L3 network context, device telemetry,
 * conflict watch, and audit trail for a single IP address.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/network.php';
require_once __DIR__ . '/../includes/audit.helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Handle conflict resolution action
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'resolve_conflict') {
        $target_ip = trim($_POST['ip'] ?? '');
        if (!$target_ip) {
            echo json_encode(['success' => false, 'error' => 'IP parameter is required']);
            exit;
        }
        $db = get_db_connection();
        $stmt = $db->prepare("UPDATE ip_addresses SET conflict_detected = 0, conflict_mac = NULL, conflict_details = NULL WHERE ip_addr = ?");
        $stmt->execute([$target_ip]);

        AuditLogHelper::log('resolve_conflict', 'ip_address', null, "Resolved conflict for IP {$target_ip} via Intelligence Dossier");

        echo json_encode(['success' => true, 'message' => 'Conflict resolved successfully']);
        exit;
    }
}

$ip = trim($_GET['ip'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if (!$ip && !$id) {
    echo json_encode(['success' => false, 'error' => 'Missing IP address or ID parameter']);
    exit;
}

$db = get_db_connection();

// 1. Fetch IP record
if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM ip_addresses WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare("SELECT * FROM ip_addresses WHERE ip_addr = ? LIMIT 1");
    $stmt->execute([$ip]);
}
$ip_row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($ip_row) {
    $ip = $ip_row['ip_addr'];
    $subnet_id = (int)$ip_row['subnet_id'];
} else {
    // IP not yet recorded in ip_addresses (unallocated / free host in a known subnet)
    $subnet_id = find_subnet_for_ip($db, $ip);
}

// 2. Resolve Subnet & VLAN Context
$subnet_info = null;
if ($subnet_id) {
    $stmt = $db->prepare("
        SELECT s.id, s.subnet, s.mask, s.description, s.gateway_ip, s.dns_servers,
               s.scan_interval, s.last_scan,
               v.id as vlan_id, v.number as vlan_number, v.name as vlan_name
        FROM subnets s
        LEFT JOIN vlans v ON s.vlan_id = v.id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$subnet_id]);
    $sub_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($sub_row) {
        $subnet_info = [
            'id'          => (int)$sub_row['id'],
            'cidr'        => $sub_row['subnet'] . '/' . $sub_row['mask'],
            'description' => $sub_row['description'] ?: 'No description',
            'gateway_ip'  => $sub_row['gateway_ip'] ?: null,
            'dns_servers' => $sub_row['dns_servers'] ?: null,
            'vlan_id'     => $sub_row['vlan_id'] ? (int)$sub_row['vlan_id'] : null,
            'vlan_number' => $sub_row['vlan_number'] ?: null,
            'vlan_name'   => $sub_row['vlan_name'] ?: null,
        ];
    }
}

// 3. Resolve L2 Physical Attachment (Switch Port Map via MAC)
$mac_addr = $ip_row['mac_addr'] ?? null;
$switch_port_info = ['found' => false];

if ($mac_addr) {
    $stmt = $db->prepare("
        SELECT m.port_name, m.port_status, m.port_speed, m.port_type, m.vlan_id, m.vlan_name,
               m.stp_state, m.sfp_vendor, m.sfp_part, m.sfp_rx_power, m.updated_at as last_seen_on_port,
               s.id as switch_id, s.name as switch_name, s.ip_addr as switch_ip, s.model as switch_model
        FROM switch_port_map m
        JOIN switches s ON m.switch_id = s.id
        WHERE m.mac_addr = ?
        ORDER BY m.updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$mac_addr]);
    $port_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($port_row) {
        $switch_port_info = [
            'found'             => true,
            'switch_id'         => (int)$port_row['switch_id'],
            'switch_name'       => $port_row['switch_name'],
            'switch_ip'         => $port_row['switch_ip'],
            'switch_model'      => $port_row['switch_model'] ?: 'Generic Switch',
            'port_name'         => $port_row['port_name'],
            'port_status'       => $port_row['port_status'] ?: 'unknown',
            'port_speed'        => $port_row['port_speed'] ?: null,
            'port_type'         => $port_row['port_type'] ?: null,
            'stp_state'         => $port_row['stp_state'] ?: null,
            'vlan_id'           => $port_row['vlan_id'] ?: null,
            'vlan_name'         => $port_row['vlan_name'] ?: null,
            'sfp_vendor'        => $port_row['sfp_vendor'] ?: null,
            'sfp_part'          => $port_row['sfp_part'] ?: null,
            'sfp_rx_power'      => $port_row['sfp_rx_power'] ?: null,
            'last_seen_on_port' => $port_row['last_seen_on_port'] ? date('d M Y H:i', strtotime($port_row['last_seen_on_port'])) : null,
        ];
    }
}

// 4. Resolve Cross-Module Nodes (Is this IP a switch, server asset, or in Netwatch?)
$related_nodes = [
    'is_switch'        => false,
    'switch_id'        => null,
    'switch_name'      => null,
    'is_server_asset'  => false,
    'asset_id'         => null,
    'asset_hostname'   => null,
    'asset_category'   => null,
    'asset_status'     => null,
    'is_netwatch'      => false,
    'netwatch_id'      => null,
    'netwatch_name'    => null,
    'netwatch_status'  => null,
];

// Check if IP is a switch
$stmt = $db->prepare("SELECT id, name FROM switches WHERE ip_addr = ? LIMIT 1");
$stmt->execute([$ip]);
if ($sw = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $related_nodes['is_switch'] = true;
    $related_nodes['switch_id'] = (int)$sw['id'];
    $related_nodes['switch_name'] = $sw['name'];
}

// Check if IP is a server asset
$stmt = $db->prepare("SELECT id, hostname, category, status FROM server_assets WHERE ip_address = ? LIMIT 1");
$stmt->execute([$ip]);
if ($sa = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $related_nodes['is_server_asset'] = true;
    $related_nodes['asset_id'] = (int)$sa['id'];
    $related_nodes['asset_hostname'] = $sa['hostname'];
    $related_nodes['asset_category'] = $sa['category'];
    $related_nodes['asset_status'] = $sa['status'];
}

// Check if IP is in netwatch
$stmt = $db->prepare("SELECT id, name, status FROM netwatch WHERE host = ? LIMIT 1");
$stmt->execute([$ip]);
if ($nw = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $related_nodes['is_netwatch'] = true;
    $related_nodes['netwatch_id'] = (int)$nw['id'];
    $related_nodes['netwatch_name'] = $nw['name'];
    $related_nodes['netwatch_status'] = $nw['status'];
}

// 5. Parse Data Sources and Confidence Score
$data_sources = [];
if (!empty($ip_row['data_sources'])) {
    foreach (explode(',', $ip_row['data_sources']) as $src) {
        $src = strtoupper(trim($src));
        if ($src !== '') $data_sources[] = $src;
    }
}

// 6. Recent Audit Trail (Last 5 log entries mentioning this IP)
$audit_trail = [];
$stmt = $db->prepare("
    SELECT action, details, created_at 
    FROM audit_logs 
    WHERE details LIKE ? 
    ORDER BY id DESC 
    LIMIT 5
");
$stmt->execute(["%{$ip}%"]);
while ($log = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $audit_trail[] = [
        'action'     => $log['action'],
        'details'    => $log['details'],
        'created_at' => date('d M Y H:i', strtotime($log['created_at'])),
    ];
}

// Build Dossier Object
$dossier = [
    'id'               => $ip_row['id'] ?? null,
    'ip'               => $ip,
    'status'           => $ip_row['state'] ?? 'free',
    'hostname'         => $ip_row['hostname'] ?? null,
    'description'      => $ip_row['description'] ?? null,
    'asset_tag'        => $ip_row['asset_tag'] ?? null,
    'owner'            => $ip_row['owner'] ?? null,
    'mac_addr'         => $mac_addr,
    'vendor'           => $ip_row['vendor'] ?? null,
    'os'               => $ip_row['os'] ?? null,
    'confidence_score' => (int)($ip_row['confidence_score'] ?? 0),
    'data_sources'     => $data_sources,
    'first_seen'       => isset($ip_row['created_at']) ? date('d M Y H:i', strtotime($ip_row['created_at'])) : null,
    'last_seen'        => isset($ip_row['last_seen']) ? date('d M Y H:i', strtotime($ip_row['last_seen'])) : null,
    'conflict'         => [
        'detected' => (bool)($ip_row['conflict_detected'] ?? 0),
        'mac'      => $ip_row['conflict_mac'] ?? null,
        'details'  => $ip_row['conflict_details'] ?? null,
    ],
    'subnet'           => $subnet_info,
    'switch_port'      => $switch_port_info,
    'related_nodes'    => $related_nodes,
    'audit_trail'      => $audit_trail,
];

echo json_encode([
    'success' => true,
    'data'    => $dossier,
]);
