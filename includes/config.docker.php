<?php
/**
 * IPManager Docker-Specific Configuration
 * This file is automatically loaded when running inside a Docker container.
 */

// Database Configuration for Docker
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_NAME', getenv('DB_NAME') ?: 'ipmanage');
define('DB_USER', getenv('DB_USER') ?: 'ipmanager');
define('DB_PASS', getenv('DB_PASS') ?: 'ipmanager_pass');

// App URL is set in config.php to avoid redefinition

// Environment flag
define('ENV_DOCKER', true);

// Redis Configuration for Docker
define('REDIS_HOST', getenv('REDIS_HOST') ?: 'redis');
if (!defined('ENCRYPTION_KEY')) define('ENCRYPTION_KEY', '27ffed91f93d4e8eaf12a66852b4a156');

// OPTIMIZATION: Use Redis for sessions if extension is loaded
if (extension_loaded('redis')) {
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', 'tcp://' . (getenv('REDIS_HOST') ?: 'redis') . ':6379');
}
