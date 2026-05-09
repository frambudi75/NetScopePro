<?php
/**
 * IPManager Pro - Server Assets Backup Service
 * Run this script via Windows Task Scheduler or Cron
 * Example: php.exe c:\xampp\htdocs\ipmanage\cron_backup.php
 */

define('IS_CRON', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/settings.helper.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/asset.helper.php';

$db = get_db_connection();

// Security: Allow CLI, session-based auth (for UI buttons), or secret key
if (php_sapi_name() !== 'cli') {
    session_start();
    $key = $_GET['key'] ?? '';
    $secret = Settings::get('cron_key', 'your-secret-key-change-me');
    
    if (!isset($_SESSION['user_id']) && $key !== $secret) {
        die("Unauthorized.");
    }
}

if (php_sapi_name() === 'cli') {
    echo "[ " . date('Y-m-d H:i:s') . " ] Server Assets Backup Started\n";
}

$last_backup = (int)Settings::get('last_server_backup', 0);
$force = isset($_GET['force']) || (isset($argv) && in_array('--force', $argv));

// Check if 3 days (259200 seconds) have passed
if (!$force && (time() - $last_backup < 259200)) {
    if (php_sapi_name() === 'cli') echo "Backup skipped. Last backup was " . date('Y-m-d H:i:s', $last_backup) . "\n";
    exit;
}

// 1. Fetch Assets
$stmt = $db->query("SELECT * FROM server_assets ORDER BY hostname ASC");
$assets = $stmt->fetchAll();

if (empty($assets)) {
    if (php_sapi_name() === 'cli') echo "No assets found to backup.\n";
    exit;
}

// 2. Generate CSV (Updated for Advanced Schema)
$csv_content = "ID,Hostname,IP Address,Category,Username,Password,Is Encrypted,Port,Status,Last Check,Installed Apps,Missing Apps,Notes,Updated At\n";
foreach ($assets as $a) {
    $csv_content .= '"' . str_replace('"', '""', $a['id']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['hostname']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['ip_address']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['category']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['username']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['password']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['is_encrypted']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['port']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['status']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['last_check']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['installed_apps']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['missing_apps']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['notes']) . '",';
    $csv_content .= '"' . str_replace('"', '""', $a['updated_at']) . "\"\n";
}

// 3. Generate Text Summary (Human Readable & Decrypted)
$txt_content = "SERVER ASSETS BACKUP - " . date('Y-m-d H:i:s') . "\n";
$txt_content .= "==========================================\n\n";
foreach ($assets as $a) {
    if ($a['is_encrypted']) {
        $pass = AssetHelper::decrypt($a['password']);
        $user = AssetHelper::decrypt($a['username']);
        $notes = AssetHelper::decrypt($a['notes']);
        $installed = str_replace("\n", ", ", AssetHelper::decrypt($a['installed_apps']));
        $missing = str_replace("\n", ", ", AssetHelper::decrypt($a['missing_apps']));
    } else {
        $pass = $a['password'];
        $user = $a['username'];
        $notes = $a['notes'];
        $installed = str_replace("\n", ", ", $a['installed_apps']);
        $missing = str_replace("\n", ", ", $a['missing_apps']);
    }
    
    $txt_content .= "Server: " . $a['hostname'] . " (" . $a['ip_address'] . ":" . $a['port'] . ") [" . $a['category'] . "]\n";
    $txt_content .= "Status: " . $a['status'] . " (Last: " . $a['last_check'] . ")\n";
    $txt_content .= "Login: " . $user . " / " . $pass . "\n";
    $txt_content .= "Installed: " . $installed . "\n";
    $txt_content .= "Missing: " . $missing . "\n";
    $txt_content .= "Notes: " . $notes . "\n";
    $txt_content .= "------------------------------------------\n\n";
}

// 4. Generate ZIP Archive for "Safe Keeping"
$zip_filename = 'server_assets_backup_' . date('Y-m-d') . '.zip';
$zip_path = __DIR__ . '/tmp/' . $zip_filename;
if (!is_dir(__DIR__ . '/tmp')) mkdir(__DIR__ . '/tmp');

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $zip->addFromString('backup.csv', $csv_content);
    $zip->addFromString('summary.txt', $txt_content);
    $zip->close();
}

// 5. Determine Recipient
$to = Settings::get('admin_email');
if (php_sapi_name() !== 'cli' && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u_email = $stmt->fetchColumn();
    if (!empty($u_email)) $to = $u_email;
}

$subject = "📁 Server Assets Backup & Archive - " . date('d M Y');
$body = "<h2>Server Assets Automated Backup</h2>";
$body .= "<p>Hello, here are the backup files you requested for your server assets records.</p>";
$body .= "<p><b>ZIP Archive:</b> Best for long-term storage and safe-keeping.</p>";
$body .= "<p><b>CSV/TXT Files:</b> For quick inspection and restore.</p>";
$body .= "<br><p>Sent from: " . APP_NAME . "</p>";

$attachments = [
    'server_assets_backup_' . date('Y-m-d') . '.csv' => $csv_content,
    'server_assets_summary_' . date('Y-m-d') . '.txt' => $txt_content
];

if (file_exists($zip_path)) {
    $attachments[$zip_filename] = file_get_contents($zip_path);
}

$success = NotificationHelper::sendEmailWithAttachments($subject, $body, $attachments, $to); 

// Cleanup ZIP
if (file_exists($zip_path)) unlink($zip_path);

if ($success) {
    Settings::set('last_server_backup', time());
    $msg = "Backup successful and email sent!";
    if (php_sapi_name() === 'cli') echo $msg . "\n";
} else {
    $msg = "ERROR: Failed to send backup email. Check SMTP settings.";
    if (php_sapi_name() === 'cli') echo $msg . "\n";
}

// Redirect back if triggered via browser
if (php_sapi_name() !== 'cli') {
    header("Location: server-assets?msg=" . urlencode($msg));
    exit;
}
?>
