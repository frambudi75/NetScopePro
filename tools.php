<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$page_title = 'Network Toolbox';
include 'includes/header.php';

$output = '';
$target = trim($_POST['target'] ?? ($_GET['target'] ?? ''));
$action = trim($_POST['action'] ?? ($_GET['action'] ?? ''));

if ((!empty($_POST) || !empty($_GET)) && !empty($target)) {
    $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    // Sanitize target (must be IP or domain)
    if (filter_var($target, FILTER_VALIDATE_IP) || preg_match('/^[a-zA-Z0-9\.\-]+$/', $target)) {
        if ($action === 'ping') {
            $cmd = $is_windows ? "ping -n 4 " . escapeshellarg($target) : "ping -c 4 " . escapeshellarg($target);
            $output = shell_exec($cmd);
        } elseif ($action === 'trace') {
            $cmd = $is_windows ? "tracert " . escapeshellarg($target) : "traceroute " . escapeshellarg($target);
            $output = shell_exec($cmd . " 2>&1");
        } elseif ($action === 'oui') {
            // OUI Lookup via MacVendors (Fast)
            $mac = trim($target);
            $url = "https://api.macvendors.com/" . urlencode($mac);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($status == 200 && !empty($result)) {
                $output = "MAC: $mac\nVendor: $result";
            } else {
                $output = "MAC: $mac\nVendor Information Not Found (HTTP $status).";
            }
        } elseif ($action === 'conflict') {
            if (!filter_var($target, FILTER_VALIDATE_IP)) {
                $output = "Error: Conflict Prober requires a valid IPv4 address.";
            } else {
                $db = get_db_connection();
                $output = "=== NetScope Pro: IP Conflict Diagnostic Prober ===\n";
                $output .= "Target IP        : " . $target . "\n";
                $output .= "Timestamp        : " . date('Y-m-d H:i:s') . "\n";
                $output .= "Runner Platform  : " . ($is_windows ? "Windows" : "Linux / Docker") . "\n";
                $output .= "------------------------------------------------------------\n\n";

                // Phase 1: Database Inventory Records
                $output .= "[Phase 1] Database & Inventory Record\n";
                $stmt = $db->prepare("SELECT * FROM ip_addresses WHERE ip_addr = ?");
                $stmt->execute([$target]);
                $db_rows = $stmt->fetchAll();
                
                $has_db_conflict = false;
                $db_conflict_details = '';
                if (!empty($db_rows)) {
                    foreach ($db_rows as $row) {
                        $output .= "  • Subnet ID        : " . $row['subnet_id'] . "\n";
                        $output .= "  • Hostname         : " . ($row['hostname'] ?: '-') . "\n";
                        $output .= "  • Recorded MAC     : " . ($row['mac_addr'] ?: 'Unknown') . " (" . ($row['vendor'] ?: 'Unknown Vendor') . ")\n";
                        $output .= "  • Recorded OS      : " . ($row['os'] ?: '-') . "\n";
                        $output .= "  • Status           : " . strtoupper($row['state']) . " (Confidence: " . $row['confidence_score'] . "%)\n";

                        // Auto-heal legacy false-positive OS conflicts caused by old Nmap single-string parser
                        if (!empty($row['conflict_details']) && strpos($row['conflict_details'], 'Conflicting OS fingerprints') !== false) {
                            $db->prepare("UPDATE ip_addresses SET conflict_detected = 0, conflict_mac = NULL, conflict_details = NULL WHERE ip_addr = ?")->execute([$target]);
                            $row['conflict_detected'] = 0;
                            $row['conflict_details'] = null;
                            $output .= "  • Conflict Flag    : None (✅ Legacy false-positive OS discrepancy automatically resolved)\n";
                        } else {
                            $output .= "  • Conflict Flag    : " . ($row['conflict_detected'] ? "YES (⚠️ ACTIVE CONFLICT)" : "None") . "\n";
                            if (!empty($row['conflict_mac'])) {
                                $output .= "  • Conflicting MAC  : " . $row['conflict_mac'] . "\n";
                            }
                            if (!empty($row['conflict_details'])) {
                                $output .= "  • Conflict Details : " . $row['conflict_details'] . "\n";
                            }
                            if ($row['conflict_detected']) {
                                $has_db_conflict = true;
                                $db_conflict_details = $row['conflict_details'];
                            }
                        }
                    }
                } else {
                    $output .= "  • No existing IPAM database record for this IP.\n";
                }
                $output .= "\n";

                // Phase 2: Switch Port L2 Cross-Reference
                $output .= "[Phase 2] Switch Port & L2 Hardware Mapping\n";
                $stmt = $db->prepare("
                    SELECT spm.*, s.name as switch_name, s.ip_addr as switch_ip 
                    FROM switch_port_map spm 
                    LEFT JOIN switches s ON spm.switch_id = s.id 
                    WHERE spm.mac_addr IN (
                        SELECT mac_addr FROM ip_addresses WHERE ip_addr = ? AND mac_addr IS NOT NULL
                        UNION
                        SELECT conflict_mac FROM ip_addresses WHERE ip_addr = ? AND conflict_mac IS NOT NULL
                    )
                ");
                $stmt->execute([$target, $target]);
                $ports = $stmt->fetchAll();
                $distinct_macs = [];
                $ports_by_switch = [];

                if (!empty($ports)) {
                    foreach ($ports as $p) {
                        $output .= "  • Switch           : " . $p['switch_name'] . " (" . $p['switch_ip'] . ")\n";
                        $output .= "    Port             : " . $p['port_name'] . " (VLAN " . ($p['vlan_id'] ?: '1') . ", Status: " . ($p['port_status'] ?: 'up') . ")\n";
                        $output .= "    MAC Attached     : " . $p['mac_addr'] . "\n";
                        if (!empty($p['mac_addr'])) {
                            $distinct_macs[strtoupper($p['mac_addr'])] = true;
                            $sw_key = $p['switch_name'] ?: $p['switch_ip'];
                            $ports_by_switch[$sw_key][] = $p['port_name'];
                        }
                    }

                    $is_same_switch_flapping = false;
                    $flapping_switches = [];
                    foreach ($ports_by_switch as $sw_label => $p_list) {
                        $u_ports = array_unique($p_list);
                        if (count($u_ports) > 1) {
                            $is_same_switch_flapping = true;
                            $flapping_switches[] = "$sw_label (" . implode(', ', $u_ports) . ")";
                        }
                    }

                    if (count($distinct_macs) > 1) {
                        $output .= "  ⚠️ CONFLICT: Multiple distinct MACs (" . implode(', ', array_keys($distinct_macs)) . ") are mapped to this IP across switch ports!\n";
                    } elseif ($is_same_switch_flapping) {
                        $output .= "  ⚠️ WARNING: MAC address is learned across multiple ports on the SAME switch: " . implode('; ', $flapping_switches) . " (MAC Flapping / Switching Loop)!\n";
                    } else {
                        $output .= "  • Topology Status  : Normal L2 forwarding. Single MAC (" . array_key_first($distinct_macs) . ") tracked along switch uplink path (" . count($ports) . " switches).\n";
                    }
                } else {
                    $output .= "  • No switch port entries directly associated with recorded MACs.\n";
                }
                $output .= "\n";

                // Phase 3: Multi-Probe Live Verification & MAC Stability Check
                $output .= "[Phase 3] Multi-Probe Verification & MAC Stability Check\n";
                
                $probe_cycles = 3;
                $probes = [];
                $all_ttls = [];
                $observed_macs = [];
                $packets_sent = 0;
                $packets_received = 0;

                for ($i = 1; $i <= $probe_cycles; $i++) {
                    $packets_sent += 2;
                    // Ping 2 packets per cycle
                    $ping_cmd = $is_windows 
                        ? "ping -n 2 -w 1000 " . escapeshellarg($target) 
                        : "ping -c 2 -W 1 " . escapeshellarg($target);
                    $raw_ping = (string)shell_exec($ping_cmd);

                    $p_ttl = null;
                    if (preg_match_all('/TTL=(\d+)/i', $raw_ping, $ttl_matches)) {
                        $p_ttls = array_map('intval', $ttl_matches[1]);
                        $packets_received += count($p_ttls);
                        $p_ttl = end($p_ttls);
                        foreach ($p_ttls as $t_val) {
                            $all_ttls[] = $t_val;
                        }
                    }

                    // Query ARP table immediately after this probe cycle
                    $arp_lines = [];
                    if ($is_windows) {
                        @exec("arp -a " . escapeshellarg($target), $arp_lines);
                    } else {
                        @exec("arp -n " . escapeshellarg($target), $arp_lines);
                    }

                    $p_mac = null;
                    foreach ($arp_lines as $aline) {
                        if (preg_match('/([0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2})/', $aline, $m)) {
                            $p_mac = strtolower(str_replace('-', ':', $m[1]));
                            break;
                        }
                    }

                    if ($p_mac && $p_mac !== 'ff:ff:ff:ff:ff:ff' && $p_mac !== '00:00:00:00:00:00') {
                        $observed_macs[] = $p_mac;
                    }

                    $probes[] = [
                        'cycle' => $i,
                        'ttl'   => $p_ttl,
                        'mac'   => $p_mac
                    ];

                    $mac_disp = $p_mac ? strtoupper($p_mac) : 'Not in ARP';
                    $ttl_disp = $p_ttl !== null ? "TTL=$p_ttl" : 'No Response';
                    $output .= "  • Probe $i ➔ Status: $ttl_disp | Active MAC: $mac_disp\n";

                    if ($i < $probe_cycles) {
                        usleep(150000); // 150ms pause
                    }
                }

                $unique_ttls = array_values(array_unique($all_ttls));
                $unique_probe_macs = array_values(array_unique($observed_macs));

                $is_mac_stable = true;
                $mac_stability_label = 'No ARP response observed';
                if (!empty($unique_probe_macs)) {
                    if (count($unique_probe_macs) === 1) {
                        $is_mac_stable = true;
                        $mac_stability_label = "Stable (Single MAC " . strtoupper($unique_probe_macs[0]) . " across all probes)";
                    } else {
                        $is_mac_stable = false;
                        $mac_stability_label = "⚠️ UNSTABLE / OSCILLATING (" . implode(' ➔ ', array_map('strtoupper', $unique_probe_macs)) . ")";
                    }
                }

                $output .= "\n  • Multi-Probe MAC Stability : " . $mac_stability_label . "\n";
                $output .= "  • Observed TTL Values       : " . (!empty($all_ttls) ? implode(', ', $all_ttls) . " (Unique: " . implode(', ', $unique_ttls) . ")" : "None (Host unpingable)") . "\n";
                $output .= "  • Probe Packet Loss         : " . ($packets_sent > 0 ? round((($packets_sent - $packets_received) / $packets_sent) * 100) : 0) . "% ($packets_received/$packets_sent received)\n";
                $output .= "\n";

                // Phase 4: Evidence Summary & Confidence Evaluation
                $output .= "[Phase 4] Evidence Checklist & Confidence Analysis\n";

                // Calculate Diagnostic Evidence Points & Confidence
                $evidence = [];
                $confidence_points = 0;
                $conflict_risk_score = 0;
                $reasons = [];

                // 1. Host Reachability
                if ($packets_received > 0) {
                    $confidence_points += 25;
                    $evidence[] = "✓ Host Reachability        : RESPONSIVE ($packets_received/$packets_sent ICMP probes answered)";
                } else {
                    $evidence[] = "⚠ Host Reachability        : UNRESPONSIVE (Host drops ICMP or is offline)";
                }

                // 2. MAC Stability Evidence
                if (!empty($unique_probe_macs)) {
                    $confidence_points += 30;
                    if ($is_mac_stable) {
                        $evidence[] = "✓ Multi-Probe MAC Stability: STABLE (Consistent single MAC observed)";
                    } else {
                        $conflict_risk_score += 50;
                        $reasons[] = "MAC address is actively oscillating between probes (" . implode(' vs ', array_map('strtoupper', $unique_probe_macs)) . "), indicating dual hardware answering the same IP!";
                        $evidence[] = "✗ Multi-Probe MAC Stability: ⚠️ UNSTABLE (" . count($unique_probe_macs) . " distinct MACs observed across probes)";
                    }
                } else {
                    $evidence[] = "⚠ Local ARP Cache Lookup   : UNAVAILABLE (Target is beyond routed gateway or stealth)";
                }

                // 3. TTL Consistency Evidence
                if (!empty($unique_ttls)) {
                    $confidence_points += 20;
                    if (count($unique_ttls) === 1) {
                        $evidence[] = "✓ ICMP TTL Signature       : STABLE (TTL " . $unique_ttls[0] . ", variance: 0)";
                    } else {
                        $conflict_risk_score += 45;
                        $reasons[] = "Fluctuating TTL detected (" . implode(' vs ', $unique_ttls) . "), confirming multiple disparate OS kernels answering this IP!";
                        $evidence[] = "✗ ICMP TTL Signature       : ⚠️ FLUCTUATING (" . implode(' vs ', $unique_ttls) . ")";
                    }
                }

                // 4. Switch Port / L2 Path Evidence
                if (!empty($ports)) {
                    $confidence_points += 25;
                    if (count($distinct_macs) > 1) {
                        $conflict_risk_score += 45;
                        $reasons[] = "Multiple conflicting MAC addresses (" . implode(', ', array_keys($distinct_macs)) . ") found across switch port tables.";
                        $evidence[] = "✗ Switch L2 Topology Path  : ⚠️ CONFLICT (" . count($distinct_macs) . " distinct MACs on switch ports)";
                    } elseif (!empty($is_same_switch_flapping)) {
                        $conflict_risk_score += 35;
                        $reasons[] = "MAC address is flapping across multiple ports on the same switch: " . implode('; ', $flapping_switches);
                        $evidence[] = "✗ Switch L2 Topology Path  : ⚠️ FLAPPING (Learned on multiple ports of same switch)";
                    } else {
                        $evidence[] = "✓ Switch L2 Topology Path  : STABLE (Consistent port forwarding path tracked)";
                    }
                } else {
                    $evidence[] = "⚠ Switch L2 Hardware Path  : UNMAPPED (Target MAC not currently in switch FDB table)";
                }

                // 5. IPAM Database Registry
                if ($has_db_conflict) {
                    $conflict_risk_score += 35;
                    $reasons[] = "NetScope IPAM registry has flagged an active collision: " . ($db_conflict_details ?: 'MAC/OS collision');
                    $evidence[] = "✗ IPAM Conflict Database   : ⚠️ FLAGGED (" . ($db_conflict_details ?: 'Active conflict') . ")";
                } else {
                    $evidence[] = "✓ IPAM Conflict Database   : CLEAN (No active collision flags in database)";
                }

                // Confidence Level computation
                $confidence_rating = 'LOW';
                if ($confidence_points >= 75) {
                    $confidence_rating = 'HIGH (' . $confidence_points . '%)';
                } elseif ($confidence_points >= 45) {
                    $confidence_rating = 'MEDIUM (' . $confidence_points . '%)';
                } else {
                    $confidence_rating = 'LOW (' . max(20, $confidence_points) . '%)';
                }

                foreach ($evidence as $ev) {
                    $output .= "  $ev\n";
                }
                $output .= "  • Diagnostic Confidence    : " . $confidence_rating . "\n";
                $output .= "\n";

                // Phase 5: Verdict & Actionable Guidance
                $output .= "==================== VERDICT ====================\n";
                if ($conflict_risk_score >= 35) {
                    $output .= "🚨 STATUS: CONFIRMED / HIGH RISK IP CONFLICT!\n";
                    $output .= "Conflict Probability : " . min(100, $conflict_risk_score + 15) . "% | Confidence: " . $confidence_rating . "\n\n";
                    $output .= "Key Indicators:\n";
                    foreach ($reasons as $r) {
                        $output .= "  [!] $r\n";
                    }
                    $output .= "\nRecommended Next Steps:\n";
                    $output .= "  1. Inspect the switch port(s) and isolate/disconnect the rogue device.\n";
                    $output .= "  2. Verify static IP configuration on the offending device and switch it to DHCP.\n";
                    $output .= "  3. In Subnet Details, edit the IP and check 'Resolve Conflict' once cleared.\n";
                } else {
                    $output .= "✅ STATUS: NO ACTIVE CONFLICT DETECTED\n";
                    $output .= "Conflict Probability : 0% | Confidence: " . $confidence_rating . "\n\n";
                    $output .= "Host responses, multi-probe MAC stability, and L2 forwarding topology appear steady and consistent.\n";
                }
            }
        } elseif ($action === 'loop') {
            $db = get_db_connection();
            $output = "=== NetScope Pro: L2 Switching Loop & STP Stability Prober ===\n";
            $output .= "Target           : " . $target . "\n";
            $output .= "Timestamp        : " . date('Y-m-d H:i:s') . "\n";
            $output .= "Runner Platform  : " . ($is_windows ? "Windows" : "Linux / Docker") . "\n";
            $output .= "------------------------------------------------------------\n\n";

            // Find switch by IP or Name
            $stmt_sw = $db->prepare("SELECT * FROM switches WHERE ip_addr = ? OR name = ?");
            $stmt_sw->execute([$target, $target]);
            $sw = $stmt_sw->fetch(PDO::FETCH_ASSOC);

            if (!$sw) {
                // Check if target is an IP that is mapped to a switch port
                $stmt_map = $db->prepare("
                    SELECT s.* 
                    FROM ip_addresses ip 
                    JOIN switch_port_map spm ON spm.mac_addr = ip.mac_addr 
                    JOIN switches s ON s.id = spm.switch_id 
                    WHERE ip.ip_addr = ? 
                    LIMIT 1
                ");
                $stmt_map->execute([$target]);
                $sw = $stmt_map->fetch(PDO::FETCH_ASSOC);
                if ($sw) {
                    $output .= "  • Host IP $target is connected through Switch: {$sw['name']} ({$sw['ip_addr']})\n\n";
                }
            }

            if (!$sw && !filter_var($target, FILTER_VALIDATE_IP)) {
                $output .= "Error: Target is neither a known switch nor a valid IP address.\n";
            } else {
                $sw_ip = $sw ? $sw['ip_addr'] : $target;
                $community = $sw['community'] ?? 'public';
                $sw_name = $sw['name'] ?? $target;

                $output .= "[Phase 1] Switch Identity & Bridge Architecture\n";
                $output .= "  • Switch Name      : " . $sw_name . "\n";
                $output .= "  • Management IP    : " . $sw_ip . "\n";

                $has_snmp = extension_loaded('snmp');
                if (!$has_snmp) {
                    $output .= "  ❌ PHP SNMP extension is not loaded in this environment.\n";
                    $output .= "     Showing historical diagnostic data from NetScope database:\n";
                } else {
                    snmp_set_quick_print(1);
                    snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
                }

                // Query SNMP Bridge Info
                $sys_descr = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.1.1.0") : false;
                $bridge_mac_raw = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.1.1.0") : false;
                $bridge_num_ports = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.1.2.0") : false;

                $bridge_mac = null;
                if ($bridge_mac_raw) {
                    $b_clean = trim($bridge_mac_raw, '" ');
                    if (strlen($b_clean) === 6) {
                        $bridge_mac = strtoupper(implode(':', str_split(bin2hex($b_clean), 2)));
                    } else {
                        $bridge_mac = strtoupper(str_replace(' ', ':', $b_clean));
                    }
                }

                $output .= "  • System Descr     : " . ($sys_descr ?: ($sw['system_info'] ?? 'Unknown / Unreachable')) . "\n";
                $output .= "  • Bridge Base MAC  : " . ($bridge_mac ?: 'Not available') . "\n";
                $output .= "  • Total Ports      : " . ($bridge_num_ports ?: ($sw['total_ports'] ?? 'Unknown')) . "\n\n";

                // Phase 2: Spanning Tree Protocol (STP)
                $output .= "[Phase 2] Spanning Tree Protocol (STP / RSTP) Configuration\n";
                $stp_proto_raw = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.1.0") : false;
                $stp_root_raw = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.5.0") : false;
                $stp_root_cost = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.6.0") : false;
                $stp_root_port = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.7.0") : false;

                $stp_name = 'Not Supported / Disabled';
                if ($stp_proto_raw !== false) {
                    $s_val = (int)trim(str_replace(['INTEGER: ', '"'], '', $stp_proto_raw));
                    $stp_name = match($s_val) {
                        2 => 'decLb100',
                        3 => 'STP (IEEE 802.1D)',
                        4 => 'RSTP (IEEE 802.1w Rapid Spanning Tree)',
                        default => 'Active (Custom)'
                    };
                } elseif (!empty($sw['stp_protocol']) && $sw['stp_protocol'] !== 'none') {
                    $stp_name = $sw['stp_protocol'] . ' (From DB)';
                }

                $output .= "  • Protocol Type    : " . $stp_name . "\n";
                if ($stp_root_raw) {
                    $r_clean = trim($stp_root_raw, '" ');
                    $r_hex = strlen($r_clean) === 8 ? strtoupper(implode(':', str_split(bin2hex($r_clean), 2))) : $r_clean;
                    $output .= "  • Designated Root  : " . $r_hex . "\n";
                    $output .= "  • Path Cost to Root: " . ($stp_root_cost ?: '0') . "\n";
                    $output .= "  • Root Bridge Port : " . ($stp_root_port ?: 'Self (Root Bridge)') . "\n";
                }
                $output .= "\n";

                // Phase 3: STP Port States & Loop Guard Analysis
                $output .= "[Phase 3] STP Port Operational States & Loop Guard Analysis\n";
                $stp_port_states_raw = $has_snmp ? @snmprealwalk($sw_ip, $community, ".1.3.6.1.2.1.17.2.15.1.3") : false;
                $blocked_ports = [];
                $forwarding_ports = [];
                $other_ports = [];

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
                        if ($s_name === 'blocking') {
                            $blocked_ports[] = "Port #$bport";
                        } elseif ($s_name === 'forwarding') {
                            $forwarding_ports[] = "Port #$bport";
                        } else {
                            $other_ports[] = "Port #$bport ($s_name)";
                        }
                    }
                } elseif ($sw) {
                    // Fallback to DB
                    $db_ports = $db->prepare("SELECT port_name, stp_state, port_status FROM switch_port_map WHERE switch_id = ?");
                    $db_ports->execute([$sw['id']]);
                    foreach ($db_ports->fetchAll(PDO::FETCH_ASSOC) as $dp) {
                        if ($dp['stp_state'] === 'blocking') {
                            $blocked_ports[] = $dp['port_name'];
                        } elseif ($dp['stp_state'] === 'forwarding') {
                            $forwarding_ports[] = $dp['port_name'];
                        }
                    }
                }

                $output .= "  • Forwarding Ports : " . count($forwarding_ports) . " active\n";
                $output .= "  • Blocked Ports    : " . count($blocked_ports) . "\n";
                if (!empty($blocked_ports)) {
                    $output .= "    ⚠️ WARNING: Blocked ports detected: " . implode(', ', $blocked_ports) . "\n";
                    $output .= "    (STP has disabled forwarding on these ports to break a detected network loop)\n";
                }
                $output .= "\n";

                // Phase 4: Topology Stability & TCN (Topology Change) Analysis
                $output .= "[Phase 4] Topology Stability & TCN (Topology Change) Analysis\n";
                $tcn_count_raw = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.4.0") : false;
                $tcn_time_raw = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.3.0") : false;

                $tcn_count = ($tcn_count_raw !== false) ? (int)trim(str_replace(['INTEGER: ', 'Counter32: '], '', $tcn_count_raw)) : ($sw['stp_topology_changes'] ?? 0);
                $time_since_tcn_ticks = ($tcn_time_raw !== false) ? (int)trim(str_replace(['Timeticks: (', ')', 'INTEGER: '], '', $tcn_time_raw)) : 0;
                $time_since_tcn_sec = (int)($time_since_tcn_ticks / 100);

                // Format human-readable time since last TCN
                $tcn_time_human = 'Unknown';
                if ($time_since_tcn_sec > 0) {
                    $days = floor($time_since_tcn_sec / 86400);
                    $hours = floor(($time_since_tcn_sec % 86400) / 3600);
                    $mins = floor(($time_since_tcn_sec % 3600) / 60);
                    $secs = $time_since_tcn_sec % 60;
                    if ($days > 0) $tcn_time_human = "{$days}d {$hours}h {$mins}m ago";
                    elseif ($hours > 0) $tcn_time_human = "{$hours}h {$mins}m {$secs}s ago";
                    elseif ($mins > 0) $tcn_time_human = "{$mins}m {$secs}s ago";
                    else $tcn_time_human = "{$secs}s ago";
                }

                $tcn_stability = 'HIGH (Stable)';
                $tcn_indicator = 'Normal';
                $is_unstable = false;

                if ($time_since_tcn_sec > 0 && $time_since_tcn_sec < 60) {
                    $is_unstable = true;
                    $tcn_stability = 'CRITICAL / ACTIVE TCN';
                    $tcn_indicator = "⚠️ Active recalculation occurred {$time_since_tcn_sec}s ago (< 1 min)! Spanning tree recalculating.";
                } elseif ($time_since_tcn_sec > 0 && $time_since_tcn_sec < 300) {
                    $tcn_stability = 'MODERATE';
                    $tcn_indicator = "⚠️ Recent topology change {$tcn_time_human} (< 5 min). Monitor for link bounce.";
                } else {
                    $tcn_indicator = 'Quiet (Last change: ' . $tcn_time_human . ')';
                }

                $output .= "  • Total Topology Changes (TCN) : " . $tcn_count . " events\n";
                $output .= "  • Time Since Last TCN Event    : " . $tcn_time_human . " (" . $time_since_tcn_sec . "s)\n";
                $output .= "  • Recent TCN Activity          : " . $tcn_indicator . "\n";
                $output .= "  • STP Topology Stability       : " . $tcn_stability . "\n";
                $output .= "\n";

                // Phase 5: FDB CAM Table & MAC Flapping Detection
                $output .= "[Phase 5] FDB CAM Table & MAC Flapping Detection\n";
                $has_flapping = false;
                $flapping_info = null;

                if ($sw) {
                    if (!empty($sw['loop_detected']) && stripos($sw['loop_details'], 'flapping') !== false) {
                        $has_flapping = true;
                        $flapping_info = $sw['loop_details'];
                        $output .= "  • Recorded Flapping Status     : ⚠️ ACTIVE (" . $flapping_info . ")\n";
                    } else {
                        $output .= "  • Recorded Flapping Status     : Clean (No active thrashing flags)\n";
                    }

                    // Check if multiple active ports share duplicate MAC addresses
                    $stmt_dups = $db->prepare("
                        SELECT mac_addr, COUNT(DISTINCT port_name) as port_cnt, GROUP_CONCAT(DISTINCT port_name SEPARATOR ', ') as ports
                        FROM switch_port_map
                        WHERE switch_id = ? AND mac_addr NOT LIKE 'PORT:%'
                        GROUP BY mac_addr
                        HAVING port_cnt > 1
                        LIMIT 5
                    ");
                    $stmt_dups->execute([$sw['id']]);
                    $dups = $stmt_dups->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($dups)) {
                        $has_flapping = true;
                        $output .= "  ⚠️ Multi-Port MAC Oscillation Detected:\n";
                        foreach ($dups as $d) {
                            $output .= "     - MAC {$d['mac_addr']} active on {$d['port_cnt']} ports: {$d['ports']}\n";
                        }
                    } else {
                        $output .= "  • CAM Table Distribution       : Clean (MACs are uniquely mapped to single physical ports)\n";
                    }
                }
                $output .= "\n";

                // Phase 6: Evidence Checklist & Confidence Analysis
                $output .= "[Phase 6] Evidence Checklist & Confidence Evaluation\n";
                $loop_evidence = [];
                $loop_confidence = 0;
                $loop_risk = 0;

                // 1. STP Protocol
                if ($stp_name !== 'Not Supported / Disabled') {
                    $loop_confidence += 30;
                    $loop_evidence[] = "✓ Spanning Tree Protocol   : ENABLED ($stp_name)";
                } else {
                    $loop_risk += 30;
                    $loop_evidence[] = "⚠ Spanning Tree Protocol   : DISABLED / UNRESPONSIVE (No BPDU loop protection)";
                }

                // 2. Blocked Ports
                if (!empty($blocked_ports)) {
                    $loop_risk += 60;
                    $loop_confidence += 30;
                    $loop_evidence[] = "✗ STP Loop Guard Action    : ⚠️ BLOCKING DETECTED (" . count($blocked_ports) . " ports quarantined)";
                } else {
                    $loop_confidence += 30;
                    $loop_evidence[] = "✓ STP Loop Guard Action    : 0 blocked ports (No loops currently quarantined)";
                }

                // 3. TCN Stability
                if ($is_unstable) {
                    $loop_risk += 35;
                    $loop_confidence += 20;
                    $loop_evidence[] = "✗ Topology Change Rate     : ⚠️ UNSTABLE (TCN occurred {$time_since_tcn_sec} seconds ago)";
                } else {
                    $loop_confidence += 20;
                    $loop_evidence[] = "✓ Topology Change Rate     : STABLE ($tcn_time_human since last TCN)";
                }

                // 4. MAC Flapping / Thrashing
                if ($has_flapping) {
                    $loop_risk += 40;
                    $loop_confidence += 20;
                    $loop_evidence[] = "✗ CAM Table Distribution   : ⚠️ MAC FLAPPING / OSCILLATION DETECTED";
                } else {
                    $loop_confidence += 20;
                    $loop_evidence[] = "✓ CAM Table Distribution   : CLEAN (No MAC thrashing observed)";
                }

                $loop_conf_label = $loop_confidence >= 80 ? 'HIGH (' . $loop_confidence . '%)' : ($loop_confidence >= 50 ? 'MEDIUM (' . $loop_confidence . '%)' : 'LOW (' . $loop_confidence . '%)');

                foreach ($loop_evidence as $lev) {
                    $output .= "  $lev\n";
                }
                $output .= "  • Diagnostic Confidence   : " . $loop_conf_label . "\n";
                $output .= "\n";

                // Phase 7: Verdict & Recommendations
                $output .= "==================== VERDICT ====================\n";
                if (!empty($blocked_ports)) {
                    $output .= "🚨 STATUS: SWITCHING LOOP MITIGATED BY STP (ACTIVE BLOCKING)\n";
                    $output .= "Risk Level: CRITICAL (Loop Broken by STP Guard) | Confidence: $loop_conf_label\n\n";
                    $output .= "Key Findings:\n";
                    $output .= "  [!] STP blocked port(s): " . implode(', ', $blocked_ports) . " to isolate an active switching loop.\n";
                    $output .= "  [!] A physical cable loop or unmanaged bridge exists between the blocked port and uplink.\n\n";
                    $output .= "Recommended Actions:\n";
                    $output .= "  1. Trace cables connected to " . implode(', ', $blocked_ports) . " — check for accidental cross-connects or patch loops.\n";
                    $output .= "  2. Look for unmanaged switches or access points plugged into multiple wall jacks.\n";
                    $output .= "  3. Ensure STP priority and root bridge settings are properly designated across the core.\n";
                } elseif ($has_flapping || $is_unstable) {
                    $output .= "⚠️ STATUS: TOPOLOGY INSTABILITY / SUSPECTED MAC FLAPPING\n";
                    $output .= "Risk Level: ELEVATED (Link Flap or Intermittent Loop) | Confidence: $loop_conf_label\n\n";
                    $output .= "Key Findings:\n";
                    if ($has_flapping) {
                        $output .= "  [!] Rapid MAC flapping observed: " . ($flapping_info ?: 'Multiple ports reporting identical MACs') . ".\n";
                    }
                    if ($is_unstable) {
                        $output .= "  [!] Rapid Spanning Tree recalculation detected within the last 60 seconds ({$time_since_tcn_sec} seconds ago).\n";
                    }
                    $output .= "\nRecommended Actions:\n";
                    $output .= "  1. Check switch port error counters (CRC errors, link flaps) on flapping interfaces.\n";
                    $output .= "  2. Verify if Edge ports (PC, printers) have 'STP Edge / PortFast' enabled to prevent TCN storms.\n";
                } elseif ($stp_name === 'Not Supported / Disabled') {
                    $output .= "⚠️ STATUS: STP NOT DETECTED (NO LOOP PROTECTION)\n";
                    $output .= "Risk Level: WARNING | Confidence: $loop_conf_label\n\n";
                    $output .= "Key Findings:\n";
                    $output .= "  [!] Spanning Tree Protocol (STP/RSTP) is not enabled or not reporting on this device.\n";
                    $output .= "  [!] Without STP, any accidental loop will cause a broadcast storm.\n\n";
                    $output .= "Recommended Actions:\n";
                    $output .= "  1. Enable RSTP (Rapid Spanning Tree Protocol) on this switch via console/web GUI.\n";
                    $output .= "  2. Enable BPDU Guard / Loop Protect on edge access ports.\n";
                } else {
                    $output .= "✅ STATUS: NO ACTIVE LOOP INDICATORS DETECTED ON TARGET SWITCH\n";
                    $output .= "Risk Level: CLEAN (Stable Forwarding) | Confidence: $loop_conf_label\n\n";
                    $output .= "Key Findings:\n";
                    $output .= "  • Switch $sw_name has stable STP topology ($stp_name) with 0 blocked ports.\n";
                    $output .= "  • MAC learning and frame forwarding operate normally without CAM thrashing.\n";
                    $output .= "  • Topology change rate is quiet (no active TCN recalculations in the last 60 seconds).\n\n";
                    $output .= "Scope & Reliability Note:\n";
                    $output .= "  • Diagnostic scope covers the boundary of this switch and directly polled interfaces.\n";
                    $output .= "  • Unmanaged downstream hubs without STP may not reflect upstream unless MAC thrashing occurs.\n";
                }
            }
        }
    } else {
        $output = "Error: Invalid target format.";
    }
}
?>

