<?php
/**
 * API: Get Netwatch Latency History
 */
require_once '../includes/config.php';
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    json_response(['error' => 'Unauthorized'], 401);
}

$id = $_GET['id'] ?? 0;
$period = $_GET['period'] ?? '1h'; // 1h, 6h, 24h

$db = get_db_connection();

// Determine interval based on period
$interval_sql = "INTERVAL 1 HOUR";
if ($period === '6h') $interval_sql = "INTERVAL 6 HOUR";
if ($period === '24h') $interval_sql = "INTERVAL 24 HOUR";

try {
    $stmt = $db->prepare("
        SELECT latency, status, recorded_at 
        FROM netwatch_history 
        WHERE netwatch_id = ? 
        AND recorded_at >= NOW() - $interval_sql
        ORDER BY recorded_at ASC
    ");
    $stmt->execute([$id]);
    $history = $stmt->fetchAll();

    $labels = [];
    $data = [];
    $statuses = [];

    foreach ($history as $h) {
        $labels[] = date('H:i', strtotime($h['recorded_at']));
        $data[] = (float)$h['latency'];
        $statuses[] = $h['status'];
    }

    json_response([
        'labels' => $labels,
        'data' => $data,
        'status' => $statuses
    ]);

} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}
