<?php
/**
 * Database connection helper using PDO
 */

require_once 'config.php';

function get_db_connection() {
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $dsn = "mysql:host=" . DB_HOST . ";port=" . $port . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+07:00'");
        run_auto_migrations($pdo);
        return $pdo;
    } catch (\PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

/**
 * IPManager - Global Redis Client Getter
 * Returns a Redis instance if extension is loaded and connection works.
 */
function get_redis_connection() {
    static $redis_instance = null;
    
    // Check if extension is loaded
    if (!extension_loaded('redis')) return null;
    
    // Return existing instance if available
    if ($redis_instance !== null) return $redis_instance;
    
    try {
        $redis = new Redis();
        $host = defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1';
        $redis->connect($host, 6379, 1.5); // 1.5s timeout
        $redis_instance = $redis;
        return $redis_instance;
    } catch (Exception $e) {
        return null; // Silent fail if redis is down
    }
}

/**
 * Ensures database structure is up to date
 */
function run_auto_migrations($db) {
    // Check if subnets table exists first
    $tableExists = $db->query("SHOW TABLES LIKE 'subnets'")->rowCount() > 0;
    if (!$tableExists) {
        return; // Skip migrations if table hasn't been imported yet
    }

    // 1. Check Subnets table for new columns
    $cols = $db->query("SHOW COLUMNS FROM subnets")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('scan_interval', $cols)) {
        $db->exec("ALTER TABLE subnets ADD COLUMN scan_interval int(11) DEFAULT 0");
    }
    if (!in_array('last_scan', $cols)) {
        $db->exec("ALTER TABLE subnets ADD COLUMN last_scan timestamp NULL DEFAULT NULL");
    }
    if (!in_array('last_limit_alert', $cols)) {
        $db->exec("ALTER TABLE subnets ADD COLUMN last_limit_alert timestamp NULL DEFAULT NULL AFTER last_scan");
    }

    // 2. Check IP Addresses table for OS column
    $ip_cols = $db->query("SHOW COLUMNS FROM ip_addresses")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('os', $ip_cols)) {
        $db->exec("ALTER TABLE ip_addresses ADD COLUMN os varchar(100) DEFAULT NULL AFTER vendor");
    }

    if (!in_array('conflict_detected', $ip_cols)) {
        $db->exec("ALTER TABLE ip_addresses ADD COLUMN conflict_detected tinyint(1) NOT NULL DEFAULT 0 AFTER os");
    }

    if (!in_array('fail_count', $ip_cols)) {
        $db->exec("ALTER TABLE ip_addresses ADD COLUMN fail_count int(11) NOT NULL DEFAULT 0 AFTER data_sources");
    }

    if (!in_array('asset_tag', $ip_cols)) {
        $db->exec("ALTER TABLE ip_addresses ADD COLUMN asset_tag varchar(100) DEFAULT NULL AFTER fail_count");
    }

    if (!in_array('owner', $ip_cols)) {
        $db->exec("ALTER TABLE ip_addresses ADD COLUMN owner varchar(100) DEFAULT NULL AFTER asset_tag");
    }

    // 3. Settings table
    try {
        $db->query("SELECT 1 FROM settings LIMIT 1");
    } catch (Exception $e) {
// ... (lines 88-117)
        $db->exec("
            CREATE TABLE IF NOT EXISTS `settings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `key` varchar(50) NOT NULL,
            `value` text DEFAULT NULL,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `key` (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
        // Insert default values
        $db->exec("
            INSERT IGNORE INTO `settings` (`key`, `value`) VALUES 
            ('telegram_enabled', '0'),
            ('telegram_bot_token', ''),
            ('telegram_chat_id', ''),
            ('email_enabled', '0'),
            ('admin_email', 'admin@example.com'),
            ('smtp_host', 'localhost'),
            ('smtp_port', '25'),
            ('smtp_user', ''),
            ('smtp_pass', ''),
            ('mail_from', ''),
            ('nmap_enabled', '0'),
            ('discovery_aggressive', '1'),
            ('subnet_limit_threshold', '80'),
            ('offline_fail_threshold', '3'),
            ('last_server_backup', '0');
        ");
    }

    // 4. Create Audit Logs table
// ... (lines 120-170)
    // 9. Add missing columns to switches (Migration)
    try {
        $db->exec("ALTER TABLE switches ADD COLUMN IF NOT EXISTS model VARCHAR(100)");
        $db->exec("ALTER TABLE switches ADD COLUMN IF NOT EXISTS uptime VARCHAR(100)");
        $db->exec("ALTER TABLE switches ADD COLUMN IF NOT EXISTS cpu_usage INT DEFAULT 0");
        $db->exec("ALTER TABLE switches ADD COLUMN IF NOT EXISTS memory_usage INT DEFAULT 0");
        $db->exec("ALTER TABLE switches ADD COLUMN IF NOT EXISTS system_info TEXT");
        $db->exec("ALTER TABLE switches ADD COLUMN IF NOT EXISTS parent_switch_id INT DEFAULT NULL");
    } catch(Exception $e) { /* Already exists or not supported */ }

    // 10. Subnets Utilization Threshold & Manual Topology (New)
    $db_cols = $db->query("SHOW COLUMNS FROM subnets")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('utilization_threshold', $db_cols)) {
        $db->exec("ALTER TABLE subnets ADD COLUMN utilization_threshold INT DEFAULT NULL AFTER last_limit_alert");
    }
    if (!in_array('parent_switch_id', $db_cols)) {
        $db->exec("ALTER TABLE subnets ADD COLUMN parent_switch_id INT DEFAULT NULL AFTER utilization_threshold");
    }

    // 11. Create Switch Health History Table
    $db->exec("CREATE TABLE IF NOT EXISTS switch_health_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        switch_id INT NOT NULL,
        cpu_usage INT NOT NULL DEFAULT 0,
        memory_usage INT NOT NULL DEFAULT 0,
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_switch_time (switch_id, recorded_at),
        FOREIGN KEY (switch_id) REFERENCES switches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

    // 12. Manual Topology Links Table (New)
    $db->exec("CREATE TABLE IF NOT EXISTS topology_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_switch_id INT NOT NULL,
        target_type ENUM('switch', 'subnet') NOT NULL,
        target_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (parent_switch_id, target_type, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

    // 13. Server Assets Table (Advanced)
    $db->exec("CREATE TABLE IF NOT EXISTS server_assets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hostname VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        category VARCHAR(50) DEFAULT 'General',
        username VARCHAR(100) DEFAULT NULL,
        password VARCHAR(255) DEFAULT NULL,
        is_encrypted TINYINT(1) DEFAULT 0,
        port INT DEFAULT 22,
        status VARCHAR(20) DEFAULT 'UNKNOWN',
        last_check TIMESTAMP NULL DEFAULT NULL,
        installed_apps TEXT DEFAULT NULL,
        missing_apps TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

    // Check for missing columns in existing server_assets table
    try {
        $asset_cols = $db->query("SHOW COLUMNS FROM server_assets")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('category', $asset_cols)) {
            $db->exec("ALTER TABLE server_assets ADD COLUMN category VARCHAR(50) DEFAULT 'General' AFTER ip_address");
        }
        if (!in_array('is_encrypted', $asset_cols)) {
            $db->exec("ALTER TABLE server_assets ADD COLUMN is_encrypted TINYINT(1) DEFAULT 0 AFTER password");
        }
        if (!in_array('status', $asset_cols)) {
            $db->exec("ALTER TABLE server_assets ADD COLUMN status VARCHAR(20) DEFAULT 'UNKNOWN' AFTER port");
        }
        if (!in_array('last_check', $asset_cols)) {
            $db->exec("ALTER TABLE server_assets ADD COLUMN last_check TIMESTAMP NULL DEFAULT NULL AFTER status");
        }
    } catch (Exception $e) {}

    // 14. Bug Reports Table
    $db->exec("CREATE TABLE IF NOT EXISTS bug_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        system_info TEXT DEFAULT NULL,
        status ENUM('pending', 'resolved') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

    // 15. Netwatch History Table (Latency Tracking)
    $db->exec("CREATE TABLE IF NOT EXISTS netwatch_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        netwatch_id INT NOT NULL,
        latency FLOAT DEFAULT 0,
        status ENUM('up', 'down', 'unknown') DEFAULT 'unknown',
        recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_netwatch_time (netwatch_id, recorded_at),
        FOREIGN KEY (netwatch_id) REFERENCES netwatch(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

    // 16. Add maintenance column if not exists
    try {
        $db->exec("ALTER TABLE netwatch ADD COLUMN maintenance_until DATETIME DEFAULT NULL");
    } catch (Exception $e) { /* ignore if already exists */ }

    // 17. Convert tables to utf8mb4 for emoji support
    try {
        $db->exec("ALTER TABLE settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("ALTER TABLE netwatch CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("ALTER TABLE netwatch_history CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) { /* ignore on failure */ }
}
