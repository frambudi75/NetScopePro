<?php
/**
 * API - Decrypt and Log Password Reveal
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/asset.helper.php';
require_once '../includes/audit.helper.php';

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
$stmt = $db->prepare("SELECT hostname, password, is_encrypted FROM server_assets WHERE id = ?");
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) {
    json_response(['error' => 'Asset not found'], 404);
}

$password = $asset['password'];
if ($asset['is_encrypted']) {
    $password = AssetHelper::decrypt($password);
}

// Log the reveal action
AuditLogHelper::log('reveal_password', 'server_assets', $id, "User {$_SESSION['username']} revealed password for '{$asset['hostname']}'");

json_response(['password' => $password]);