<div class="grid-side-detail">
    <!-- Tools Sidebar/Selector -->
    <div class="card">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
            <div style="background: rgba(59, 130, 246, 0.1); padding: 8px; border-radius: 50%; color: var(--primary);">
                <i data-lucide="wrench" style="width: 20px;"></i>
            </div>
            <h3 style="font-size: 1.125rem;">Network Tools</h3>
        </div>
        <form action="" method="POST">
            <div class="input-group">
                <label>Target Host / Switch (IP / Name / MAC)</label>
                <input type="text" name="target" value="<?php echo htmlspecialchars($target); ?>" class="input-control" placeholder="e.g. 192.168.1.1 or switch name" required>
                <?php
                try {
                    $db_tools = get_db_connection();
                    $quick_switches = $db_tools->query("SELECT id, name, ip_addr FROM switches ORDER BY name ASC LIMIT 6")->fetchAll();
                    if (!empty($quick_switches)):
                ?>
                <div style="margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 4px;">
                    <span style="font-size: 0.7rem; color: var(--text-muted); width: 100%;">Pilih Switch Cepat:</span>
                    <?php foreach ($quick_switches as $qs): ?>
                    <button type="button" onclick="document.querySelector('input[name=target]').value='<?php echo htmlspecialchars($qs['ip_addr']); ?>'; document.querySelector('button[value=loop]').click();" style="font-size: 0.7rem; padding: 2px 6px; background: var(--surface-light); border: 1px solid var(--border); border-radius: 4px; color: var(--primary); cursor: pointer;">
                        <?php echo htmlspecialchars($qs['name']); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php 
                    endif;
                } catch(Exception $e) {} 
                ?>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 0.8rem; margin-top: 1.5rem;">
                <?php 
                    $active_action = $action ?: 'conflict'; 
                ?>
                <button type="submit" name="action" value="conflict" class="btn <?php echo $active_action === 'conflict' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'conflict' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="shield-alert" style="width: 16px;"></i> Cek Konflik IP (Prober)
                </button>
                <button type="submit" name="action" value="loop" class="btn <?php echo $active_action === 'loop' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'loop' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="refresh-cw" style="width: 16px;"></i> Cek Looping L2 (Loop Detector)
                </button>
                <button type="submit" name="action" value="ping" class="btn <?php echo $active_action === 'ping' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'ping' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="radio" style="width: 16px;"></i> Ping Utility
                </button>
                <button type="submit" name="action" value="trace" class="btn <?php echo $active_action === 'trace' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'trace' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="git-merge" style="width: 16px;"></i> Traceroute
                </button>
                <button type="submit" name="action" value="oui" class="btn <?php echo $active_action === 'oui' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'oui' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="search" style="width: 16px;"></i> OUI Lookup (MAC)
                </button>
            </div>
        </form>
    </div>

    <!-- Output Area -->
    <div style="min-width: 0;">
        <?php if (!empty($output)): ?>
            <div class="card" style="background: rgba(0,0,0,0.4); border-color: var(--border); min-height: 400px; display: flex; flex-direction: column; overflow: hidden; backdrop-filter: blur(4px);">
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 1rem; margin-bottom: 1rem;">
                    <h3 style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="terminal" style="width: 14px;"></i> Terminal Output
                    </h3>
                    <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo date('H:i:s'); ?></span>
                </div>
                <div class="table-responsive" style="flex: 1;">
                    <pre style="color: #60a5fa; font-family: monospace; font-size: 0.875rem; line-height: 1.6; white-space: pre-wrap; word-break: break-all;"><?php echo htmlspecialchars($output); ?></pre>
                </div>
            </div>
        <?php else: ?>
            <div class="card" style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; border-style: dashed; opacity: 0.5;">
                <div style="background: var(--surface-light); padding: 1.5rem; border-radius: 50%; margin-bottom: 1.5rem;">
                    <i data-lucide="terminal" style="width: 48px; height: 48px; color: var(--text-muted);"></i>
                </div>
                <h3 style="color: var(--text-muted); font-size: 1.25rem;">Ready to Execute</h3>
                <p style="font-size: 0.875rem; color: var(--text-muted); max-width: 300px; text-align: center;">Select a tool and provide a target host from the panel to start diagnostics.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
