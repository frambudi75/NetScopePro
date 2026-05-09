<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    json_response(['error' => 'Unauthorized'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$db = get_db_connection();
$title = $_POST['title'] ?? '';
$description = $_POST['description'] ?? '';

if (empty($title) || empty($description)) {
    json_response(['error' => 'Title and description are required'], 400);
}

// Auto-capture system info
$system_info = [
    'app_version' => APP_VERSION,
    'php_version' => PHP_VERSION,
    'os' => PHP_OS,
    'server_software' => $_SERVER['SERVER_SOFTWARE'],
    'http_host' => $_SERVER['HTTP_HOST'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT']
];
$system_info_json = json_encode($system_info);

try {
    // 1. Store in local DB
    $stmt = $db->prepare("INSERT INTO bug_reports (user_id, title, description, system_info) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $title, $description, $system_info_json]);
    
    // 2. Send email to Developer
    $subject = "🚨 [BUG REPORT] " . $title . " - " . $_SERVER['HTTP_HOST'];
    $body = "<h2>Bug Report from " . APP_NAME . "</h2>";
    $body .= "<p><b>From:</b> " . $_SESSION['username'] . " (" . $_SERVER['HTTP_HOST'] . ")</p>";
    $body .= "<p><b>Issue:</b> " . htmlspecialchars($description) . "</p>";
    $body .= "<h3>System Context</h3>";
    $body .= "<ul>";
    foreach ($system_info as $key => $val) {
        $body .= "<li><b>$key:</b> $val</li>";
    }
    $body .= "</ul>";
    
    NotificationHelper::sendEmailWithAttachments($subject, $body, [], DEVELOPER_EMAIL);

    json_response(['success' => true, 'message' => 'Bug report sent successfully!']);
} catch (Exception $e) {
    json_response(['error' => 'Failed to process bug report: ' . $e->getMessage()], 500);
}
