<?php
ob_start();
/**
 * IPManager Pro Configuration
 */

// Auto-Detection for Docker Environment
if (getenv('DOCKER_ENV') === '1') {
    require_once 'config.docker.php';
} else {
    // Database Configuration (Default for XAMPP / Local)
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_NAME', getenv('DB_NAME') ?: 'ipmanage');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
}
require_once 'version.php';
require_once 'settings.helper.php';
require_once 'updater.php';

// Regional Settings
date_default_timezone_set('Asia/Jakarta');
putenv("MIBDIRS=C:/xampp/php/extras/mibs");

// Dynamic Configuration from Database
define('DISCOVERY_AGGRESSIVE_MODE', Settings::enabled('discovery_aggressive'));
define('ENABLE_NMAP_FALLBACK', Settings::enabled('nmap_enabled'));

// Application Configuration
if (!defined('APP_NAME')) define('APP_NAME', 'IPManager Pro');
if (!defined('APP_URL')) define('APP_URL', getenv('APP_URL') ?: 'http://localhost/ipmanage');
if (!defined('ENCRYPTION_KEY')) define('ENCRYPTION_KEY', '27ffed91f93d4e8eaf12a66852b4a156');
if (!defined('DEVELOPER_EMAIL')) define('DEVELOPER_EMAIL', 'frambudihabib@gmail.com');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Standard success/error responses
function json_response($data, $status = 200) {
    if (ob_get_length()) ob_clean(); // Clear any warnings/garbage before JSON
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

/**
 * Auth Helpers
 */
function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function is_viewer() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'viewer';
}
