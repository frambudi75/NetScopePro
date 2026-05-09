<?php
/**
 * IPManager Pro - Switch Health History API
 * Returns CPU + Memory history for Chart.js graphs.
 * GET /api/switch-history.php?id=<switch_id>&hours=<1-48>
 */

require_once '../includes/config.php';
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$id    = (int)($_GET['id']    ?? 0);
$hours = min(48, max(1, (int)($_GET['hours'] ?? 6)));

if ($id <= 0) {
    http_response_code(400);
    json_response(['error' => 'Invalid switch ID']);
}

$db = get_db_connection();

// Fetch history records
$stmt = $db->prepare("
    SELECT cpu_usage, memory_usage, recorded_at
    FROM switch_health_history
    WHERE switch_id = ?
      AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ORDER BY recorded_at ASC
    LIMIT 500
");
$stmt->execute([$id, $hours]);
$rows = $stmt->fetchAll();

// Fetch port count from port map
$portStmt = $db->prepare("
    SELECT COUNT(DISTINCT port_name) as port_count,
           COUNT(DISTINCT mac_addr) as device_count
    FROM switch_port_map
    WHERE switch_id = ?
");
$portStmt->execute([$id]);
$portStats = $portStmt->fetch();

$labels  = [];
$cpuData = [];
$memData = [];

foreach ($rows as $row) {
    $labels[]  = date('H:i', strtotime($row['recorded_at']));
    $cpuData[] = (int)$row['cpu_usage'];
    $memData[] = (int)$row['memory_usage'];
}

json_response([
    'labels'       => $labels,
    'cpu'          => $cpuData,
    'mem'          => $memData,
    'port_count'   => (int)($portStats['port_count']  ?? 0),
    'device_count' => (int)($portStats['device_count'] ?? 0),
    'hours'        => $hours,
]);
