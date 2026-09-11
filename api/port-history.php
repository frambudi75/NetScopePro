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
$stmt->execute([$switch_id, $port_name, max(1, $hours)]);
$rows = $stmt->fetchAll();

$labels = [];
$rx = [];
$tx = [];

$label_format = ($hours > 24) ? 'd/m H:i' : 'H:i';

foreach ($rows as $row) {
    $labels[] = date($label_format, strtotime($row['recorded_at']));
    // Convert to Mbps (Megabits per second)
    $rx[] = round((float)$row['rx_bps'] / 1000000, 2);
    $tx[] = round((float)$row['tx_bps'] / 1000000, 2);
}

$count = count($rx);
$current_rx = $count > 0 ? end($rx) : 0.0;
$current_tx = $count > 0 ? end($tx) : 0.0;
$peak_rx    = $count > 0 ? max($rx) : 0.0;
$peak_tx    = $count > 0 ? max($tx) : 0.0;
$avg_rx     = $count > 0 ? round(array_sum($rx) / $count, 2) : 0.0;
$avg_tx     = $count > 0 ? round(array_sum($tx) / $count, 2) : 0.0;

json_response([
    'labels' => $labels,
    'rx' => $rx,
    'tx' => $tx,
    'port' => $port_name,
    'hours' => $hours,
    'count' => $count,
    'stats' => [
        'current_rx' => $current_rx,
        'current_tx' => $current_tx,
        'peak_rx' => $peak_rx,
        'peak_tx' => $peak_tx,
        'avg_rx' => $avg_rx,
        'avg_tx' => $avg_tx
    ]
]);

