<?php
/**
 * IPManager Pro - Port Traffic History API
 */
require_once '../includes/config.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$db = get_db_connection();
$switch_id = (int)($_GET['id'] ?? 0);
$port_name = $_GET['port'] ?? '';
$hours     = (int)($_GET['hours'] ?? 6);

if (!$switch_id || !$port_name) {
    json_response(['error' => 'Missing parameters'], 400);
}

$query = "
    SELECT rx_bps, tx_bps, recorded_at 
    FROM switch_port_history 
    WHERE switch_id = ? AND port_name = ? 
      AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ORDER BY recorded_at ASC
";

$stmt = $db->prepare($query);
$stmt->execute([$switch_id, $port_name, $hours]);
$rows = $stmt->fetchAll();

$labels = [];
$rx = [];
$tx = [];

foreach ($rows as $row) {
    $labels[] = date('H:i', strtotime($row['recorded_at']));
    // Convert to Mbps for better readability if needed, but keeping as bps for flexibility
    $rx[] = round($row['rx_bps'] / 1000000, 2); // Mbps
    $tx[] = round($row['tx_bps'] / 1000000, 2); // Mbps
}

json_response([
    'labels' => $labels,
    'rx' => $rx,
    'tx' => $tx,
    'port' => $port_name
]);
