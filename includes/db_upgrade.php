<?php
/**
 * Auto-Migration Handler for Docker & Existing Installs
 * Checks and creates missing tables dynamically.
 */
require_once 'includes/config.php';
require_once 'includes/db.php';

echo "[$(date)] Checking for Database Upgrades...\n";

try {
    $db = get_db_connection();
    
    // Check if netwatch table exists
    $tableExists = $db->query("SHOW TABLES LIKE 'netwatch'")->rowCount() > 0;
    
    if (!$tableExists) {
        echo "Creating missing table: netwatch...\n";
        $sql = "CREATE TABLE `netwatch` (
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
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
        
        $db->exec($sql);
        echo "Table 'netwatch' created successfully.\n";
    }

    // --- Switch SNMP Port Monitoring Updates ---
    
    // Check and update `switch_port_map` columns
    $portMapCols = $db->query("SHOW COLUMNS FROM `switch_port_map` LIKE 'port_status'")->rowCount();
    if ($portMapCols === 0) {
        echo "Updating switch_port_map table with new columns...\n";
        $sql = "ALTER TABLE `switch_port_map`
                ADD COLUMN `port_status` VARCHAR(20) DEFAULT NULL AFTER `vlan_id`,
                ADD COLUMN `vlan_name` VARCHAR(100) DEFAULT NULL AFTER `port_status`,
                ADD COLUMN `port_type` VARCHAR(30) DEFAULT NULL AFTER `vlan_name`,
                ADD COLUMN `port_speed` VARCHAR(10) DEFAULT NULL AFTER `port_type`,
                ADD COLUMN `port_alias` VARCHAR(200) DEFAULT NULL AFTER `port_speed`;";
        $db->exec($sql);
        echo "switch_port_map table updated.\n";
    } else {
        // Fallback for vlan_name if port_status was already added previously
        $vlanNameCol = $db->query("SHOW COLUMNS FROM `switch_port_map` LIKE 'vlan_name'")->rowCount();
        if ($vlanNameCol === 0) {
            $db->exec("ALTER TABLE `switch_port_map` ADD COLUMN `vlan_name` VARCHAR(100) DEFAULT NULL AFTER `vlan_id`");
            echo "Added vlan_name to switch_port_map.\n";
        }
    }

    // Add SFP columns to switch_port_map if they don't exist
    $sfpVendorCol = $db->query("SHOW COLUMNS FROM `switch_port_map` LIKE 'sfp_vendor'")->rowCount();
    if ($sfpVendorCol === 0) {
        echo "Adding SFP columns to switch_port_map...\n";
        $sql = "ALTER TABLE `switch_port_map`
                ADD COLUMN `sfp_vendor` VARCHAR(100) DEFAULT NULL,
                ADD COLUMN `sfp_part` VARCHAR(100) DEFAULT NULL,
                ADD COLUMN `sfp_serial` VARCHAR(100) DEFAULT NULL,
                ADD COLUMN `sfp_rx_power` VARCHAR(50) DEFAULT NULL,
                ADD COLUMN `sfp_tx_power` VARCHAR(50) DEFAULT NULL;";
        $db->exec($sql);
        echo "SFP columns added to switch_port_map.\n";
    }

    // Check and create `switch_port_vlans` table
    $vlanTableExists = $db->query("SHOW TABLES LIKE 'switch_port_vlans'")->rowCount() > 0;
    if (!$vlanTableExists) {
        echo "Creating missing table: switch_port_vlans...\n";
        $sql = "CREATE TABLE `switch_port_vlans` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
        echo "Table 'switch_port_vlans' created successfully.\n";
    }

    // Check and update `switches` columns
    $switchCols = $db->query("SHOW COLUMNS FROM `switches` LIKE 'total_ports'")->rowCount();
    if ($switchCols === 0) {
        echo "Updating switches table with new columns...\n";
        $sql = "ALTER TABLE `switches`
                ADD COLUMN `total_ports` INT(11) DEFAULT 0 AFTER `system_info`,
                ADD COLUMN `active_ports` INT(11) DEFAULT 0 AFTER `total_ports`;";
        $db->exec($sql);
        echo "switches table updated.\n";
    }

    // --- Traffic Monitoring Tables ---
    
    $trafficTableExists = $db->query("SHOW TABLES LIKE 'switch_port_history'")->rowCount() > 0;
    if (!$trafficTableExists) {
        echo "Creating traffic monitoring tables...\n";
        $sql = "
        CREATE TABLE IF NOT EXISTS switch_port_latest_counters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            switch_id INT,
            port_name VARCHAR(100),
            last_rx_octets BIGINT UNSIGNED,
            last_tx_octets BIGINT UNSIGNED,
            last_poll TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (switch_id, port_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

        CREATE TABLE IF NOT EXISTS switch_port_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            switch_id INT,
            port_name VARCHAR(100),
            rx_bps BIGINT,
            tx_bps BIGINT,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(switch_id, port_name),
            INDEX(recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ";
        $db->exec($sql);
        echo "Traffic monitoring tables created.\n";
    }

    echo "Database schema is up to date.\n";

} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
