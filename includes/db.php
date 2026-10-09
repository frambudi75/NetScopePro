<?php
/**
 * Database connection helper using PDO
 */

if (!function_exists('get_db_connection')) {
function get_db_connection($maxRetries = 1, $retryDelaySeconds = 2) {
    require_once __DIR__ . '/config.php';
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $dsn = "mysql:host=" . DB_HOST . ";port=" . $port . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $attempt = 0;
    while ($attempt < $maxRetries) {
        $attempt++;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+07:00'");
            run_auto_migrations($pdo);
            return $pdo;
        } catch (\PDOException $e) {
            // Check if error is 1049 (Unknown database)
            $isUnknownDb = ($e->getCode() == 1049) || (stripos($e->getMessage(), 'Unknown database') !== false);
            if ($isUnknownDb) {
                try {
                    $rawDsn = "mysql:host=" . DB_HOST . ";port=" . $port . ";charset=utf8mb4";
                    $rawPdo = new PDO($rawDsn, DB_USER, DB_PASS, $options);
                    $rawPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $rawPdo = null;

                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                    $pdo->exec("SET time_zone = '+07:00'");
                    run_auto_migrations($pdo);
                    return $pdo;
                } catch (\Exception $createEx) {
                    error_log("[db_auto_heal] Auto-create DB failed: " . $createEx->getMessage());
                }
            }

            if ($attempt >= $maxRetries) {
                if (php_sapi_name() === 'cli') {
                    throw $e;
                }
                die("Connection failed: " . $e->getMessage());
            }

            if (php_sapi_name() === 'cli') {
                echo "[db_connect] Database not ready (attempt {$attempt}/{$maxRetries}). Retrying in {$retryDelaySeconds}s...\n";
            }
            sleep($retryDelaySeconds);
        }
    }
}
}

/**
 * IPManager - Global Redis Client Getter
 * Returns a Redis instance if extension is loaded and connection works.
 */
if (!function_exists('get_redis_connection')) {
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
}

/**
 * Ensures database structure is up to date
 */
