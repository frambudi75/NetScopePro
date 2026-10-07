<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/loop.helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    json_response(['error' => 'Unauthorized'], 401);
}

// Release session lock to prevent blocking concurrent requests
session_write_close();

$action = $_GET['action'] ?? 'analyze';
$switch_id = (int)($_GET['switch_id'] ?? 0);

if (!$switch_id) {
    json_response(['error' => 'Valid switch_id is required'], 400);
}

$db = get_db_connection();

if ($action === 'analyze') {
    $data = LoopDetectiveHelper::analyze($db, $switch_id);
    if (!$data) {
        json_response(['error' => 'Switch not found'], 404);
    }
    json_response(['success' => true, 'data' => $data]);
}

if ($action === 'deep_probe') {
    $probe = LoopDetectiveHelper::runDeepProbe($db, $switch_id);
    json_response(['success' => true, 'probe' => $probe]);
}

json_response(['error' => 'Invalid action'], 400);
