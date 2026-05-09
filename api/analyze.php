<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/network.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$db = get_db_connection();
$subnet_id = (int)($_GET['id'] ?? 0);

if (!$subnet_id) {
    json_response(['success' => false, 'message' => 'Missing Subnet ID'], 400);
}

// Fetch subnet info
$stmt = $db->prepare("SELECT * FROM subnets WHERE id = ?");
$stmt->execute([$subnet_id]);
$subnet = $stmt->fetch();

if (!$subnet) {
    json_response(['success' => false, 'message' => 'Subnet not found'], 404);
}

list($start_long, $end_long) = cidr_to_range($subnet['subnet'] . '/' . $subnet['mask']);

// 1. Detect Gateway
$potential_gateways = [];
if ($subnet['mask'] < 31) {
    $potential_gateways[] = long2ip($start_long + 1); // .1
    $potential_gateways[] = long2ip($end_long - 1);   // .254
}

$gateway_results = [];
$arp_map = refresh_arp_map();

foreach ($potential_gateways as $gw_ip) {
    $is_alive = ping_ip($gw_ip, 2, 400);
    $is_in_arp = isset($arp_map[$gw_ip]);
    
    if ($is_alive || $is_in_arp) {
        $gateway_results[] = [
            'ip' => $gw_ip,
            'status' => $is_alive ? 'online' : 'arp-only',
            'mac' => $arp_map[$gw_ip] ?? 'unknown',
            'vendor' => isset($arp_map[$gw_ip]) ? get_vendor_by_mac($arp_map[$gw_ip]) : '-'
        ];
    }
}

// 2. Scan for DNS Resolvers in this subnet
$dns_resolvers = [];
// We only scan a small sample of active IPs or common candidates to keep it fast
$stmt = $db->prepare("SELECT ip_addr FROM ip_addresses WHERE subnet_id = ? AND state = 'active' LIMIT 50");
$stmt->execute([$subnet_id]);
$active_ips = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Always check gateways for DNS too
$dns_candidates = array_unique(array_merge($potential_gateways, $active_ips));

foreach ($dns_candidates as $ip) {
    if (check_port($ip, 53, 0.2, 1)) {
        $dns_resolvers[] = [
            'ip' => $ip,
            'status' => 'responding'
        ];
    }
}

// 3. Simple Routing Info (Local Subnet vs External)
$routing_info = [
    'local_interface' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
    'is_local_range' => false, // We could compare server IP with subnet range
];

$server_ip_long = ip2long($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
if ($server_ip_long >= $start_long && $server_ip_long <= $end_long) {
    $routing_info['is_local_range'] = true;
}

json_response([
    'success' => true,
    'data' => [
        'gateways' => $gateway_results,
        'dns_resolvers' => $dns_resolvers,
        'routing' => $routing_info,
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);