if (!function_exists('run_auto_migrations')) {
function run_auto_migrations($db) {
    static $migrations_done = false;
    if ($migrations_done) return;
    $migrations_done = true;

    // 0. Base Tables Auto-Healing & Data Protection Guard
    // CRITICAL: Only import full database.sql if the database is 100% EMPTY (brand new install).
    // If ANY tables exist, NEVER run full SQL file to strictly protect existing user data.
    $totalExistingTables = (int)$db->query("SHOW TABLES")->rowCount();

    if ($totalExistingTables === 0) {
        $sqlFiles = [
            __DIR__ . '/../sql/database.sql',
            '/var/www/html/sql/database.sql',
            dirname(__DIR__) . '/sql/database.sql'
        ];
        
        $imported = false;
        foreach ($sqlFiles as $file) {
            if (file_exists($file)) {
                try {
                    $sql = file_get_contents($file);
                    if ($sql) {
                        $db->exec($sql);
                        $imported = true;
                        break;
                    }
                } catch (Exception $e) {
                    error_log("[db_auto_heal] Error importing schema from {$file}: " . $e->getMessage());
                }
            }
        }
    }

    // Guarantee all essential base tables exist (non-destructive: CREATE TABLE IF NOT EXISTS)
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL,
                `password` varchar(255) NOT NULL,
                `email` varchar(100) DEFAULT NULL,
                `role` enum('admin','user','viewer') NOT NULL DEFAULT 'viewer',
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // Guarantee default admin exists if users table is empty
            $userCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($userCount === 0) {
                $defaultPass = '$2y$10$iC1CpjbPVLpFx1BcbSTUsOZ52qhELYqHrKyADN/z9DF2UArhZEnPK'; // default: admin
                $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES ('admin', ?, 'admin')");
                $stmt->execute([$defaultPass]);
            }
        } catch (Exception $e) {
            error_log("[db_auto_heal] Fallback users error: " . $e->getMessage());
        }

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `subnets` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `network` varchar(45) NOT NULL,
                `cidr` int(11) NOT NULL,
                `vlan` int(11) DEFAULT NULL,
                `description` text DEFAULT NULL,
                `scan_interval` int(11) DEFAULT 0,
                `last_scan` timestamp NULL DEFAULT NULL,
                `last_limit_alert` timestamp NULL DEFAULT NULL,
                `utilization_threshold` int(11) DEFAULT NULL,
                `parent_switch_id` int(11) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Exception $e) {}

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `ip_addresses` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `subnet_id` int(11) NOT NULL,
                `ip_address` varchar(45) NOT NULL,
                `mac_address` varchar(17) DEFAULT NULL,
                `hostname` varchar(255) DEFAULT NULL,
                `status` enum('active','reserved','offline') NOT NULL DEFAULT 'offline',
                `vendor` varchar(100) DEFAULT NULL,
                `os` varchar(100) DEFAULT NULL,
                `conflict_detected` tinyint(1) NOT NULL DEFAULT 0,
                `conflict_mac` varchar(20) DEFAULT NULL,
                `conflict_details` varchar(255) DEFAULT NULL,
                `last_seen` timestamp NULL DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `data_sources` varchar(255) DEFAULT NULL,
                `fail_count` int(11) NOT NULL DEFAULT 0,
                `asset_tag` varchar(100) DEFAULT NULL,
                `owner` varchar(100) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_ip` (`ip_address`),
                KEY `subnet_id` (`subnet_id`),
                KEY `idx_conflict` (`conflict_detected`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Exception $e) {}

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `switches` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `ip_addr` varchar(45) NOT NULL,
                `community` varchar(100) NOT NULL DEFAULT 'public',
                `snmp_version` enum('v1','v2c','v3') NOT NULL DEFAULT 'v2c',
                `description` text DEFAULT NULL,
                `last_poll` timestamp NULL DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `model` varchar(100) DEFAULT NULL,
                `uptime` varchar(100) DEFAULT NULL,
                `cpu_usage` int(11) DEFAULT 0,
                `memory_usage` int(11) DEFAULT 0,
                `system_info` text DEFAULT NULL,
                `total_ports` int(11) DEFAULT 0,
                `active_ports` int(11) DEFAULT 0,
                `parent_switch_id` int(11) DEFAULT NULL,
                `stp_enabled` tinyint(1) DEFAULT 0,
                `stp_protocol` varchar(50) DEFAULT NULL,
                `loop_detected` tinyint(1) DEFAULT 0,
                `loop_details` varchar(255) DEFAULT NULL,
                `stp_topology_changes` int(11) DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Exception $e) {}

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `switch_port_map` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `switch_id` int(11) NOT NULL,
                `port_number` int(11) NOT NULL,
                `port_name` varchar(50) NOT NULL,
                `mac_address` varchar(17) DEFAULT NULL,
                `ip_address` varchar(45) DEFAULT NULL,
                `connected_device` varchar(255) DEFAULT NULL,
                `last_seen` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                `vlan_id` int(11) DEFAULT NULL,
                `port_status` varchar(20) DEFAULT NULL,
                `stp_state` varchar(30) DEFAULT NULL,
                `vlan_name` varchar(100) DEFAULT NULL,
                `port_type` varchar(30) DEFAULT NULL,
                `port_speed` varchar(10) DEFAULT NULL,
                `port_alias` varchar(200) DEFAULT NULL,
                `sfp_vendor` varchar(100) DEFAULT NULL,
                `sfp_part` varchar(100) DEFAULT NULL,
                `sfp_serial` varchar(100) DEFAULT NULL,
                `sfp_rx_power` varchar(50) DEFAULT NULL,
                `sfp_tx_power` varchar(50) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_port_mac` (`switch_id`,`port_name`,`mac_address`),
                KEY `idx_ip` (`ip_address`),
                KEY `idx_mac` (`mac_address`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Exception $e) {}

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `vlans` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `vlan_id` int(11) NOT NULL,
                `name` varchar(100) NOT NULL,
                `description` text DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `vlan_id` (`vlan_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Exception $e) {}

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `sections` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `description` text DEFAULT NULL,
                `master_section` int(11) DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Exception $e) {}

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `stats_history` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `total_subnets` int(11) NOT NULL DEFAULT 0,
                `total_ips` int(11) NOT NULL DEFAULT 0,
                `active_ips` int(11) NOT NULL DEFAULT 0,
                `reserved_ips` int(11) NOT NULL DEFAULT 0,
                `offline_ips` int(11) NOT NULL DEFAULT 0,
                `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_recorded_at` (`recorded_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Exception $e) {}

    // Double check subnets exists before altering columns
    $tableExists = $db->query("SHOW TABLES LIKE 'subnets'")->rowCount() > 0;
    if (!$tableExists) {
        return;
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

    if (!in_array('conflict_mac', $ip_cols)) {
        $db->exec("ALTER TABLE ip_addresses ADD COLUMN conflict_mac varchar(20) DEFAULT NULL AFTER conflict_detected");
    }

    if (!in_array('conflict_details', $ip_cols)) {
        $db->exec("ALTER TABLE ip_addresses ADD COLUMN conflict_details varchar(255) DEFAULT NULL AFTER conflict_mac");
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

    try {
        $hasConflictIdx = $db->query("SHOW INDEX FROM ip_addresses WHERE Key_name = 'idx_conflict'")->rowCount();
        if ($hasConflictIdx === 0) {
            $db->exec("ALTER TABLE ip_addresses ADD INDEX idx_conflict (conflict_detected)");
        }
    } catch (Exception $e) {}

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
            ('masscan_enabled', '0'),
            ('masscan_rate', '1000'),
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
        $db->exec("ALTER TABLE switches ADD COLUMN IF NOT EXISTS temperature INT DEFAULT NULL");
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
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS bug_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            screenshot_path VARCHAR(255) DEFAULT NULL,
            system_info TEXT DEFAULT NULL,
            status ENUM('pending', 'resolved') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

        // Auto-migration for existing installations
        try {
            $db->exec("ALTER TABLE bug_reports ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER user_id");
        } catch (Exception $e) {}
        try {
            $db->exec("ALTER TABLE bug_reports ADD COLUMN screenshot_path VARCHAR(255) DEFAULT NULL AFTER description");
        } catch (Exception $e) {}
    } catch (Exception $e) {}

    // 15. Netwatch & Netwatch History Tables
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `netwatch` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `host` varchar(100) NOT NULL,
            `ping_interval` int(11) NOT NULL DEFAULT 60,
            `timeout` int(11) NOT NULL DEFAULT 2,
            `status` enum('up','down','intermittent','unknown') NOT NULL DEFAULT 'unknown',
            `fail_count` int(11) NOT NULL DEFAULT 0,
            `fail_threshold` int(11) NOT NULL DEFAULT 3,
            `last_up` timestamp NULL DEFAULT NULL,
            `last_down` timestamp NULL DEFAULT NULL,
            `last_check` timestamp NULL DEFAULT NULL,
            `notify` tinyint(1) NOT NULL DEFAULT 0,
            `maintenance_until` datetime DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS netwatch_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            netwatch_id INT NOT NULL,
            latency FLOAT DEFAULT 0,
            status ENUM('up', 'down', 'intermittent', 'unknown') DEFAULT 'unknown',
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_netwatch_time (netwatch_id, recorded_at),
            FOREIGN KEY (netwatch_id) REFERENCES netwatch(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    } catch (Exception $e) {}

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

    // 18. Performance Indexes & Data Retention Settings
    // Add missing indexes on history/log tables for query performance
    try {
        // audit_logs: index on created_at for date-range queries and cleanup
        $idx = $db->query("SHOW INDEX FROM audit_logs WHERE Key_name = 'idx_created_at'")->rowCount();
        if ($idx === 0) {
            $db->exec("ALTER TABLE audit_logs ADD INDEX idx_created_at (created_at)");
        }
    } catch (Exception $e) {}

    try {
        // switch_port_history: composite index for efficient per-switch time-range queries
        $idx = $db->query("SHOW INDEX FROM switch_port_history WHERE Key_name = 'idx_switch_port_time'")->rowCount();
        if ($idx === 0) {
            $db->exec("ALTER TABLE switch_port_history ADD INDEX idx_switch_port_time (switch_id, port_name, recorded_at)");
        }
    } catch (Exception $e) {}

    // Default data retention settings (days)
    $db->exec("INSERT IGNORE INTO settings (`key`, `value`) VALUES 
        ('retention_port_history', '30'),
        ('retention_health_history', '30'),
        ('retention_netwatch_history', '30'),
        ('retention_audit_logs', '90'),
        ('retention_auto_cleanup', '1'),
        ('last_db_cleanup', '0')
    ");

    // Fix ENUM for netwatch and netwatch_history to include 'intermittent'
    try {
        $db->exec("ALTER TABLE netwatch MODIFY COLUMN status ENUM('up', 'down', 'intermittent', 'unknown') NOT NULL DEFAULT 'unknown'");
        $db->exec("ALTER TABLE netwatch_history MODIFY COLUMN status ENUM('up', 'down', 'intermittent', 'unknown') DEFAULT 'unknown'");
    } catch (Exception $e) {}

    // 19. Port Monitoring & SFP Columns for Switch Port Map
    try {
        $spm_cols = $db->query("SHOW COLUMNS FROM switch_port_map")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('port_status', $spm_cols)) {
            $db->exec("ALTER TABLE switch_port_map ADD COLUMN port_status VARCHAR(20) DEFAULT NULL AFTER vlan_id");
        }
        if (!in_array('vlan_name', $spm_cols)) {
            $db->exec("ALTER TABLE switch_port_map ADD COLUMN vlan_name VARCHAR(100) DEFAULT NULL AFTER port_status");
        }
        if (!in_array('port_type', $spm_cols)) {
            $db->exec("ALTER TABLE switch_port_map ADD COLUMN port_type VARCHAR(30) DEFAULT NULL AFTER vlan_name");
        }
        if (!in_array('port_speed', $spm_cols)) {
            $db->exec("ALTER TABLE switch_port_map ADD COLUMN port_speed VARCHAR(10) DEFAULT NULL AFTER port_type");
        }
        if (!in_array('port_alias', $spm_cols)) {
            $db->exec("ALTER TABLE switch_port_map ADD COLUMN port_alias VARCHAR(200) DEFAULT NULL AFTER port_speed");
        }
        if (!in_array('sfp_vendor', $spm_cols)) {
            $db->exec("ALTER TABLE switch_port_map 
                ADD COLUMN sfp_vendor VARCHAR(100) DEFAULT NULL,
                ADD COLUMN sfp_part VARCHAR(100) DEFAULT NULL,
                ADD COLUMN sfp_serial VARCHAR(100) DEFAULT NULL,
                ADD COLUMN sfp_rx_power VARCHAR(50) DEFAULT NULL,
                ADD COLUMN sfp_tx_power VARCHAR(50) DEFAULT NULL
            ");
        }
        if (!in_array('stp_state', $spm_cols)) {
            $db->exec("ALTER TABLE switch_port_map ADD COLUMN stp_state VARCHAR(30) DEFAULT NULL AFTER port_status");
        }
    } catch (Exception $e) {}

    // 20. Switch STP, Loop Detection, & Port Count Columns
    try {
        $sw_cols = $db->query("SHOW COLUMNS FROM switches")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('total_ports', $sw_cols)) {
            $db->exec("ALTER TABLE switches ADD COLUMN total_ports INT(11) DEFAULT 0 AFTER system_info");
        }
        if (!in_array('active_ports', $sw_cols)) {
            $db->exec("ALTER TABLE switches ADD COLUMN active_ports INT(11) DEFAULT 0 AFTER total_ports");
        }
        if (!in_array('stp_enabled', $sw_cols)) {
            $db->exec("ALTER TABLE switches ADD COLUMN stp_enabled TINYINT(1) DEFAULT 0");
        }
        if (!in_array('stp_protocol', $sw_cols)) {
            $db->exec("ALTER TABLE switches ADD COLUMN stp_protocol VARCHAR(50) DEFAULT NULL");
        }
        if (!in_array('loop_detected', $sw_cols)) {
            $db->exec("ALTER TABLE switches ADD COLUMN loop_detected TINYINT(1) DEFAULT 0");
        }
        if (!in_array('loop_details', $sw_cols)) {
            $db->exec("ALTER TABLE switches ADD COLUMN loop_details VARCHAR(255) DEFAULT NULL");
        }
        if (!in_array('stp_topology_changes', $sw_cols)) {
            $db->exec("ALTER TABLE switches ADD COLUMN stp_topology_changes INT DEFAULT 0");
        }
    } catch (Exception $e) {}

    // 21. Auto-heal legacy false-positive OS conflicts caused by old Nmap single-string parser
    try {
        $db->exec("UPDATE ip_addresses 
            SET conflict_detected = 0, conflict_mac = NULL, conflict_details = NULL 
            WHERE conflict_details LIKE 'Multi-OS Discrepancy: Conflicting OS fingerprints%'
        ");
    } catch (Exception $e) {}

    // 22. Switch Port VLANs Table (Tagged / Trunk VLAN Tracking)
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `switch_port_vlans` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `switch_id` INT(11) NOT NULL,
            `port_name` VARCHAR(100) NOT NULL,
            `vlan_id` INT(11) NOT NULL,
            `vlan_name` VARCHAR(100) DEFAULT NULL,
            `is_tagged` TINYINT(1) NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_unique_port_vlan` (`switch_id`, `port_name`, `vlan_id`),
            KEY `fk_port_vlan_switch_id` (`switch_id`),
            CONSTRAINT `fk_port_vlan_switch_id` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {}

    // 23. Traffic Monitoring Tables
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS switch_port_latest_counters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            switch_id INT,
            port_name VARCHAR(100),
            last_rx_octets BIGINT UNSIGNED,
            last_tx_octets BIGINT UNSIGNED,
            last_poll TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (switch_id, port_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");

        $db->exec("CREATE TABLE IF NOT EXISTS switch_port_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            switch_id INT,
            port_name VARCHAR(100),
            rx_bps BIGINT,
            tx_bps BIGINT,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(switch_id, port_name),
            INDEX(recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
    } catch (Exception $e) {}

    // 24. Auto-heal any misaligned or ghost IP records across subnets
    try {
        if (file_exists(__DIR__ . '/network.php')) {
            require_once __DIR__ . '/network.php';
            if (function_exists('sync_and_cleanup_orphaned_ips')) {
                sync_and_cleanup_orphaned_ips($db);
            }
        }
    } catch (Exception $e) {}

    // 25. IP Conflict & MAC Flapping Events Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `ip_conflict_events` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ip_addr` VARCHAR(45) NOT NULL,
            `mac_a` VARCHAR(20) NOT NULL,
            `mac_b` VARCHAR(20) DEFAULT NULL,
            `vendor_a` VARCHAR(100) DEFAULT NULL,
            `vendor_b` VARCHAR(100) DEFAULT NULL,
            `switch_port_a` VARCHAR(100) DEFAULT NULL,
            `switch_port_b` VARCHAR(100) DEFAULT NULL,
            `event_type` ENUM('conflict', 'flapping', 'rogue_gateway') DEFAULT 'conflict',
            `details` TEXT DEFAULT NULL,
            `flap_count` INT NOT NULL DEFAULT 1,
            `status` ENUM('active', 'resolved', 'ignored') DEFAULT 'active',
            `detected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `resolved_at` TIMESTAMP NULL DEFAULT NULL,
            `resolved_by` VARCHAR(50) DEFAULT NULL,
            INDEX (`ip_addr`),
            INDEX (`status`),
            INDEX (`detected_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {}

    // 26. Auto-purge corrupted fake sequential MAC records (00:00:00:xx:xx:xx) generated by legacy Bridge MIB parsers
    try {
        $db->exec("DELETE FROM switch_port_map WHERE mac_addr LIKE '00:00:00:%'");
    } catch (Exception $e) {}

    // 27. Auto-clear legacy false-positive loop flags triggered by single-device movements
    try {
        $db->exec("
            UPDATE switches 
            SET loop_detected = 0, loop_details = NULL 
            WHERE loop_detected = 1 
              AND id NOT IN (
                  SELECT DISTINCT switch_id FROM switch_port_map WHERE stp_state = 'blocking' AND LOWER(port_status) = 'up'
              )
        ");
        $db->exec("DELETE FROM switch_port_map WHERE port_name = 'Port 0' OR port_name = '0'");
        $db->exec("UPDATE switches SET loop_detected = 0, loop_details = NULL WHERE loop_details LIKE '%Port 0%'");
        $db->exec("
            UPDATE ip_conflict_events 
            SET status = 'resolved', resolved_at = NOW(), resolved_by = 'system_anti_false_positive' 
            WHERE event_type = 'flapping' AND status = 'active' AND flap_count <= 1
        ");
    } catch (Exception $e) {}
}
}
