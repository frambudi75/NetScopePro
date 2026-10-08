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

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/network.php';
require_once __DIR__ . '/includes/audit.helper.php';
require_once __DIR__ . '/includes/vendor.helper.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/loop.evidence.php';

$is_cli = (php_sapi_name() === 'cli');

// Security: Allow CLI, session-based admin auth, or secret key
if (!$is_cli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $key = $_GET['key'] ?? '';
    $secret = Settings::get('cron_key', 'your-secret-key-change-me');
    
    if (!isset($_SESSION['user_id']) || !is_admin()) {
        if ($key !== $secret) {
            header('HTTP/1.1 403 Forbidden');
            die("Unauthorized. Run via CLI, log in as admin, or provide a valid key.");
        }
    }
    // Release session lock immediately to prevent blocking other browser tabs
    session_write_close();
}

if (!$is_cli) {
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
} // end if (!$is_cli)

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
function snmp_walk_indexed($ip, $community, $oid, $timeout = 1500000, $retries = 1) {
    $result = @snmp2_real_walk($ip, $community, $oid, $timeout, $retries);
    if (!$result || !is_array($result)) return [];
    
    $map = [];
    foreach ($result as $full_oid => $val) {
        $parts = explode('.', $full_oid);
        $index = end($parts);
        // Clean SNMP type prefixes (covers standard + extended types)
        $val = preg_replace('/^(?:STRING|INTEGER|Gauge32|Gauge64|Counter32|Counter64|Timeticks|Opaque|OID|IpAddress|Hex-STRING):\s*/i', '', $val);
        $val = trim($val, '" ');
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
        if ($bp >= 1 && $bp <= 64) {
            return "1/$bp";
        }
    }
    
    return "Port $bridge_port";
}

