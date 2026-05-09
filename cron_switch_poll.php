<?php
/**
 * IPManager Pro - Switch SNMP Poller
 * Discovers MAC addresses and their physical port locations.
 * 
 * Enhanced with:
 * - Multi-OID interface name resolution (ifName → ifDescr → ifAlias)
 * - Interface status tracking (ifOperStatus)
 * - Interface type detection (ifType)
 * - Interface speed detection (ifHighSpeed / ifSpeed)
 * - Vendor-specific OID support (Alcatel-Lucent, Cisco, MikroTik)
 * - Human-readable uptime formatting
 */

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/network.php';
require_once 'includes/audit.helper.php';
require_once 'includes/vendor.helper.php';

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Switch Poller - IPManager Pro</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: 'JetBrains Mono', 'Fira Code', monospace; padding: 20px; line-height: 1.5; font-size: 13px; }
        .terminal { max-width: 1200px; margin: 0 auto; background: #1e293b; border-radius: 12px; border: 1px solid #334155; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
        .terminal-header { background: #334155; padding: 10px 15px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #475569; }
        .dot { width: 12px; height: 12px; border-radius: 50%; }
        .dot-red { background: #ef4444; } .dot-yellow { background: #f59e0b; } .dot-green { background: #10b981; }
        .terminal-body { padding: 20px; white-space: pre-wrap; word-break: break-all; max-height: 70vh; overflow-y: auto; }
        .success { color: #10b981; font-weight: bold; }
        .info { color: #38bdf8; }
        .warning { color: #fbbf24; }
        .switch-title { color: #8b5cf6; font-weight: 800; border-bottom: 1px solid #334155; padding-bottom: 5px; margin-top: 15px; display: block; }
    </style>
</head>
<body>
    <div class="terminal">
        <div class="terminal-header">
            <div class="dot dot-red"></div><div class="dot dot-yellow"></div><div class="dot dot-green"></div>
            <div style="margin-left: 10px; font-weight: bold; font-size: 11px; opacity: 0.8;">SNMP_POLLER_V2.21</div>
        </div>
        <div class="terminal-body" id="console">
<?php
set_time_limit(0);
putenv("MIBDIRS=C:/xampp/php/extras/mibs");

if (!extension_loaded('snmp')) {
    die("PHP SNMP extension is not loaded. Please enable it in php.ini.");
}

// Set SNMP Options for cleaner data
snmp_set_quick_print(1);
snmp_set_valueretrieval(SNMP_VALUE_PLAIN);

$db = get_db_connection();
$switch_id = (int)($_GET['id'] ?? 0);

$query = "SELECT * FROM switches";
if ($switch_id > 0) $query .= " WHERE id = $switch_id";
$switches = $db->query($query)->fetchAll();

/**
 * Convert SNMP timeticks to human-readable uptime string
 * SNMP sysUpTime is in hundredths of seconds (timeticks)
 */
function format_uptime_ticks($ticks) {
    $ticks = (int)$ticks;
    if ($ticks <= 0) return '-';
    
    $seconds = (int)($ticks / 100);
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($minutes > 0) $parts[] = $minutes . 'm';
    
    return implode(' ', $parts) ?: '< 1m';
}

/**
 * Walk an SNMP OID and return a map of [index => value]
 * Cleans string prefixes that some devices return.
 */
function snmp_walk_indexed($ip, $community, $oid) {
    $result = @snmp2_real_walk($ip, $community, $oid);
    if (!$result || !is_array($result)) return [];
    
    $map = [];
    foreach ($result as $full_oid => $val) {
        $parts = explode('.', $full_oid);
        $index = end($parts);
        // Clean any SNMP type prefixes
        $val = trim(str_replace(['STRING: ', 'INTEGER: ', 'Gauge32: ', 'Counter32: ', '"'], '', $val));
        $map[$index] = $val;
    }
    return $map;
}

/**
 * Detect interface type name from IANA ifType integer
 * See: https://www.iana.org/assignments/ianaiftype-mib/ianaiftype-mib
 */
function get_iftype_name($type_id) {
    $types = [
        1 => 'other',
        6 => 'ethernet',       // ethernetCsmacd
        24 => 'loopback',
        53 => 'propVirtual',   // Virtual/VLAN interface
        131 => 'tunnel',
        135 => 'l2vlan',       // Layer 2 VLAN (802.1Q)
        136 => 'l3ipvlan',
        150 => 'mplsTunnel',
        161 => 'ieee8023adLag', // Link Aggregation (LACP)
        209 => 'bridge',
    ];
    return $types[(int)$type_id] ?? 'other';
}

/**
 * Format interface speed to human-readable
 */
function format_speed($speed_mbps) {
    $speed_mbps = (int)$speed_mbps;
    if ($speed_mbps <= 0) return null;
    if ($speed_mbps >= 1000) {
        $gbps = $speed_mbps / 1000;
        // Show clean integers (1G, 10G, 40G) or one decimal (2.5G)
        return (floor($gbps) == $gbps ? (int)$gbps : round($gbps, 1)) . 'G';
    }
    return $speed_mbps . 'M';
}

/**
 * Convert raw ifDescr/ifName from Alcatel-Lucent to a friendlier port name
 * Alcatel typically returns names like "1/1", "1/1/1", "Alcatel-Lucent 1/1" etc.
 * The bridge port numbers on AOS are often 1001, 1002... = slot*1000 + port
 */
function normalize_port_name($raw_name, $bridge_port, $ifindex, $vendor) {
    // If we got a valid name from SNMP, use it
    if (!empty($raw_name) && $raw_name !== 'Port ' . $bridge_port) {
        return $raw_name;
    }
    
    // Alcatel-Lucent AOS: bridge port mapping
    // Port IDs typically: 1001 = 1/1, 1002 = 1/2, ..., 1024 = 1/24
    // For chassis: 2001 = 2/1, etc.
    if (stripos($vendor, 'Alcatel') !== false || stripos($vendor, 'Nokia') !== false || stripos($vendor, 'AOS') !== false) {
        $bp = (int)$bridge_port;
        if ($bp > 1000) {
            $slot = floor($bp / 1000);
            $port = $bp % 1000;
            return "$slot/$port";
        }
    }
    
    return "Port $bridge_port";
}

foreach ($switches as $switch) {
    echo "Polling Switch: {$switch['name']} ({$switch['ip_addr']})...\n";
    $ip = $switch['ip_addr'];
    $community = $switch['community'];
    
    // --- Phase 0: System Info & Health ---
    $sys_descr = @snmp2_get($ip, $community, ".1.3.6.1.2.1.1.1.0");
    $sys_uptime = @snmp2_get($ip, $community, ".1.3.6.1.2.1.1.3.0");
    
    $model = "Generic";
    $cpu = 0;
    $mem = 0;
    $system_info = trim((string)$sys_descr);
    $uptime_raw = trim((string)$sys_uptime);
    $uptime_str = format_uptime_ticks($uptime_raw);

    // Smart Vendor Detection for CPU/RAM (30+ vendors supported)
    $vendor_result = VendorDetector::detect($ip, $community, $system_info);
    $model = $vendor_result['model'];
    $cpu = $vendor_result['cpu'];
    $mem = $vendor_result['mem'];
    echo "  Detected: $model (CPU: {$cpu}%, MEM: {$mem}%)\n";

    // Safety Bounds
    $cpu = min(100, max(0, (int)$cpu));
    $mem = min(100, max(0, (int)$mem));
    
    // Save System Stats
    $db->prepare("UPDATE switches SET model = ?, uptime = ?, cpu_usage = ?, memory_usage = ?, system_info = ? WHERE id = ?")
       ->execute([$model, $uptime_str, $cpu, $mem, $system_info, $switch['id']]);

    // Save to History (for graphs) - keep last 24h only
    $db->prepare("INSERT INTO switch_health_history (switch_id, cpu_usage, memory_usage) VALUES (?, ?, ?)")
       ->execute([$switch['id'], $cpu, $mem]);
    $db->prepare("DELETE FROM switch_health_history WHERE switch_id = ? AND recorded_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)")
       ->execute([$switch['id']]);

    // --- Phase 1: Interface Discovery & Port Mapping ---
    echo "  Phase 1: Discovering interfaces...\n";
    
    // 1. Get Bridge Port → ifIndex mapping
    // OID: .1.3.6.1.2.1.17.1.4.1.2 (dot1dBasePortIfIndex)
    $port_to_ifindex = @snmprealwalk($ip, $community, ".1.3.6.1.2.1.17.1.4.1.2");
    $ifindex_map = [];
    if ($port_to_ifindex && is_array($port_to_ifindex)) {
        foreach ($port_to_ifindex as $oid => $val) {
            $parts = explode('.', $oid);
            $port_num = end($parts);
            $ifindex_map[$port_num] = trim(str_replace('INTEGER: ', '', $val));
        }
    }

    if (count($ifindex_map) >= 0) { // Keep block active even if initial walk is empty

        // Cisco Fix: Bridge-to-ifIndex mapping is VLAN-specific.
        // We need to poll this mapping for every VLAN to ensure all ports are mapped.
        if (stripos($system_info, 'Cisco') !== false) {
            echo "  Cisco detected: building multi-VLAN port map...\n";
            $vlan_list_raw = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.9.9.46.1.3.1.1.2"); // vtpVlanState
            $cisco_vlan_ids = !empty($vlan_list_raw) ? array_keys($vlan_list_raw) : [1];
            
            foreach ($cisco_vlan_ids as $v_id) {
                $v_id = (int)$v_id;
                if ($v_id >= 1002 && $v_id <= 1005) continue;
                
                $v_comm = $community . '@' . $v_id;
                $v_map = @snmprealwalk($ip, $v_comm, ".1.3.6.1.2.1.17.1.4.1.2");
                if ($v_map && is_array($v_map)) {
                    foreach ($v_map as $v_oid => $v_val) {
                        $v_parts = explode('.', $v_oid);
                        $v_port_num = end($v_parts);
                        $ifindex_map[$v_port_num] = trim(str_replace('INTEGER: ', '', $v_val));
                    }
                }
            }
            echo "    Consolidated port map: " . count($ifindex_map) . " entries.\n";
        }
        
        // 2. Get interface names using multiple OID sources for maximum compatibility
        // Priority: ifName (.1.3.6.1.2.1.31.1.1.1.1) → ifDescr (.1.3.6.1.2.1.2.2.1.2) → ifAlias (.1.3.6.1.2.1.31.1.1.1.18)
        
        echo "  Fetching interface names (ifName)...\n";
        $name_map_ifname = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.31.1.1.1.1");
        echo "    ifName entries: " . count($name_map_ifname) . "\n";
        
        echo "  Fetching interface descriptions (ifDescr)...\n";
        $name_map_ifdescr = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.2");
        echo "    ifDescr entries: " . count($name_map_ifdescr) . "\n";
        
        echo "  Fetching interface aliases (ifAlias)...\n";
        $name_map_ifalias = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.31.1.1.1.18");
        echo "    ifAlias entries: " . count($name_map_ifalias) . "\n";
        
        // 3. Get interface operational status
        // OID: .1.3.6.1.2.1.2.2.1.8 (ifOperStatus) — 1=up, 2=down, 3=testing, ...
        echo "  Fetching interface status (ifOperStatus)...\n";
        $oper_status_map = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.8");
        echo "    ifOperStatus entries: " . count($oper_status_map) . "\n";
        
        // 4. Get interface types
        // OID: .1.3.6.1.2.1.2.2.1.3 (ifType)
        $iftype_map = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.3");
        
        // 4.1 Get Port Vlan ID (PVID) - Crucial for non-tagged/access ports
        // Standard: dot1qPortPvid (.1.3.6.1.2.1.17.7.1.4.5.1.1)
        // Alcatel: alaVlanPortVlanId (.1.3.6.1.4.1.6486.800.1.2.1.11.1.1.1.2)
        echo "  Fetching Port PVIDs...\n";
        $pvid_map = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.17.7.1.4.5.1.1");
        if (empty($pvid_map) && (stripos($system_info, 'Alcatel') !== false || stripos($model, 'Alcatel') !== false)) {
            $pvid_map = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.11.1.1.1.2");
        }
        
        // 4.5 Get VLAN Names
        // Standard OID: dot1qVlanStaticName (.1.3.6.1.2.1.17.7.1.4.3.1.1)
        echo "  Fetching VLAN names...\n";
        $vlan_names = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.17.7.1.4.3.1.1");
        if (empty($vlan_names)) {
            if (stripos($system_info, 'Cisco') !== false) {
                // Cisco VTP VLAN names (.1.3.6.1.4.1.9.9.46.1.3.1.1.4)
                // Note: The OID structure is .1.3.6.1.4.1.9.9.46.1.3.1.1.4.1.X (where X is VLAN ID)
                $vlan_names = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.9.9.46.1.3.1.1.4.1");
            } elseif (stripos($system_info, 'Alcatel') !== false || stripos($system_info, 'OmniSwitch') !== false || stripos($model, 'Alcatel') !== false) {
                // Alcatel alaVlanName (.1.3.6.1.4.1.6486.800.1.2.1.11.1.1.1.2)
                echo "    Trying Alcatel-specific VLAN names...\n";
                $vlan_names = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.11.1.1.1.2");
            }
        }
        
        // 5. Get interface speed (ifHighSpeed in Mbps, fallback ifSpeed in bps)
        $ifhighspeed_map = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.31.1.1.1.15");
        $ifspeed_map = [];
        if (empty($ifhighspeed_map)) {
            $ifspeed_raw = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.5");
            foreach ($ifspeed_raw as $idx => $bps) {
                $ifspeed_map[$idx] = round((int)$bps / 1000000); // Convert bps to Mbps
            }
        } else {
            $ifspeed_map = $ifhighspeed_map;
        }

        // Build consolidated name map with smart fallback
        $name_map = [];
        foreach ($ifindex_map as $bridge_port => $ifindex) {
            // Priority: ifName (short) → ifDescr (longer) → ifAlias (custom description)
            $if_name = $name_map_ifname[$ifindex] ?? null;
            $if_descr = $name_map_ifdescr[$ifindex] ?? null;
            $if_alias = $name_map_ifalias[$ifindex] ?? null;
            
            // Use the best available name
            if (!empty($if_name) && strlen($if_name) > 0) {
                $name_map[$ifindex] = $if_name;
            } elseif (!empty($if_descr) && strlen($if_descr) > 0) {
                $name_map[$ifindex] = $if_descr;
            } elseif (!empty($if_alias) && strlen($if_alias) > 0) {
                $name_map[$ifindex] = $if_alias;
            }
            // If none found, will use normalized fallback later
        }
        
        // 6. Get FDB table (MAC to Bridge Port + VLAN)
        // Detect if this is an Alcatel switch (flat bridge mode)
        $is_alcatel = (stripos($system_info, 'Alcatel') !== false || stripos($model, 'Alcatel') !== false || stripos($system_info, 'OmniSwitch') !== false);
        
        echo "  Scanning FDB Tables...\n";
        
        // Primary: dot1qTpFdbPort (.1.3.6.1.2.1.17.7.1.2.2.1.2) - VLAN Aware (802.1Q)
        $fdb_table = @snmprealwalk($ip, $community, ".1.3.6.1.2.1.17.7.1.2.2.1.2");
        $is_vlan_aware = ($fdb_table !== false && count($fdb_table) > 0);
        
        if ($is_alcatel && $is_vlan_aware) {
            echo "    Alcatel flat bridge mode: will use PVID for VLAN resolution.\n";
            echo "    PVID map has " . count($pvid_map) . " entries.\n";
        }
        
        // Fallback: dot1dTpFdbPort (.1.3.6.1.2.1.17.4.3.1.2) - Generic (no VLAN)
        if (!$is_vlan_aware) {
            echo "    dot1q empty, falling back to generic bridge table...\n";
            $fdb_table = @snmprealwalk($ip, $community, ".1.3.6.1.2.1.17.4.3.1.2");
        }
        
        // Cisco IOS per-VLAN community polling fallback
        // Some Cisco IOS switches require community@vlan to access FDB per VLAN context
        if ((!$fdb_table || count($fdb_table) === 0) && stripos($system_info, 'Cisco') !== false) {
            echo "  Cisco detected: trying per-VLAN community polling...\n";
            $vlan_list = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.9.9.46.1.3.1.1.2"); // vtpVlanState
            if (empty($vlan_list)) {
                // Fallback: try dot1qVlanStaticRowStatus
                $vlan_list = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.17.7.1.4.3.1.5");
            }
            
            $fdb_table = [];
            $cisco_vlans = !empty($vlan_list) ? array_keys($vlan_list) : [1]; // Default VLAN 1
            foreach ($cisco_vlans as $vlan_num) {
                $vlan_num = (int)$vlan_num;
                if ($vlan_num >= 1002 && $vlan_num <= 1005) continue; // Skip reserved VLANs
                
                $vlan_community = $community . '@' . $vlan_num;
                $vlan_fdb = @snmprealwalk($ip, $vlan_community, ".1.3.6.1.2.1.17.4.3.1.2");
                if ($vlan_fdb && is_array($vlan_fdb)) {
                    // Tag each entry with its VLAN for later extraction
                    foreach ($vlan_fdb as $oid => $val) {
                        $fdb_table[$oid . '.__vlan__.' . $vlan_num] = $val;
                    }
                    echo "    VLAN $vlan_num: " . count($vlan_fdb) . " entries\n";
                }
            }
            $is_vlan_aware = false; // Use dot1d parsing but with tagged VLANs
        }

        if ($fdb_table) {
            $discovered_count = 0;
            foreach ($fdb_table as $oid => $val) {
                // Check for Cisco per-VLAN tagged entries
                $cisco_vlan_tag = null;
                if (strpos($oid, '.__vlan__.') !== false) {
                    $tag_parts = explode('.__vlan__.', $oid);
                    $oid = $tag_parts[0];
                    $cisco_vlan_tag = (int)$tag_parts[1];
                }
                
                $parts = explode('.', $oid);
                
                if ($is_vlan_aware && !$cisco_vlan_tag) {
                    // Structure: ...1.2.2.1.2.<VLAN>.<MAC_6_PARTS>
                    $vlan_id = (int)$parts[count($parts) - 7];
                    $mac_dec = array_slice($parts, -6);
                } else {
                    // Structure: ...4.3.1.2.<MAC_6_PARTS>
                    $vlan_id = $cisco_vlan_tag ?: null;
                    $mac_dec = array_slice($parts, -6);
                }

                $mac_hex = [];
                foreach ($mac_dec as $dec) {
                    $mac_hex[] = str_pad(dechex((int)$dec), 2, '0', STR_PAD_LEFT);
                }
                $mac_addr = strtoupper(implode(':', $mac_hex));
                
                // Validate MAC: must be 17 chars (AA:BB:CC:DD:EE:FF) and not broadcast/multicast
                if (strlen($mac_addr) !== 17 || $mac_addr === 'FF:FF:FF:FF:FF:FF' || $mac_addr === '00:00:00:00:00:00') {
                    continue;
                }
                
                $bridge_port = trim(str_replace('INTEGER: ', '', $val));
                
                // Smart VLAN Resolution using PVID
                // Alcatel flat bridge: dot1q always reports VLAN 1, use PVID instead
                // Other vendors: use PVID only when VLAN is missing
                if ($is_alcatel) {
                    // Alcatel PVID is indexed by bridge port (1001, 1002, etc.)
                    $pvid_val = $pvid_map[$bridge_port] ?? null;
                    if ($pvid_val && (int)$pvid_val > 0) {
                        $vlan_id = (int)$pvid_val;
                    }
                } elseif (!$vlan_id) {
                    $vlan_id = $pvid_map[$bridge_port] ?? 1;
                }

                // For Cisco per-VLAN polling, bridge-to-ifIndex might need VLAN context too
                $ifindex = $ifindex_map[$bridge_port] ?? null;
                if (!$ifindex && $cisco_vlan_tag) {
                    // Try fetching bridge port mapping with VLAN community
                    $vlan_ifindex = @snmp2_get($ip, $community . '@' . $cisco_vlan_tag, ".1.3.6.1.2.1.17.1.4.1.2." . $bridge_port);
                    if ($vlan_ifindex !== false) {
                        $ifindex = trim(str_replace('INTEGER: ', '', $vlan_ifindex));
                        $ifindex_map[$bridge_port] = $ifindex; // Cache for future lookups
                    }
                }
                
                // Fallback: If still no ifIndex, assume ifIndex = bridge_port
                // Many switches (like TP-Link, HP, Huawei) map bridge port directly to ifIndex 1:1
                if (!$ifindex) {
                    $ifindex = $bridge_port;
                }
                
                // Smart port name resolution with vendor-aware fallback
                $raw_name = $name_map[$ifindex] ?? null;
                $port_name = normalize_port_name($raw_name, $bridge_port, $ifindex, $system_info);
                
                // Get interface status for this port
                $port_status = null;
                if ($ifindex && isset($oper_status_map[$ifindex])) {
                    $status_int = (int)$oper_status_map[$ifindex];
                    $port_status = match($status_int) {
                        1 => 'up',
                        2 => 'down',
                        3 => 'testing',
                        5 => 'dormant',
                        6 => 'notPresent',
                        7 => 'lowerLayerDown',
                        default => 'unknown'
                    };
                }
                
                // Get interface type
                $port_type = null;
                if ($ifindex && isset($iftype_map[$ifindex])) {
                    $port_type = get_iftype_name($iftype_map[$ifindex]);
                }
                
                // Get interface speed
                $port_speed = null;
                if ($ifindex && isset($ifspeed_map[$ifindex])) {
                    $port_speed = format_speed($ifspeed_map[$ifindex]);
                }
                
                // Resolve VLAN Name
                $vlan_name = null;
                if ($vlan_id && isset($vlan_names[$vlan_id])) {
                    $vlan_name = trim($vlan_names[$vlan_id], '" ');
                }
                
                // Build alias info (stored as description for additional context)
                $port_alias = $name_map_ifalias[$ifindex] ?? null;
                
                if ($mac_addr && $port_name) {
                    $stmt = $db->prepare("INSERT INTO switch_port_map (mac_addr, switch_id, port_name, vlan_id, vlan_name, port_status, port_type, port_speed, port_alias) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE port_name = VALUES(port_name), vlan_id = VALUES(vlan_id), vlan_name = VALUES(vlan_name), port_status = VALUES(port_status), port_type = VALUES(port_type), port_speed = VALUES(port_speed), port_alias = VALUES(port_alias), updated_at = CURRENT_TIMESTAMP");
                    $stmt->execute([$mac_addr, $switch['id'], $port_name, $vlan_id, $vlan_name, $port_status, $port_type, $port_speed, $port_alias]);
                    $discovered_count++;
                }
            }
            
            $db->prepare("UPDATE switches SET last_poll = CURRENT_TIMESTAMP WHERE id = ?")->execute([$switch['id']]);
            echo "Discovered $discovered_count MAC-Port mappings (VLAN ".($is_vlan_aware ? "ON" : "OFF").") on {$switch['name']}.\n";
            AuditLogHelper::log("poll_switch", "switch", $switch['id'], "Discovered $discovered_count mappings on {$switch['name']}");
        }
    } else {
        echo "Note: Bridge port mapping (L2) not supported on {$ip}. Skipping L2, proceeding to L3 ARP...\n";
    }

    // --- Phase 2: Standalone Interface Inventory ---
    // Even if FDB is empty, discover all physical interfaces for visibility
    echo "  Phase 2: Interface inventory...\n";
    
    $if_names_all = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.31.1.1.1.1");
    if (empty($if_names_all)) {
        $if_names_all = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.2");
    }
    $if_oper_all = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.8");
    $if_type_all = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.3");
    
    // Fetch traffic counters (HC In/Out) for speed calculation
    $in_octets = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.31.1.1.1.6");
    $out_octets = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.31.1.1.1.10");
    if (empty($in_octets)) {
        $in_octets = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.10");
        $out_octets = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.16");
    }
    $if_speed_all = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.31.1.1.1.15");
    if (empty($if_speed_all)) {
        $if_speed_raw = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.2.2.1.5");
        foreach ($if_speed_raw as $idx => $bps) {
            $if_speed_all[$idx] = round((int)$bps / 1000000);
        }
    }

    // Count total physical interfaces (ethernet type=6)
    $phys_interfaces = 0;
    $up_interfaces = 0;
    foreach ($if_type_all as $ifidx => $type_val) {
        if ((int)$type_val === 6) { // ethernetCsmacd
            $phys_interfaces++;
            if (isset($if_oper_all[$ifidx]) && (int)$if_oper_all[$ifidx] === 1) {
                $up_interfaces++;
            }
        }
    }
    
    // Save interface counts
    $db->prepare("UPDATE switches SET total_ports = ?, active_ports = ? WHERE id = ?")
       ->execute([$phys_interfaces, $up_interfaces, $switch['id']]);

    // Ensure all physical interfaces are in the port map for visibility
    foreach ($if_names_all as $ifidx => $name) {
        if (isset($if_type_all[$ifidx]) && (int)$if_type_all[$ifidx] === 6) { // ethernetCsmacd
            $name = trim(str_replace('"', '', $name));
            $status = 'unknown';
            if (isset($if_oper_all[$ifidx])) {
                $status = ((int)$if_oper_all[$ifidx] === 1) ? 'up' : 'down';
            }
            $speed = isset($if_speed_all[$ifidx]) ? format_speed($if_speed_all[$ifidx]) : null;
            $type = get_iftype_name($if_type_all[$ifidx]);

            // Insert placeholder entry for the port itself (without MAC)
            // Use a specific dummy MAC or just let the MAC be NULL/Empty? 
            // Better to use an empty MAC entry so it appears in the list even if no devices found
            $db->prepare("INSERT IGNORE INTO switch_port_map (mac_addr, switch_id, port_name, port_status, port_type, port_speed) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute(['', $switch['id'], $name, $status, $type, $speed]);

            // --- Traffic BPS Calculation ---
            if (isset($in_octets[$ifidx]) && isset($out_octets[$ifidx])) {
                $curr_in = (float)$in_octets[$ifidx];
                $curr_out = (float)$out_octets[$ifidx];

                // Get previous values
                $stmt_prev = $db->prepare("SELECT last_rx_octets, last_tx_octets, UNIX_TIMESTAMP(last_poll) as last_time FROM switch_port_latest_counters WHERE switch_id = ? AND port_name = ?");
                $stmt_prev->execute([$switch['id'], $name]);
                $prev = $stmt_prev->fetch();

                if ($prev) {
                    $time_diff = time() - (int)$prev['last_time'];
                    if ($time_diff > 0) {
                        // Calculate Delta (Handle counter wrap-around roughly)
                        $delta_in = ($curr_in >= (float)$prev['last_rx_octets']) ? ($curr_in - (float)$prev['last_rx_octets']) : 0;
                        $delta_out = ($curr_out >= (float)$prev['last_tx_octets']) ? ($curr_out - (float)$prev['last_tx_octets']) : 0;

                        // Bytes to Bits: * 8
                        $rx_bps = ($delta_in * 8) / $time_diff;
                        $tx_bps = ($delta_out * 8) / $time_diff;

                        // Store history
                        $db->prepare("INSERT INTO switch_port_history (switch_id, port_name, rx_bps, tx_bps) VALUES (?, ?, ?, ?)")
                           ->execute([$switch['id'], $name, (int)$rx_bps, (int)$tx_bps]);
                    }
                }

                // Update latest counters
                $db->prepare("INSERT INTO switch_port_latest_counters (switch_id, port_name, last_rx_octets, last_tx_octets, last_poll) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE last_rx_octets = ?, last_tx_octets = ?, last_poll = CURRENT_TIMESTAMP")
                   ->execute([$switch['id'], $name, $curr_in, $curr_out, $curr_in, $curr_out]);
            }
        }
    }
    echo "  Interface inventory: $up_interfaces/$phys_interfaces ports up.\n";

    // --- Phase 3: L3 ARP Table Polling (ARP Discovery) ---
    echo "  Phase 3: L3 ARP Discovery...\n";
    $arp_count = 0;
    
    // Strategy: Try multiple tables until we find one with data
    $arp_tables = [
        ['name' => 'ipNetToMediaTable (Standard)', 'oid' => ".1.3.6.1.2.1.4.22.1.2"],
        ['name' => 'ipNetToPhysicalTable (Modern)', 'oid' => ".1.3.6.1.2.1.4.35.1.4"],
        ['name' => 'Alcatel-Specific Table',        'oid' => ".1.3.6.1.4.1.6486.800.1.2.1.25.1.1.1.2"]
    ];

    foreach ($arp_tables as $table) {
        echo "    Trying {$table['name']}...\n";
        $arp_raw_macs = @snmp2_real_walk($ip, $community, $table['oid']);
        
        if ($arp_raw_macs && count($arp_raw_macs) > 0) {
            echo "    Found " . count($arp_raw_macs) . " raw entries.\n";
            foreach ($arp_raw_macs as $oid => $mac_bin) {
                $parts = explode('.', $oid);
                
                // IP Extraction Logic:
                // Standard: .ifIndex.ip.ip.ip.ip (Last 4)
                // Modern:   .ifIndex.type.len.ip.ip.ip.ip (Last 4 if IPv4)
                $target_ip = implode('.', array_slice($parts, -4));
                
                if (!filter_var($target_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    // Try searching for a valid IP pattern in the OID if last 4 failed
                    foreach (range(4, 16) as $len) {
                        $possible_ip = implode('.', array_slice($parts, -$len, 4));
                        if (filter_var($possible_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $target_ip = $possible_ip;
                            break;
                        }
                    }
                }

                if (!filter_var($target_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;

            // 1. Raw binary (6 bytes)                    → bin2hex
            // 2. Hex-string with spaces ("AA BB CC...")  → strip spaces
            // 3. Hex-string with colons ("AA:BB:CC...")   → strip colons
            // 4. Quoted strings ('"XX XX..."')            → trim quotes first
            $mac_raw = trim($mac_bin, '" ');
            $target_mac = null;
            
            if (strlen($mac_raw) === 6) {
                // Raw binary: 6 bytes → convert to hex
                $target_mac = strtoupper(implode(':', str_split(bin2hex($mac_raw), 2)));
            } elseif (preg_match('/^([0-9A-Fa-f]{2}[: ]){5}[0-9A-Fa-f]{2}$/', $mac_raw)) {
                // Already formatted hex-string with colons or spaces
                $target_mac = strtoupper(str_replace(' ', ':', $mac_raw));
            } elseif (preg_match('/^[0-9A-Fa-f]{12}$/', $mac_raw)) {
                // Plain 12 hex characters without separators
                $target_mac = strtoupper(implode(':', str_split($mac_raw, 2)));
            }
            
            // Validate MAC format (AA:BB:CC:DD:EE:FF = 17 chars)
            if ($target_mac && strlen($target_mac) === 17 
                && $target_mac !== 'FF:FF:FF:FF:FF:FF' 
                && $target_mac !== '00:00:00:00:00:00') {
                
                // Resolve Subnet ID (Mandatory for Foreign Key)
                $target_subnet_id = find_subnet_for_ip($db, $target_ip);

                if ($target_subnet_id) {
                    // Save to IPAM Discovery Table
                    $stmt = $db->prepare("
                        INSERT INTO ip_addresses (subnet_id, ip_addr, mac_addr, state, last_seen, data_sources, confidence_score) 
                        VALUES (?, ?, ?, 'active', CURRENT_TIMESTAMP, 'snmp_arp', 80)
                        ON DUPLICATE KEY UPDATE 
                            mac_addr = VALUES(mac_addr),
                            state = 'active',
                            last_seen = CURRENT_TIMESTAMP,
                            data_sources = IF(data_sources NOT LIKE '%snmp_arp%', CONCAT(data_sources, ',snmp_arp'), data_sources)
                    ");
                    $stmt->execute([$target_subnet_id, $target_ip, $target_mac]);
                    $arp_count++;
                }
            }
        }
        if ($arp_count > 0) {
            echo "    Successfully discovered $arp_count ARP entries from {$table['name']}.\n";
            break; // Found data, stop trying other tables
        }
    }
    }
}
?>

        </div>
    </div>
    
    <div style="text-align: center; margin-top: 2rem;">
        <p style="color: #64748b; font-size: 0.8rem;">Task finished. Redirecting to Management Console...</p>
        <a href="switches.php" style="color: #38bdf8; text-decoration: none; font-weight: bold; border: 1px solid #38bdf8; padding: 10px 20px; border-radius: 8px; display: inline-block; margin-top: 10px;">Return Now</a>
    </div>

    <script>
        // Auto scroll to bottom as logs come in
        const consoleObj = document.getElementById('console');
        consoleObj.scrollTop = consoleObj.scrollHeight;
        
        // Immediate redirect if no errors
        setTimeout(() => {
            window.location.href = 'switches.php?message=Poll completed';
        }, 1500);
    </script>
</body>
</html>
<?php
ob_end_flush();
