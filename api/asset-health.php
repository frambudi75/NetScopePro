<?php
/**
 * API - Asset Health Check
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/asset.helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    json_response(['error' => 'Unauthorized'], 401);
}

if (!is_admin()) {
    json_response(['error' => 'Forbidden'], 403);
}

$id = $_GET['id'] ?? null;
if (!$id) {
    json_response(['error' => 'Asset ID required'], 400);
}

$db = get_db_connection();
$stmt = $db->prepare("SELECT id, ip_address, port FROM server_assets WHERE id = ?");
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) {
    json_response(['error' => 'Asset not found'], 404);
}

$result = AssetHelper::checkConnectivity($asset['ip_address'], $asset['port'] ?: 22);

// Update database
$update = $db->prepare("UPDATE server_assets SET status = ?, last_check = NOW() WHERE id = ?");
$update->execute([$result['status'], $id]);

json_response($result);
