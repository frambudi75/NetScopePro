<?php
require_once '../includes/config.php';
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$q = $_GET['q'] ?? '';
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$db = get_db_connection();
$results = [];

// 1. Search Subnets
$stmt = $db->prepare("SELECT id, subnet, mask, description as category, 'subnet' as type FROM subnets WHERE subnet LIKE ? OR description LIKE ? LIMIT 5");
$stmt->execute(["%$q%", "%$q%"]);
$results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));

// 2. Search Server Assets
if (is_admin()) {
    $stmt = $db->prepare("SELECT id, hostname as title, ip_address as subtitle, category, 'asset' as type FROM server_assets WHERE hostname LIKE ? OR ip_address LIKE ? OR category LIKE ? LIMIT 5");
    $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// 3. Search Switches
$stmt = $db->prepare("SELECT id, hostname as title, ip_addr as subtitle, model as category, 'switch' as type FROM switches WHERE hostname LIKE ? OR ip_addr LIKE ? OR model LIKE ? LIMIT 5");
$stmt->execute(["%$q%", "%$q%", "%$q%"]);
$results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));

header('Content-Type: application/json');
echo json_encode($results);