foreach ($switches as $switch) {
    try {
        echo "Polling Switch: {$switch['name']} ({$switch['ip_addr']})...\n";
        $ip = $switch['ip_addr'];
        $community = $switch['community'];
        
        // Fast reachability check (1.0s timeout, 1 retry) to prevent freezing on dead switches
        $sys_descr = @snmp2_get($ip, $community, ".1.3.6.1.2.1.1.1.0", 1000000, 1);
        if ($sys_descr === false || $sys_descr === "") {
            echo "  ⚠️ Switch is UNREACHABLE or SNMP timed out (1s). Skipping detailed walk.\n";
            $db->prepare("UPDATE switches SET last_poll = NOW() WHERE id = ?")->execute([$switch['id']]);
            continue;
        }

        // Reset per-switch STP and Loop trackers to prevent cross-switch leakage
        $stp_blocked_ports = [];

        // Clear existing port VLAN associations for this switch before polling
        $db->prepare("DELETE FROM switch_port_vlans WHERE switch_id = ?")->execute([$switch['id']]);

        // --- Phase 0: System Info & Health ---
        $sys_uptime = @snmp2_get($ip, $community, ".1.3.6.1.2.1.1.3.0", 1000000, 1);
        
        $model = "Generic";
        $cpu = 0;
        $mem = 0;
        $system_info = trim((string)$sys_descr);
        $uptime_raw = trim((string)$sys_uptime);
        $uptime_str = format_uptime_ticks($uptime_raw);

        // Smart Vendor Detection for CPU/RAM/Temperature (30+ vendors supported)
        $vendor_result = VendorDetector::detect($ip, $community, $system_info);
        $model = $vendor_result['model'];
        $cpu = $vendor_result['cpu'];
        $mem = $vendor_result['mem'];
        $temp = isset($vendor_result['temp']) ? (int)$vendor_result['temp'] : null;
        if ($temp !== null && ($temp < -20 || $temp > 150)) {
            $temp = null;
        }
        $temp_str = ($temp !== null) ? ", TEMP: {$temp}°C" : "";
        echo "  Detected: $model (CPU: {$cpu}%, MEM: {$mem}%{$temp_str})\n";

        // Safety Bounds
        $cpu = min(100, max(0, (int)$cpu));
        $mem = min(100, max(0, (int)$mem));
        
        // Save System Stats
        $db->prepare("UPDATE switches SET model = ?, uptime = ?, cpu_usage = ?, memory_usage = ?, temperature = ?, system_info = ? WHERE id = ?")
           ->execute([$model, $uptime_str, $cpu, $mem, $temp, $system_info, $switch['id']]);

        // Save to History (for graphs) - cleanup handled by retention policy
        $db->prepare("INSERT INTO switch_health_history (switch_id, cpu_usage, memory_usage) VALUES (?, ?, ?)")
           ->execute([$switch['id'], $cpu, $mem]);
        $health_retention = max(1, (int)Settings::get('retention_health_history', 30));
        $db->prepare("DELETE FROM switch_health_history WHERE switch_id = ? AND recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)")
           ->execute([$switch['id'], $health_retention]);

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
            if (empty($pvid_map)) {
                $pvid_map = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.11.1.1.1.2");
            }
        }

        // 4.2 Get Tagged VLANs per port
        // Standard OID: dot1qVlanStaticTaggedPorts (.1.3.6.1.2.1.17.7.1.4.3.1.2)
        // Also: dot1qVlanCurrentEgressPorts (.1.3.6.1.2.1.17.7.1.4.2.1.4)
        echo "  Fetching Tagged VLANs per port...\n";
        $tagged_ports_vlan = @snmprealwalk($ip, $community, ".1.3.6.1.2.1.17.7.1.4.3.1.2");
        if (!$tagged_ports_vlan) {
            $tagged_ports_vlan = @snmprealwalk($ip, $community, ".1.3.6.1.2.1.17.7.1.4.2.1.4");
        }
        $tagged_vlans_per_ifindex = [];
        if ($tagged_ports_vlan && is_array($tagged_ports_vlan)) {
            foreach ($tagged_ports_vlan as $oid_full => $value_mask) {
                // OID structure: OID.vlan_id or OID.vlan_id.X
                $oid_parts = explode('.', $oid_full);
                $vlan_id = (int)$oid_parts[count($oid_parts) - 2];
                if ($vlan_id <= 0) {
                    $vlan_id = (int)end($oid_parts);
                }
                if ($vlan_id <= 0) continue;

                // Clean SNMP prefixes and non-hex chars
                $cleaned_mask = preg_replace('/^(?:STRING|Hex-STRING|OctetString|Opaque):\s*/i', '', $value_mask);
                $cleaned_mask = trim($cleaned_mask, '"\' ');
                $cleaned_mask = preg_replace('/[^0-9a-fA-F]/', '', $cleaned_mask);

                if (!empty($cleaned_mask)) {
                    // Standard 802.1Q PortList is an octet string where each byte represents 8 ports in MSB order
                    $hex_bytes = str_split($cleaned_mask, 2);
                    $port_num = 1;
                    foreach ($hex_bytes as $hb) {
                        $byte_val = hexdec($hb);
                        for ($b = 7; $b >= 0; $b--) {
                            if (($byte_val >> $b) & 1) {
                                $if_idx = $ifindex_map[$port_num] ?? $port_num;
                                $tagged_vlans_per_ifindex[$if_idx][] = $vlan_id;
                            }
                            $port_num++;
                        }
                    }
                }
            }
        }

        // Alcatel OmniSwitch (AOS) Enterprise MIB for Tagged VLANs:
        // vpaType (.1.3.6.1.4.1.6486.800.1.2.1.3.1.1.2.1.1.3 or 801): 1=default/untagged, 2=tagged (802.1Q)
        // Also fallback alaVlanPortType (.1.3.6.1.4.1.6486.800.1.2.1.11.1.2.1.3 or 801)
        $is_alcatel = (stripos($system_info, 'Alcatel') !== false || stripos($system_info, 'OmniSwitch') !== false || stripos($model, 'Alcatel') !== false);
        if ($is_alcatel || empty($tagged_vlans_per_ifindex)) {
            $alcatel_vlan_ports = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.3.1.1.2.1.1.3");
            if (empty($alcatel_vlan_ports)) {
                $alcatel_vlan_ports = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.3.1.1.2.1.1.3");
            }
            if (empty($alcatel_vlan_ports)) {
                $alcatel_vlan_ports = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.11.1.2.1.3");
            }
            if (empty($alcatel_vlan_ports)) {
                $alcatel_vlan_ports = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.11.1.2.1.3");
            }
            if ($alcatel_vlan_ports && is_array($alcatel_vlan_ports)) {
                echo "  Alcatel VLAN-Port table detected: parsing 802.1Q tagged ports...\n";
                foreach ($alcatel_vlan_ports as $oid => $val) {
                    $type_int = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                    // 2 = tagged (802.1Q trunk)
                    if ($type_int === 2) {
                        $parts = explode('.', $oid);
                        $p_last = (int)end($parts);
                        $p_prev = (int)$parts[count($parts) - 2];
                        
                        // Robust index resolution: either (vlan.ifindex) or (ifindex.vlan)
                        if (isset($ifindex_map[$p_last]) || isset($name_map_ifname[$p_last]) || isset($oper_status_map[$p_last])) {
                            $if_idx = $p_last;
                            $vlan_id = $p_prev;
                        } elseif (isset($ifindex_map[$p_prev]) || isset($name_map_ifname[$p_prev]) || isset($oper_status_map[$p_prev])) {
                            $if_idx = $p_prev;
                            $vlan_id = $p_last;
                        } else {
                            $if_idx = $p_last;
                            $vlan_id = $p_prev;
                        }

                        if ($if_idx && $vlan_id) {
                            $tagged_vlans_per_ifindex[$if_idx][] = $vlan_id;
                        }
                    }
                }
            }
        }

        // Deduplicate tagged VLANs per ifIndex
        foreach ($tagged_vlans_per_ifindex as $idx => $vlan_arr) {
            $tagged_vlans_per_ifindex[$idx] = array_values(array_unique($vlan_arr));
        }
        echo "    Found " . count($tagged_vlans_per_ifindex) . " interfaces with tagged VLANs.\n";
        
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
                // Alcatel vlanDescription (.1.3.6.1.4.1.6486.800.1.2.1.3.1.1.1.1.1.2 or 801)
                // Fallback alaVlanName (.1.3.6.1.4.1.6486.800.1.2.1.11.1.1.1.2 or 801)
                echo "    Trying Alcatel-specific VLAN names...\n";
                $vlan_names = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.3.1.1.1.1.1.2");
                if (empty($vlan_names)) {
                    $vlan_names = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.3.1.1.1.1.1.2");
                }
                if (empty($vlan_names)) {
                    $vlan_names = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.11.1.1.1.2");
                }
                if (empty($vlan_names)) {
                    $vlan_names = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.11.1.1.1.2");
                }
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

        // Phase 1.5: Register all discovered tagged VLANs per port into switch_port_vlans
        if (!empty($tagged_vlans_per_ifindex)) {
            echo "  Registering tagged VLANs to database for discovered ports...\n";
            foreach ($tagged_vlans_per_ifindex as $t_ifindex => $t_vlans) {
                $raw_t_name = $name_map[$t_ifindex] ?? ($name_map_ifname[$t_ifindex] ?? ($name_map_ifdescr[$t_ifindex] ?? null));
                $t_port_name = normalize_port_name($raw_t_name, null, $t_ifindex, $system_info);
                if (!$t_port_name) continue;
                foreach ($t_vlans as $t_vid) {
                    $t_vname = isset($vlan_names[$t_vid]) ? trim($vlan_names[$t_vid], '" ') : null;
                    $db->prepare("INSERT IGNORE INTO switch_port_vlans (switch_id, port_name, vlan_id, vlan_name, is_tagged) VALUES (?, ?, ?, ?, 1)")
                       ->execute([$switch['id'], $t_port_name, $t_vid, $t_vname]);
                }
            }
        }

        // 5.5 Poll Spanning Tree Protocol (STP) & Loop Protection Status
        echo "  Polling Spanning Tree (STP) & Loop Status...\n";
        $stp_proto_raw = @snmp2_get($ip, $community, ".1.3.6.1.2.1.17.2.1.0");
        $stp_protocol = 'none';
        $stp_enabled = 0;
        if ($stp_proto_raw !== false) {
            $stp_proto_val = (int)trim(str_replace(['INTEGER: ', '"'], '', $stp_proto_raw));
            $stp_protocol = match($stp_proto_val) {
                2 => 'decLb100',
                3 => 'STP (802.1D)',
                4 => 'RSTP (802.1w)',
                default => 'enabled'
            };
            $stp_enabled = 1;
        }

        $stp_top_changes_raw = @snmp2_get($ip, $community, ".1.3.6.1.2.1.17.2.4.0");
        $stp_top_changes = ($stp_top_changes_raw !== false) ? (int)trim(str_replace(['INTEGER: ', 'Counter32: '], '', $stp_top_changes_raw)) : 0;

        // Poll STP Port States: .1.3.6.1.2.1.17.2.15.1.3 (1=disabled, 2=blocking, 3=listening, 4=learning, 5=forwarding, 6=broken)
        $stp_port_states_raw = @snmprealwalk($ip, $community, ".1.3.6.1.2.1.17.2.15.1.3");
        $stp_port_states_by_bport = [];
        $stp_state_by_port_name = [];
        if ($stp_port_states_raw && is_array($stp_port_states_raw)) {
            foreach ($stp_port_states_raw as $oid => $val) {
                $parts = explode('.', $oid);
                $bport = end($parts);
                $s_int = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                $s_name = match($s_int) {
                    1 => 'disabled',
                    2 => 'blocking',
                    3 => 'listening',
                    4 => 'learning',
                    5 => 'forwarding',
                    6 => 'broken',
                    default => 'unknown'
                };
                $stp_port_states_by_bport[$bport] = $s_name;

                $b_ifindex = $ifindex_map[$bport] ?? $bport;
                $b_raw = $name_map[$b_ifindex] ?? null;
                $b_norm = normalize_port_name($b_raw, $bport, $b_ifindex, $system_info);

                // Validate if interface is physically UP
                // (In MikroTik and other bridge devices, unplugged ports report STP state 2/blocking, which is normal link-down behavior, not an active loop!)
                $b_oper = (int)($oper_status_map[$b_ifindex] ?? 0);
                if ($b_oper !== 1) {
                    $s_name = 'disabled';
                }

                $stp_state_by_port_name[$b_norm] = $s_name;
                if ($s_name === 'blocking' && $b_oper === 1) {
                    $stp_blocked_ports[$b_norm] = $b_norm;
                }
            }
        }
        echo "    STP: $stp_protocol, Topology Changes: $stp_top_changes\n";
        
        // 6. Get FDB table (MAC to Bridge Port + VLAN)
        $is_alcatel = (stripos($system_info, 'Alcatel') !== false || stripos($model, 'Alcatel') !== false || stripos($system_info, 'OmniSwitch') !== false);
        
        // Auto-purge corrupted fake sequential MAC records (00:00:00:xx:xx:xx) generated by legacy Bridge MIB parsers
        try {
            $db->prepare("DELETE FROM switch_port_map WHERE switch_id = ? AND mac_addr LIKE '00:00:00:%'")->execute([$switch['id']]);
            if ($is_alcatel) {
                // Clear any previous unmapped 'Port %' or 'Vlan%' fallback records so physical interfaces take precedence
                $db->prepare("DELETE FROM switch_port_map WHERE switch_id = ? AND (port_name LIKE 'Port %' OR port_name LIKE 'Vlan%' OR port_name LIKE 'VLAN%')")->execute([$switch['id']]);
            }
        } catch (Exception $e) {}

        echo "  Scanning FDB Tables...\n";
        
        $fdb_table = [];
        $is_vlan_aware = false;

        // --- ALCATEL-IND1/ENT1-MAC-ADDRESS-MIB (Native OmniSwitch Source Learning Tables) ---
        if ($is_alcatel) {
            echo "  Alcatel OmniSwitch detected: querying enterprise Source Learning tables...\n";
            
            $fnResolveAlcatelSl = function($p_a, $p_b) use ($name_map, $vlan_names) {
                // In ALCATEL-IND1-MAC-ADDRESS-MIB, slMacAddressEntry index has { ifIndex, dot1qVlanIndex, mac }
                // where one is the physical port (1..64 or 1001..1064) and the other is the VLAN ID (1..4094).
                // If p_a > 64 and p_b is a valid physical port (1..64), p_a is clearly the VLAN ID and p_b is the port!
                if ($p_a > 64 && $p_b >= 1 && $p_b <= 64) {
                    $p_idx = isset($name_map[1000 + $p_b]) ? (1000 + $p_b) : (isset($name_map[$p_b]) ? $p_b : (1000 + $p_b));
                    return [$p_idx, $p_a];
                }
                // If p_b > 64 and p_a is a valid physical port (1..64), p_a is the port and p_b is the VLAN ID!
                if ($p_b > 64 && $p_a >= 1 && $p_a <= 64) {
                    $p_idx = isset($name_map[1000 + $p_a]) ? (1000 + $p_a) : (isset($name_map[$p_a]) ? $p_a : (1000 + $p_a));
                    return [$p_idx, $p_b];
                }
                // If p_a >= 1000 and < 9000 (standard Alcatel chassis bridge port e.g. 1001..1052):
                if ($p_a >= 1000 && $p_a < 9000) {
                    return [$p_a, $p_b];
                }
                if ($p_b >= 1000 && $p_b < 9000) {
                    return [$p_b, $p_a];
                }
                // Physical port index fallback (1..64 mapped to 1000 + p_a):
                $p_idx = ($p_a >= 1 && $p_a <= 64) ? (1000 + $p_a) : $p_a;
                return [$p_idx, $p_b];
            };

            // 1. Primary: slMacAddressTable (.1.3.6.1.4.1.6486.800.1.2.1.8.1.1.1 or 801)
            $alcatel_sl = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.8.1.1.1", 2000000, 1);
            if (empty($alcatel_sl)) {
                $alcatel_sl = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.8.1.1.1", 2000000, 1);
            }
            if (!empty($alcatel_sl)) {
                echo "    Alcatel slMacAddressTable: retrieved " . count($alcatel_sl) . " entries.\n";
                foreach ($alcatel_sl as $oid => $val) {
                    $parts = explode('.', $oid);
                    $tot = count($parts);
                    if ($tot >= 8) {
                        list($if_idx, $vlan_id) = $fnResolveAlcatelSl((int)$parts[$tot - 8], (int)$parts[$tot - 7]);
                        $fdb_table[$oid . '.__alcatel_sl__.' . $if_idx . '.' . $vlan_id] = $if_idx;
                    }
                }
                $is_vlan_aware = true;
            }

            // 2. Secondary: slMacToPortMacTable (.1.3.6.1.4.1.6486.800.1.2.1.8.1.1.4)
            if (empty($fdb_table)) {
                echo "    Trying slMacToPortMacTable (.1.3.6.1.4.1.6486.800.1.2.1.8.1.1.4)...\n";
                $alcatel_port_mac = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.8.1.1.4", 2000000, 1);
                if (!empty($alcatel_port_mac)) {
                    echo "    Alcatel slMacToPortMacTable: retrieved " . count($alcatel_port_mac) . " entries.\n";
                    foreach ($alcatel_port_mac as $oid => $val) {
                        $parts = explode('.', $oid);
                        $tot = count($parts);
                        if ($tot >= 8) {
                            list($if_idx, $vlan_id) = $fnResolveAlcatelSl((int)$parts[$tot - 8], (int)$parts[$tot - 7]);
                            $fdb_table[$oid . '.__alcatel_sl__.' . $if_idx . '.' . $vlan_id] = $if_idx;
                        }
                    }
                    $is_vlan_aware = true;
                }
            }

            // 3. Tertiary: alaSlMacAddressGlobalTable (.1.3.6.1.4.1.6486.800.1.2.1.8.1.1.8 or 801)
            if (empty($fdb_table)) {
                echo "    Trying alaSlMacAddressGlobalTable (.1.3.6.1.4.1.6486.800.1.2.1.8.1.1.8)...\n";
                $alcatel_gbl = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.8.1.1.8", 2000000, 1);
                if (empty($alcatel_gbl)) {
                    $alcatel_gbl = @snmprealwalk($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.8.1.1.8", 2000000, 1);
                }
                if (!empty($alcatel_gbl)) {
                    echo "    Alcatel alaSlMacAddressGlobalTable: retrieved " . count($alcatel_gbl) . " entries.\n";
                    foreach ($alcatel_gbl as $oid => $val) {
                        $parts = explode('.', $oid);
                        $tot = count($parts);
                        if ($tot >= 8) {
                            list($if_idx, $vlan_id) = $fnResolveAlcatelSl((int)$parts[$tot - 8], (int)$parts[$tot - 7]);
                            $fdb_table[$oid . '.__alcatel_sl__.' . $if_idx . '.' . $vlan_id] = $if_idx;
                        }
                    }
                    $is_vlan_aware = true;
                }
            }
        }

        // Standard Q-BRIDGE-MIB fallback (dot1qTpFdbPort .1.3.6.1.2.1.17.7.1.2.2.1.2)
        if (empty($fdb_table)) {
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
        }
        
        // Cisco IOS per-VLAN community polling fallback
        if ((!$fdb_table || count($fdb_table) === 0) && stripos($system_info, 'Cisco') !== false) {
            echo "  Cisco detected: trying per-VLAN community polling...\n";
            $vlan_list = snmp_walk_indexed($ip, $community, ".1.3.6.1.4.1.9.9.46.1.3.1.1.2"); // vtpVlanState
            if (empty($vlan_list)) {
                $vlan_list = snmp_walk_indexed($ip, $community, ".1.3.6.1.2.1.17.7.1.4.3.1.5");
            }
            
            $fdb_table = [];
            $cisco_vlans = !empty($vlan_list) ? array_keys($vlan_list) : [1];
            foreach ($cisco_vlans as $vlan_num) {
                $vlan_num = (int)$vlan_num;
                if ($vlan_num >= 1002 && $vlan_num <= 1005) continue;
                
                $vlan_community = $community . '@' . $vlan_num;
                $vlan_fdb = @snmprealwalk($ip, $vlan_community, ".1.3.6.1.2.1.17.4.3.1.2");
                if ($vlan_fdb && is_array($vlan_fdb)) {
                    foreach ($vlan_fdb as $oid => $val) {
                        $fdb_table[$oid . '.__vlan__.' . $vlan_num] = $val;
                    }
                    echo "    VLAN $vlan_num: " . count($vlan_fdb) . " entries\n";
                }
            }
            $is_vlan_aware = false;
        }

        // Cache existing MAC locations to track MAC Flapping (L2 loop indicator)
        $existing_mac_ports = [];
        try {
            $prev_stmt = $db->prepare("SELECT mac_addr, port_name FROM switch_port_map WHERE switch_id = ? AND mac_addr NOT LIKE 'PORT:%'");
            $prev_stmt->execute([$switch['id']]);
            $existing_mac_ports = $prev_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {}

        $mac_flaps = [];

        if ($fdb_table) {
            $discovered_count = 0;
            foreach ($fdb_table as $oid => $val) {
                $is_alcatel_sl = false;
                $alcatel_ifindex = null;
                $alcatel_vlan = null;

                // Check for Alcatel native SL entry
                if (strpos($oid, '.__alcatel_sl__.') !== false) {
                    $sl_parts = explode('.__alcatel_sl__.', $oid);
                    $oid = $sl_parts[0];
                    $meta = explode('.', $sl_parts[1]);
                    $alcatel_ifindex = (int)$meta[0];
                    $alcatel_vlan = (int)$meta[1];
                    $is_alcatel_sl = true;
                }

                // Check for Cisco per-VLAN tagged entries
                $cisco_vlan_tag = null;
                if (strpos($oid, '.__vlan__.') !== false) {
                    $tag_parts = explode('.__vlan__.', $oid);
                    $oid = $tag_parts[0];
                    $cisco_vlan_tag = (int)$tag_parts[1];
                }
                
                $parts = explode('.', $oid);
                
                if ($is_alcatel_sl) {
                    $vlan_id = $alcatel_vlan;
                    $mac_dec = array_slice($parts, -6);
                } elseif ($is_vlan_aware && !$cisco_vlan_tag) {
                    // Structure: ...1.2.2.1.2.<VLAN>.<MAC_6_PARTS>
                    $vlan_id = (int)$parts[count($parts) - 7];
                    $mac_dec = array_slice($parts, -6);
                } else {
                    // Structure: ...4.3.1.2.<MAC_6_PARTS>
                    $vlan_id = $cisco_vlan_tag ?: null;
                    $mac_dec = array_slice($parts, -6);
                }

                $mac_hex = [];
                $all_zero = true;
                foreach ($mac_dec as $dec) {
                    $d = (int)preg_replace('/[^0-9]/', '', $dec);
                    if ($d > 255) { $d = 255; }
                    if ($d !== 0) { $all_zero = false; }
                    $mac_hex[] = str_pad(dechex($d), 2, '0', STR_PAD_LEFT);
                }
                $mac_addr = strtoupper(implode(':', $mac_hex));
                
                // Validate MAC: must be 17 chars and not dummy, broadcast, or multicast
                if ($all_zero || strlen($mac_addr) !== 17 || str_starts_with($mac_addr, '00:00:00:') || $mac_addr === 'FF:FF:FF:FF:FF:FF' || $mac_addr === '00:00:00:00:00:00') {
                    continue;
                }
                
                $bridge_port = $is_alcatel_sl ? $alcatel_ifindex : trim(str_replace('INTEGER: ', '', $val));
                if (!$bridge_port || (int)$bridge_port <= 0) {
                    continue; // RFC 1493 port 0 represents CPU/management/static table, not a physical bridge port
                }
                
                // Smart VLAN Resolution using PVID for standard bridges
                if (!$is_alcatel_sl) {
                    // Only fallback to PVID if VLAN ID was not discovered in 802.1Q dot1qTpFdbPort table
                    if (!$vlan_id || (int)$vlan_id <= 0) {
                        $pvid_val = $pvid_map[$bridge_port] ?? null;
                        $vlan_id = ($pvid_val && (int)$pvid_val > 0) ? (int)$pvid_val : 1;
                    }
                }

                // Bridge port to ifIndex resolution
                if ($is_alcatel_sl) {
                    $ifindex = $alcatel_ifindex;
                } else {
                    $ifindex = $ifindex_map[$bridge_port] ?? null;
                    if (!$ifindex && $cisco_vlan_tag) {
                        $vlan_ifindex = @snmp2_get($ip, $community . '@' . $cisco_vlan_tag, ".1.3.6.1.2.1.17.1.4.1.2." . $bridge_port);
                        if ($vlan_ifindex !== false) {
                            $ifindex = trim(str_replace('INTEGER: ', '', $vlan_ifindex));
                            $ifindex_map[$bridge_port] = $ifindex;
                        }
                    }
                    if (!$ifindex) {
                        $ifindex = $bridge_port;
                    }
                }
                
                // Smart port name resolution with vendor-aware fallback
                $raw_name = $name_map[$ifindex] ?? null;
                $port_name = normalize_port_name($raw_name, $bridge_port, $ifindex, $system_info);
                
                // Track MAC Flapping on this switch (exclude dummy, multicast, broadcast, VRRP)
                if (isset($existing_mac_ports[$mac_addr]) && $existing_mac_ports[$mac_addr] !== $port_name) {
                    $mUpper = strtoupper($mac_addr);
                    $firstOctet = hexdec(substr($mUpper, 0, 2));
                    $isMulticast = ($firstOctet & 1);
                    $isDummy = str_starts_with($mUpper, '00:00:00:') || $mUpper === 'FF:FF:FF:FF:FF:FF' || str_starts_with($mUpper, '00:00:5E:') || str_starts_with($mUpper, '00:00:0C:');
                    
                    if (!$isMulticast && !$isDummy && strlen($mUpper) === 17) {
                        $mac_flaps[] = [
                            'mac' => $mac_addr,
                            'from' => $existing_mac_ports[$mac_addr],
                            'to' => $port_name
                        ];
                    }
                }

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

                // Get STP state for this bridge port (only active if link is UP)
                $port_stp_state = $stp_port_states_by_bport[$bridge_port] ?? null;
                if ($port_status !== 'up') {
                    $port_stp_state = 'disabled';
                } elseif ($port_stp_state === 'blocking') {
                    $stp_blocked_ports[$port_name] = $port_name;
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
                    $stmt = $db->prepare("INSERT INTO switch_port_map (mac_addr, switch_id, port_name, vlan_id, vlan_name, port_status, stp_state, port_type, port_speed, port_alias) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE port_name = VALUES(port_name), vlan_id = VALUES(vlan_id), vlan_name = VALUES(vlan_name), port_status = VALUES(port_status), stp_state = VALUES(stp_state), port_type = VALUES(port_type), port_speed = VALUES(port_speed), port_alias = VALUES(port_alias), updated_at = CURRENT_TIMESTAMP");
                    $stmt->execute([$mac_addr, $switch['id'], $port_name, $vlan_id, $vlan_name, $port_status, $port_stp_state, $port_type, $port_speed, $port_alias]);
                    $discovered_count++;

                    // Save all tagged VLANs for this port
                    if ($ifindex && isset($tagged_vlans_per_ifindex[$ifindex])) {
                        foreach ($tagged_vlans_per_ifindex[$ifindex] as $tagged_vlan_id) {
                            $vlan_name_for_tagged = $vlan_names[$tagged_vlan_id] ?? null;
                            $db->prepare("INSERT IGNORE INTO switch_port_vlans (switch_id, port_name, vlan_id, vlan_name, is_tagged) VALUES (?, ?, ?, ?, 1)")
                               ->execute([$switch['id'], $port_name, $tagged_vlan_id, $vlan_name_for_tagged]);
                        }
                    }

                    // Auto-register non-PVID VLANs learned via traffic as tagged VLANs for this port
                    $port_pvid = $pvid_map[$bridge_port] ?? ($pvid_map[$ifindex] ?? 1);
                    if ($vlan_id && (int)$vlan_id !== (int)$port_pvid) {
                        $vlan_name_for_tagged = isset($vlan_names[$vlan_id]) ? trim($vlan_names[$vlan_id], '" ') : null;
                        $db->prepare("INSERT IGNORE INTO switch_port_vlans (switch_id, port_name, vlan_id, vlan_name, is_tagged) VALUES (?, ?, ?, ?, 1)")
                           ->execute([$switch['id'], $port_name, $vlan_id, $vlan_name_for_tagged]);
                    }

                }
            }
            
            // Pair-specific MAC Flapping Analysis (Filters out normal WiFi roaming & client movement)
            // Fetch MAC count per port on this switch to differentiate Trunk/Uplink vs Access/Edge
            $port_mac_counts = [];
            try {
                $mcStmt = $db->prepare("SELECT port_name, COUNT(*) as cnt FROM switch_port_map WHERE switch_id = ? AND mac_addr NOT LIKE 'PORT:%' GROUP BY port_name");
                $mcStmt->execute([$switch['id']]);
                $port_mac_counts = $mcStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Exception $e) {}

            $fnIsUplink = function($pName) use ($port_mac_counts, $name_map_ifalias) {
                $clean = preg_match('/\(([^)]+)\)/', $pName, $m) ? $m[1] : $pName;
                $clean = trim($clean);
                $cnt = $port_mac_counts[$clean] ?? ($port_mac_counts[$pName] ?? 0);
                if ($cnt > 3) return true;
                if (preg_match('/(-to-|-sw|uplink|trunk|core|dist|po\d+|bond|ae\d+|sfp|\/48|\/49|\/50|\/51|\/52|\/24|\/25|\/26)/i', $clean)) return true;
                $alias = $name_map_ifalias[$clean] ?? ($name_map_ifalias[$pName] ?? '');
                if (!empty($alias) && preg_match('/(uplink|trunk|core|dist|switch|to\s)/i', $alias)) return true;
                return false;
            };

            $pair_flaps = [];
            $access_suspects = []; // Root cause candidates where an Access port is flapping to Uplink or another port
            foreach ($mac_flaps as $mf) {
                $p1 = $mf['from'];
                $p2 = $mf['to'];
                if (!$p1 || !$p2 || $p1 === $p2) continue;
                // Normalize pair so (Port A <-> Port B) and (Port B <-> Port A) are grouped together
                $pair_key = ($p1 < $p2) ? "$p1 <-> $p2" : "$p2 <-> $p1";
                $pair_flaps[$pair_key][] = $mf['mac'];

                $p1_up = $fnIsUplink($p1);
                $p2_up = $fnIsUplink($p2);

                // If one port is Access and the other is Uplink, or both are Access:
                if (!$p1_up || !$p2_up) {
                    $origin_port = !$p1_up ? $p1 : $p2;
                    $transit_port = !$p1_up ? $p2 : $p1;
                    $access_suspects[] = [
                        'mac'          => $mf['mac'],
                        'access_port'  => $origin_port,
                        'transit_port' => $transit_port
                    ];
                }
            }

            // Identify highest flapping pair
            $top_pair = null;
            $max_pair_flaps = 0;
            foreach ($pair_flaps as $pair => $flapped_macs) {
                $unique_macs = count(array_unique($flapped_macs));
                if ($unique_macs > $max_pair_flaps) {
                    $max_pair_flaps = $unique_macs;
                    $top_pair = $pair;
                }
            }

            // Fetch Tagged Ports for this switch to assist infrastructure link classification
            $tagged_ports_list = [];
            try {
                $tpStmt = $db->prepare("SELECT DISTINCT port_name FROM switch_port_vlans WHERE switch_id = ? AND is_tagged = 1");
                $tpStmt->execute([$switch['id']]);
                $tagged_ports_list = $tpStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Exception $e) {}

            // Build port telemetry map
            $telemetry_ports = [];
            foreach ($port_mac_counts as $pName => $pCnt) {
                $telemetry_ports[$pName] = [
                    'mac_count' => $pCnt,
                    'stp_state' => isset($stp_blocked_ports[$pName]) ? 'blocking' : 'forwarding',
                    'alias'     => $name_map_ifalias[$pName] ?? ''
                ];
            }
            foreach ($stp_blocked_ports as $bpName) {
                if (!isset($telemetry_ports[$bpName])) {
                    $telemetry_ports[$bpName] = [
                        'mac_count' => 0,
                        'stp_state' => 'blocking',
                        'alias'     => $name_map_ifalias[$bpName] ?? ''
                    ];
                }
            }

            $prev_tcn = (int)($switch['stp_topology_changes'] ?? 0);
            $tcn_delta = max(0, $stp_top_changes - $prev_tcn);
            $prev_loop_detected = (int)($switch['loop_detected'] ?? 0);
            $prev_loop_details = (string)($switch['loop_details'] ?? '');
            $cycles_persisted = ($prev_loop_detected > 0) ? 2 : 1;
            $flap_threshold = max(3, (int)Settings::get('loop_flap_threshold', 5));

            // Multi-Evidence Evaluation via LoopEvidenceEngine (Candidate-Only Correlation)
            $eval = LoopEvidenceEngine::evaluate([
                'ports'            => $telemetry_ports,
                'flaps'            => $mac_flaps,
                'tagged_ports'     => $tagged_ports_list,
                'tcn_delta'        => $tcn_delta,
                'cycles_persisted' => $cycles_persisted,
                'flap_threshold'   => $flap_threshold
            ]);

            $top_pair = $eval['top_pair'];
            $max_pair_flaps = $eval['unique_mac_count'];
            $is_candidate = $eval['is_candidate'];
            $confidence_score = $eval['confidence_score'];
            $confidence_level = $eval['confidence_level'];

            // Orthogonal State Evaluation
            $loop_state = 'NORMAL';
            if ($is_candidate) {
                $loop_state = ($cycles_persisted >= 2 || !empty($stp_blocked_ports)) ? 'CONFIRMED' : 'SUSPECTED';
            }

            $protection_state = !empty($stp_blocked_ports) ? 'STP_BLOCKING' : 'NONE';

            $impact_state = 'NORMAL';
            if ($loop_state === 'CONFIRMED' || $loop_state === 'SUSPECTED') {
                if ($protection_state === 'STP_BLOCKING') {
                    $impact_state = 'MITIGATED';
                } else {
                    $impact_state = ($loop_state === 'CONFIRMED') ? 'ACTIVE' : 'SUSPECTED';
                }
            }

            $is_real_flapping_loop = ($impact_state === 'ACTIVE');

            // Identify Root-Cause End Device on Access port if loop candidate or blocked port is confirmed
            $culprit_info = null;
            if (!empty($access_suspects) && ($is_real_flapping_loop || !empty($stp_blocked_ports))) {
                $matched_suspect = null;
                if ($is_real_flapping_loop && $top_pair) {
                    foreach ($access_suspects as $as) {
                        $p_key = ($as['access_port'] < $as['transit_port'])
                            ? "{$as['access_port']} <-> {$as['transit_port']}"
                            : "{$as['transit_port']} <-> {$as['access_port']}";
                        if ($p_key === $top_pair) {
                            $matched_suspect = $as;
                            break;
                        }
                    }
                } elseif (!empty($stp_blocked_ports)) {
                    $matched_suspect = $access_suspects[0];
                }

                if ($matched_suspect) {
                    $s_mac = $matched_suspect['mac'];
                    $s_vendor = function_exists('get_vendor_by_mac') ? get_vendor_by_mac($s_mac) : 'Unknown';
                    
                    // Lookup IP & Hostname
                    $ipStmt = $db->prepare("SELECT ip_addr, hostname, vendor, description FROM ip_addresses WHERE mac_addr = ? LIMIT 1");
                    $ipStmt->execute([$s_mac]);
                    $ipRow = $ipStmt->fetch(PDO::FETCH_ASSOC);

                    $dev_ip = $ipRow['ip_addr'] ?? null;
                    $dev_host = $ipRow['hostname'] ?? null;
                    $dev_name = $dev_host ?: ($s_vendor !== 'Unknown' ? "$s_vendor Device" : "End-Device");

                    $culprit_info = [
                        'mac'          => $s_mac,
                        'vendor'       => $s_vendor,
                        'ip'           => $dev_ip,
                        'name'         => $dev_name,
                        'access_port'  => $matched_suspect['access_port'],
                        'transit_port' => $matched_suspect['transit_port']
                    ];

                    // Log into ip_conflict_events table ONLY for confirmed active loop events
                    if ($is_real_flapping_loop) {
                        try {
                            $ceStmt = $db->prepare("
                                INSERT INTO ip_conflict_events 
                                (ip_addr, mac_a, vendor_a, switch_port_a, switch_port_b, event_type, details, flap_count, status, detected_at)
                                VALUES (?, ?, ?, ?, ?, 'flapping', ?, ?, 'active', NOW())
                            ");
                            $ceStmt->execute([
                                $dev_ip ?: '0.0.0.0',
                                $s_mac,
                                $s_vendor,
                                $matched_suspect['access_port'],
                                $matched_suspect['transit_port'],
                                "MAC thrashing ($max_pair_flaps MACs) on Access port {$matched_suspect['access_port']} bouncing to {$matched_suspect['transit_port']} [Confidence: {$confidence_level}]",
                                $max_pair_flaps
                            ]);
                        } catch (Exception $e) {}
                    }
                }
            }

            // Assemble Loop Details with Evidence Reasoning
            $loop_detected = ($impact_state === 'ACTIVE' || $impact_state === 'MITIGATED') ? 1 : 0;
            $loop_details = null;

            if ($impact_state === 'MITIGATED') {
                $blocked_list = implode(', ', $stp_blocked_ports);
                if ($culprit_info) {
                    $loop_details = "[MITIGATED] STP Loop Quarantined (Confidence: {$confidence_level} {$confidence_score}/100): Port(s) {$blocked_list} in BLOCKING state. Suspect End-Device on Access port {$culprit_info['access_port']}: {$culprit_info['name']} [{$culprit_info['vendor']} - {$culprit_info['mac']}]";
                } else {
                    $loop_details = "[MITIGATED] STP Loop Prevention Active (Confidence: {$confidence_level} {$confidence_score}/100): Port(s) {$blocked_list} in BLOCKING state to prevent switching loop";
                }
                echo "  🔵 [STP LOOP MITIGATED]: $loop_details\n";
            } elseif ($impact_state === 'ACTIVE') {
                $sample_mac = !empty($pair_flaps[$top_pair]) ? $pair_flaps[$top_pair][0] : null;
                $sample_str = $sample_mac ? " (e.g. $sample_mac)" : "";
                if ($culprit_info) {
                    $loop_details = "[ACTIVE] Confirmed L2 Loop (Confidence: {$confidence_level} {$confidence_score}/100): High-frequency MAC thrashing ($max_pair_flaps MACs) between $top_pair. Root-cause origin on Access port {$culprit_info['access_port']} [{$culprit_info['name']} ({$culprit_info['mac']})]";
                } else {
                    $loop_details = "[ACTIVE] Confirmed L2 Loop (Confidence: {$confidence_level} {$confidence_score}/100): High-frequency MAC thrashing ($max_pair_flaps MACs) between $top_pair$sample_str";
                }
                echo "  🔴 [ACTIVE L2 LOOP]: $loop_details\n";
            } elseif ($loop_state === 'SUSPECTED') {
                $loop_details = "[SUSPECTED] Candidate MAC thrashing observed between $top_pair ($max_pair_flaps MACs, Confidence: {$confidence_level} {$confidence_score}/100). Awaiting cycle 2 persistence confirmation.";
                echo "  🟡 [SUSPECTED L2 LOOP]: $loop_details\n";
            }

            $db->prepare("UPDATE switches SET last_poll = CURRENT_TIMESTAMP, stp_enabled = ?, stp_protocol = ?, loop_detected = ?, loop_details = ?, stp_topology_changes = ? WHERE id = ?")
               ->execute([$stp_enabled, $stp_protocol, $loop_detected, $loop_details, $stp_top_changes, $switch['id']]);

            // Dispatch alert based on State Transition to prevent notification spamming
            if (class_exists('NotificationHelper')) {
                if ($loop_detected) {
                    $is_new_event = !$prev_loop_detected;
                    $is_state_changed = ($prev_loop_detected && $loop_details !== $prev_loop_details);

                    if ($is_new_event || $is_state_changed) {
                        // New loop or changed condition: dispatch immediately (force = true)
                        NotificationHelper::notifySwitchLoop($switch['name'], $ip, $loop_details, array_values($stp_blocked_ports ?? []), true);
                    } else {
                        // Persistent unchanged condition: throttled to 6-hour reminders
                        NotificationHelper::notifySwitchLoop($switch['name'], $ip, $loop_details, array_values($stp_blocked_ports ?? []), false);
                    }
                } elseif ($prev_loop_detected && !$loop_detected) {
                    // Loop cleared: dispatch recovery notification
                    NotificationHelper::notifySwitchLoopResolved($switch['name'], $ip, $prev_loop_details);
                }
            }

            echo "Discovered $discovered_count MAC-Port mappings (VLAN ".($is_vlan_aware ? "ON" : "OFF").") on {$switch['name']}.\n";
            AuditLogHelper::log("poll_switch", "switch", $switch['id'], "Discovered $discovered_count mappings on {$switch['name']}" . ($loop_detected ? " | LOOP ALERT: $loop_details" : ""));
        }
    } else {
        echo "Note: Bridge port mapping (L2) not supported on {$ip}. Skipping L2, proceeding to L3 ARP...\n";
    }

    // --- Phase 2: Standalone Interface Inventory ---
    // Even if FDB is empty, discover all physical interfaces for visibility
    echo "  Phase 2: Interface inventory & SFP Polling...\n";
    
    $sfp_data_map = VendorDetector::pollSfpDOM($ip, $community, $model);

    
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

            // Match SFP by ifidx OR exact port name OR prefix/substring (e.g. "sfp-sfpplus8" inside "sfp-sfpplus8-to-DS3")
            $sfp_match = $sfp_data_map[$ifidx] ?? ($sfp_data_map[$name] ?? ($sfp_data_map[strtolower($name)] ?? null));
            if ($sfp_match === null && !empty($sfp_data_map)) {
                foreach ($sfp_data_map as $key => $sfp_info) {
                    if (is_string($key) && strlen($key) >= 3) {
                        if (stripos($name, $key) === 0 || stripos($name, $key) !== false) {
                            $sfp_match = $sfp_info;
                            break;
                        }
                    }
                }
            }

            $sfp = array_merge([
                'vendor'   => null,
                'part'     => null,
                'serial'   => null,
                'rx_power' => null,
                'tx_power' => null,
            ], $sfp_match ?? []);

            $stmt_check = $db->prepare("SELECT id FROM switch_port_map WHERE switch_id = ? AND port_name = ? LIMIT 1");
            $stmt_check->execute([$switch['id'], $name]);
            $existing_port_id = $stmt_check->fetchColumn();

            $port_stp = $stp_state_by_port_name[$name] ?? null;
            if ($status !== 'up') {
                $port_stp = 'disabled';
            }

            $port_pvid = $pvid_map[$bridge_port_for_if ?? $ifidx] ?? ($pvid_map[$ifidx] ?? null);
            $port_pvid_name = ($port_pvid && isset($vlan_names[$port_pvid])) ? trim($vlan_names[$port_pvid], '" ') : null;

            if ($existing_port_id) {
                // Update existing port (from FDB or previous run) with SFP, STP, status data, and fallback VLAN
                $db->prepare("UPDATE switch_port_map SET port_status=?, stp_state=COALESCE(?, stp_state), port_type=?, port_speed=?, sfp_vendor=?, sfp_part=?, sfp_serial=?, sfp_rx_power=?, sfp_tx_power=?, vlan_id=COALESCE(vlan_id, ?), vlan_name=COALESCE(vlan_name, ?) WHERE id=?")
                   ->execute([$status, $port_stp, $type, $speed, $sfp['vendor'] ?? null, $sfp['part'] ?? null, $sfp['serial'] ?? null, $sfp['rx_power'] ?? null, $sfp['tx_power'] ?? null, $port_pvid, $port_pvid_name, $existing_port_id]);
            } else {
                // Insert placeholder entry for the port itself (without a real MAC)
                // Use a dummy MAC to avoid unique constraint violations on empty strings
                $dummy_mac = 'PORT:' . substr($name, 0, 12);
                $db->prepare("INSERT IGNORE INTO switch_port_map (mac_addr, switch_id, port_name, vlan_id, vlan_name, port_status, stp_state, port_type, port_speed, sfp_vendor, sfp_part, sfp_serial, sfp_rx_power, sfp_tx_power) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$dummy_mac, $switch['id'], $name, $port_pvid, $port_pvid_name, $status, $port_stp, $type, $speed, $sfp['vendor'] ?? null, $sfp['part'] ?? null, $sfp['serial'] ?? null, $sfp['rx_power'] ?? null, $sfp['tx_power'] ?? null]);
            }

            // Check for critical optical power degradation on active SFP interfaces
            if ($status === 'up' && !empty($sfp['rx_power']) && is_numeric($sfp['rx_power']) && class_exists('NotificationHelper')) {
                $rx_val = (float)$sfp['rx_power'];
                if ($rx_val <= -24.0 && $rx_val > -40.0) {
                    NotificationHelper::notifySfpOpticalWarning($switch['name'], $ip, $name, $sfp['rx_power'], $sfp['tx_power']);
                }
            }

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
        }
        if ($arp_count > 0) {
            echo "    Successfully discovered $arp_count ARP entries from {$table['name']}.\n";
            break; // Found data, stop trying other tables
        }
    }
    } catch (Exception $e) {
        echo "  ❌ Error polling Switch {$switch['name']}: " . $e->getMessage() . "\n";
    }
}
if (!$is_cli) {
?>

        </div>
    </div>
    
    <div style="text-align: center; margin-top: 2rem;">
        <p style="color: #64748b; font-size: 0.8rem;">Task finished. Redirecting to Management Console...</p>
        <a href="switches?message=Poll%20completed" style="color: #38bdf8; text-decoration: none; font-weight: bold; border: 1px solid #38bdf8; padding: 10px 20px; border-radius: 8px; display: inline-block; margin-top: 10px;">Return Now</a>
    </div>

    <script>
        // Auto scroll to bottom as logs come in
        const consoleObj = document.getElementById('console');
        consoleObj.scrollTop = consoleObj.scrollHeight;
        
        // Immediate redirect if no errors
        setTimeout(() => {
            window.location.href = 'switches?message=Poll completed';
        }, 1500);
    </script>
</body>
</html>
<?php
ob_end_flush();
} // end if (!$is_cli)
