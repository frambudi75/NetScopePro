<?php
/**
 * NetScope Pro - L2 Loop Detective Forensic Helper
 * Analyzes switching loops, MAC flapping/thrashing, STP blocking states,
 * and traces the investigation path from core to downstream culprit device.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/network.php';

class LoopDetectiveHelper {

    /**
     * Determine if a port is likely an Uplink/Trunk/Backbone link vs an Access/Edge port
     */
    /**
     * Determine if a port is an Uplink/Trunk link vs an Access/Edge port
     */
    public static function isUplinkPort(string $port_name, string $port_alias = '', int $mac_count = 0, array $tagged_ports = []): bool {
        // Strip switch name prefix if formatted as "Switch Name (Port Name)"
        if (preg_match('/\(([^)]+)\)/', $port_name, $m)) {
            $port_name = $m[1];
        }
        $port_name = trim($port_name);

        // A port carrying multiple MACs is a trunk/uplink link, never a single-host access port
        if ($mac_count > 3) return true;

        // A port carrying 802.1Q tagged VLANs is a trunk link
        if (!empty($tagged_ports) && in_array($port_name, $tagged_ports, true)) return true;

        // Standard enterprise uplink/trunk port naming conventions across vendors (Alcatel, Cisco, MikroTik, HP, Huawei)
        if (preg_match('/(-to-|-sw|uplink|trunk|core|dist|po\d+|bond|ae\d+|sfp|\/48|\/49|\/50|\/51|\/52|\/24|\/25|\/26)/i', $port_name)) return true;
        if (!empty($port_alias) && preg_match('/(uplink|trunk|core|dist|switch|to\s)/i', $port_alias)) return true;

        return false;
    }

    /**
     * Get all managed switches with basic loop & STP summary
     */
    public static function getSwitches(PDO $db): array {
        $stmt = $db->query("
            SELECT s.id, s.name, s.ip_addr, s.model, s.loop_detected, s.loop_details, s.stp_topology_changes,
                   s.stp_enabled, s.stp_protocol,
                   COUNT(spm.id) as total_ports,
                   SUM(CASE WHEN spm.stp_state = 'blocking' AND LOWER(spm.port_status) = 'up' THEN 1 ELSE 0 END) as blocked_ports,
                   SUM(CASE WHEN spm.stp_state = 'forwarding' THEN 1 ELSE 0 END) as forwarding_ports
            FROM switches s
            LEFT JOIN switch_port_map spm ON s.id = spm.switch_id
            GROUP BY s.id
            ORDER BY blocked_ports DESC, s.loop_detected DESC, s.name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Analyze a single target switch and build forensic data
     */
    public static function analyze(PDO $db, int $switch_id): ?array {
        $stmt = $db->prepare("SELECT * FROM switches WHERE id = ?");
        $stmt->execute([$switch_id]);
        $switch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$switch) {
            return null;
        }

        // Cache port MAC counts on this switch to differentiate Access vs Uplink
        $port_mac_counts = [];
        try {
            $pmStmt = $db->prepare("
                SELECT port_name, COUNT(*) as cnt 
                FROM switch_port_map 
                WHERE switch_id = ? AND mac_addr NOT LIKE 'PORT:%' 
                GROUP BY port_name
            ");
            $pmStmt->execute([$switch_id]);
            $port_mac_counts = $pmStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Exception $e) {}

        // Cache tagged VLAN ports on this switch
        $tagged_ports = [];
        try {
            $tagStmt = $db->prepare("SELECT DISTINCT port_name FROM switch_port_vlans WHERE switch_id = ? AND is_tagged = 1");
            $tagStmt->execute([$switch_id]);
            $tagged_ports = $tagStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) {}

        // Helper to validate host MAC (exclude dummy, broadcast, multicast, VRRP)
        $isValidHostMac = function($mac) {
            if (empty($mac) || strlen($mac) !== 17) return false;
            $m = strtoupper($mac);
            if (str_starts_with($m, '00:00:00:')) return false;
            if ($m === 'FF:FF:FF:FF:FF:FF') return false;
            $firstOctet = hexdec(substr($m, 0, 2));
            if ($firstOctet & 1) return false; // Multicast
            if (str_starts_with($m, '00:00:5E:') || str_starts_with($m, '00:00:0C:')) return false; // VRRP/HSRP
            return true;
        };

        // 1. Fetch port STP operational states
        $pStmt = $db->prepare("
            SELECT port_name, mac_addr, vlan_name, port_status, stp_state, port_speed
            FROM switch_port_map
            WHERE switch_id = ?
            ORDER BY port_name ASC
        ");
        $pStmt->execute([$switch_id]);
        $all_ports = $pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $blocked_ports = [];
        $forwarding_ports = [];
        $down_ports = [];

        foreach ($all_ports as $p) {
            $is_up = strtolower($p['port_status'] ?? '') === 'up';
            if (!$is_up) {
                $down_ports[] = $p['port_name'];
            } elseif ($p['stp_state'] === 'blocking') {
                $blocked_ports[] = $p['port_name'];
            } elseif ($p['stp_state'] === 'forwarding') {
                $forwarding_ports[] = $p['port_name'];
            }
        }

        // 2. Detect MAC Thrashing / Flapping across ports
        $flapping_macs = [];

        // Strategy A: Directly check switches.loop_details from recent poller telemetry (only if loop is actively flagged)
        if (!empty($switch['loop_detected']) && !empty($switch['loop_details'])) {
            // Only parse if it represents an actual loop or quarantine (not an obsolete single-MAC move)
            $is_obsolete_single = (stripos($switch['loop_details'], 'End-device thrashing on Access port') !== false && stripos($switch['loop_details'], 'High-frequency') === false);
            if (!$is_obsolete_single) {
                if (preg_match('/([0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2})/', $switch['loop_details'], $m)) {
                    $parsed_mac = strtoupper(str_replace('-', ':', $m[1]));
                    if ($isValidHostMac($parsed_mac)) {
                        $port_match = '';
                        if (preg_match('/(?:Access port|port|Port)\s+([a-zA-Z0-9_\-\.\/]+)/i', $switch['loop_details'], $pm)) {
                            $port_match = $pm[1];
                        }

                        // Resolve candidate uplink port on this switch
                        $candidate_uplink = !empty($blocked_ports) ? $blocked_ports[0] : null;
                        if (!$candidate_uplink) {
                            foreach ($all_ports as $ap) {
                                $ap_name = $ap['port_name'];
                                if ($port_match && $ap_name === $port_match) continue;
                                $cnt = $port_mac_counts[$ap_name] ?? 0;
                                if (self::isUplinkPort($ap_name, '', $cnt, $tagged_ports)) {
                                    $candidate_uplink = $ap_name;
                                    break;
                                }
                            }
                        }

                        $uplink_label = $candidate_uplink ? "{$candidate_uplink} (Uplink)" : "Uplink";
                        $flapping_macs[] = [
                            'mac_addr'            => $parsed_mac,
                            'port_cnt'            => 2,
                            'port_pair'           => $port_match ? "{$port_match} ↔ {$uplink_label}" : "Access ↔ {$uplink_label}",
                            'origin_port'         => $port_match ?: null,
                            'origin_switch_name'  => $switch['name'],
                            'transit_port'        => $candidate_uplink ?: 'Uplink'
                        ];
                    }
                }
            }
        }

        // Strategy B: Active flapping events logged in ip_conflict_events for ports on THIS switch
        if (empty($flapping_macs) && !empty($switch['loop_detected'])) {
            try {
                $ceStmt = $db->prepare("
                    SELECT mac_a as mac_addr, flap_count as port_cnt,
                           switch_port_a, switch_port_b,
                           CONCAT(switch_port_a, ' ↔ ', switch_port_b) as port_pair
                    FROM ip_conflict_events
                    WHERE event_type = 'flapping'
                      AND status = 'active'
                      AND flap_count >= 3
                      AND (switch_port_a IS NOT NULL OR switch_port_b IS NOT NULL)
                    ORDER BY detected_at DESC
                    LIMIT 10
                ");
                $ceStmt->execute();
                $ce_rows = $ceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                // Build set of ports on this switch
                $local_port_names = array_column($all_ports, 'port_name');
                foreach ($ce_rows as $row) {
                    // Only match if at least one port belongs to this switch
                    if (in_array($row['switch_port_a'], $local_port_names, true) || in_array($row['switch_port_b'], $local_port_names, true)) {
                        if ($isValidHostMac($row['mac_addr'])) {
                            $flapping_macs[] = $row;
                            break;
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        // Strategy C: Cross-Switch End-Device Access Port Isolation
        // ONLY executed if an actual loop or quarantine is active on this switch!
        $has_loop_indicator = !empty($blocked_ports) || (!empty($switch['loop_detected']) && !empty($flapping_macs));

        if (empty($flapping_macs) && $has_loop_indicator) {
            try {
                // Find candidate uplink port on this switch
                $target_uplink = !empty($blocked_ports) ? $blocked_ports[0] : null;
                if (!$target_uplink) {
                    foreach ($all_ports as $ap) {
                        if (self::isUplinkPort($ap['port_name'], '', $port_mac_counts[$ap['port_name']] ?? 0, $tagged_ports)) {
                            $target_uplink = $ap['port_name'];
                            break;
                        }
                    }
                }

                // Query remote switch access ports carrying the same MAC that traverses this switch's uplink
                if ($target_uplink) {
                    $csQuery = "
                        SELECT spm_remote.mac_addr,
                               s_remote.name as remote_switch_name,
                               s_remote.id as remote_switch_id,
                               spm_remote.port_name as remote_access_port,
                               spm_local.port_name as local_transit_port
                        FROM switch_port_map spm_local
                        JOIN switch_port_map spm_remote ON spm_local.mac_addr = spm_remote.mac_addr AND spm_local.switch_id != spm_remote.switch_id
                        JOIN switches s_remote ON spm_remote.switch_id = s_remote.id
                        WHERE spm_local.switch_id = ?
                          AND spm_local.port_name = ?
                          AND spm_local.mac_addr NOT LIKE 'PORT:%'
                          AND spm_local.mac_addr NOT LIKE '00:00:00:%'
                        ORDER BY spm_remote.updated_at DESC
                        LIMIT 5
                    ";
                    $csStmt = $db->prepare($csQuery);
                    $csStmt->execute([$switch_id, $target_uplink]);
                    $cs_candidates = $csStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    foreach ($cs_candidates as $cand) {
                        if ($isValidHostMac($cand['mac_addr'])) {
                            // Verify remote port is truly an access port (not another 200-MAC trunk)
                            $remCntStmt = $db->prepare("SELECT COUNT(*) FROM switch_port_map WHERE switch_id = ? AND port_name = ? AND mac_addr NOT LIKE 'PORT:%'");
                            $remCntStmt->execute([$cand['remote_switch_id'], $cand['remote_access_port']]);
                            $remCount = (int)$remCntStmt->fetchColumn();

                            if ($remCount <= 3) {
                                $flapping_macs[] = [
                                    'mac_addr'           => $cand['mac_addr'],
                                    'port_cnt'           => 2,
                                    'port_pair'          => "{$cand['remote_switch_name']} ({$cand['remote_access_port']}) ↔ {$switch['name']} ({$cand['local_transit_port']})",
                                    'origin_port'        => $cand['remote_access_port'],
                                    'origin_switch_name' => $cand['remote_switch_name'],
                                    'transit_port'       => $cand['local_transit_port']
                                ];
                                break;
                            }
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        // Strategy D: True Access-to-Access Loop (same MAC on two access ports, e.g. looped cable between 2 wall ports)
        if (empty($flapping_macs) && $has_loop_indicator) {
            try {
                $dupStmt = $db->prepare("
                    SELECT spm1.mac_addr,
                           CONCAT(s1.name, ' (', spm1.port_name, ') ↔ ', s2.name, ' (', spm2.port_name, ')') as port_pair,
                           spm1.port_name as port_1,
                           spm2.port_name as port_2,
                           s1.name as switch_1_name,
                           s2.name as switch_2_name
                    FROM switch_port_map spm1
                    JOIN switch_port_map spm2 ON spm1.mac_addr = spm2.mac_addr AND (spm1.switch_id != spm2.switch_id OR spm1.port_name != spm2.port_name)
                    JOIN switches s1 ON spm1.switch_id = s1.id
                    JOIN switches s2 ON spm2.switch_id = s2.id
                    WHERE spm1.switch_id = ?
                      AND spm1.mac_addr NOT LIKE 'PORT:%'
                      AND spm1.mac_addr NOT LIKE '00:00:00:%'
                    LIMIT 5
                ");
                $dupStmt->execute([$switch_id]);
                $dupRows = $dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($dupRows as $dr) {
                    if ($isValidHostMac($dr['mac_addr'])) {
                        $flapping_macs[] = [
                            'mac_addr'           => $dr['mac_addr'],
                            'port_cnt'           => 2,
                            'port_pair'          => $dr['port_pair'],
                            'origin_port'        => $dr['port_1'],
                            'origin_switch_name' => $dr['switch_1_name'],
                            'transit_port'       => $dr['port_2']
                        ];
                        break;
                    }
                }
            } catch (Exception $e) {}
        }

        // Check recorded loop flags
        $has_recorded_loop = !empty($switch['loop_detected']);
        $recorded_details = $switch['loop_details'] ?? '';

        // 3. Calculate Risk Score (0 - 100%)
        $risk_score = 0;
        $risk_factors = [];

        if (!empty($flapping_macs)) {
            $risk_score += 65;
            $risk_factors[] = "Active CAM Table Thrashing / MAC Flapping (" . count($flapping_macs) . " oscillating MAC: {$flapping_macs[0]['mac_addr']})";
        }
        if (!empty($blocked_ports)) {
            $risk_score += 30;
            $risk_factors[] = "Active STP port quarantine (" . count($blocked_ports) . " ports in BLOCKING state)";
        } elseif ($has_recorded_loop) {
            $risk_score += 15;
            $risk_factors[] = "Recent loop warning recorded in poller telemetry";
        }

        $tcn_count = (int)($switch['stp_topology_changes'] ?? 0);
        if ($tcn_count > 100) {
            $risk_score += 10;
            $risk_factors[] = "High accumulated STP Topology Changes ($tcn_count TCN events)";
        }

        $risk_score = min(100, max(0, $risk_score));

        // 4. Construct Investigation Path
        $investigation_path = self::buildInvestigationPath($db, $switch, $flapping_macs, $blocked_ports, $port_mac_counts, $tagged_ports);

        // 5. Generate Forensic Verdict
        $verdict = self::generateVerdict($switch, $risk_score, $flapping_macs, $blocked_ports, $investigation_path);

        return [
            'switch'             => $switch,
            'risk_score'         => $risk_score,
            'risk_factors'       => $risk_factors,
            'blocked_ports'      => $blocked_ports,
            'forwarding_ports'   => $forwarding_ports,
            'down_ports'         => $down_ports,
            'flapping_macs'      => $flapping_macs,
            'investigation_path' => $investigation_path,
            'verdict'            => $verdict,
            'total_ports'        => count($all_ports)
        ];
    }

    /**
     * Build the multi-level Investigation Path (Core ➔ Target Switch ➔ Downstream Link ➔ Culprit Endpoint)
     */
    private static function buildInvestigationPath(PDO $db, array $switch, array $flapping_macs, array $blocked_ports, array $port_mac_counts = [], array $tagged_ports = []): array {
        $path = [];

        // Level 1: Upstream Core / Parent Switch
        $parent_switch = null;
        if (!empty($switch['parent_switch_id'])) {
            try {
                $pStmt = $db->prepare("SELECT id, name, ip_addr, model FROM switches WHERE id = ?");
                $pStmt->execute([$switch['parent_switch_id']]);
                $parent_switch = $pStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }

        if (!$parent_switch) {
            try {
                $pStmt = $db->prepare("
                    SELECT s.id, s.name, s.ip_addr, s.model, tl.link_label
                    FROM topology_links tl
                    JOIN switches s ON tl.parent_switch_id = s.id
                    WHERE tl.target_type = 'switch' AND tl.target_id = ?
                    LIMIT 1
                ");
                $pStmt->execute([$switch['id']]);
                $parent_switch = $pStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }

        if ($parent_switch) {
            $path[] = [
                'level'       => 1,
                'role'        => 'UPSTREAM_CORE',
                'name'        => $parent_switch['name'],
                'ip'          => $parent_switch['ip_addr'],
                'model'       => $parent_switch['model'] ?: 'Core / Distribution Switch',
                'status'      => 'normal',
                'status_text' => 'Normal L2 Trunk Forwarding',
                'badge'       => 'CORE LINK',
                'badge_color' => 'var(--text-muted)'
            ];
        } else {
            $path[] = [
                'level'       => 1,
                'role'        => 'UPSTREAM_CORE',
                'name'        => 'Upstream Core / Root Path',
                'ip'          => 'L2 Bridge Backbone',
                'model'       => 'Designated Root Path',
                'status'      => 'normal',
                'status_text' => 'Spanning Tree Root Forwarding Active',
                'badge'       => 'ROOT PATH',
                'badge_color' => 'var(--text-muted)'
            ];
        }

        // Level 2: Target Switch (Symptom Observation Point)
        $has_loop_symptom = !empty($flapping_macs) || !empty($blocked_ports) || !empty($switch['loop_detected']);
        $symptom_detail = 'Clean forwarding state';
        if (!empty($flapping_macs)) {
            $first_flap = $flapping_macs[0];
            $symptom_detail = "MAC Thrashing detected across [{$first_flap['port_pair']}]";
        } elseif (!empty($blocked_ports)) {
            $symptom_detail = "Port(s) " . implode(', ', $blocked_ports) . " quarantined in BLOCKING state";
        }

        $path[] = [
            'level'       => 2,
            'role'        => 'TARGET_SWITCH',
            'name'        => $switch['name'],
            'ip'          => $switch['ip_addr'],
            'model'       => $switch['model'] ?: 'Managed Switch',
            'status'      => $has_loop_symptom ? 'critical' : 'normal',
            'status_text' => $symptom_detail,
            'badge'       => $has_loop_symptom ? 'SYMPTOM DETECTED' : 'STABLE',
            'badge_color' => $has_loop_symptom ? 'var(--danger)' : 'var(--success)'
        ];

        // Identify Access vs Uplink ports from flapping or blocked ports
        $first_flap = !empty($flapping_macs) ? $flapping_macs[0] : null;
        $access_port = $first_flap['origin_port'] ?? null;
        $remote_origin_switch = $first_flap['origin_switch_name'] ?? null;
        $uplink_port = !empty($blocked_ports) ? $blocked_ports[0] : ($first_flap['transit_port'] ?? null);

        if (!$access_port && !empty($flapping_macs)) {
            $pairs = explode(' ↔ ', $first_flap['port_pair']);
            foreach ($pairs as $p) {
                $clean_p = trim(preg_replace('/^.*?:/', '', $p));
                if (preg_match('/\(([^)]+)\)/', $clean_p, $m)) {
                    $clean_p = $m[1];
                }
                $cnt = $port_mac_counts[$clean_p] ?? 0;
                if (self::isUplinkPort($clean_p, '', $cnt, $tagged_ports)) {
                    if (!$uplink_port) $uplink_port = $clean_p;
                } else {
                    if (!$access_port) $access_port = $clean_p;
                }
            }
        }

        // Ensure uplink_port is resolved if empty or generic
        if (empty($uplink_port) || strtolower($uplink_port) === 'uplink') {
            foreach ($port_mac_counts as $p => $cnt) {
                if ($access_port && $p === $access_port) continue;
                if (self::isUplinkPort($p, '', $cnt, $tagged_ports)) {
                    $uplink_port = $p;
                    break;
                }
            }
        }

        // If access port still not isolated, check switches.loop_details for port mention
        if (!$access_port && !empty($switch['loop_details'])) {
            if (preg_match('/(?:Access port|port|Port)\s+([a-zA-Z0-9_\-\.\/]+)/i', $switch['loop_details'], $pm)) {
                $pm_port = $pm[1];
                $pm_cnt = $port_mac_counts[$pm_port] ?? 0;
                if (!self::isUplinkPort($pm_port, '', $pm_cnt, $tagged_ports)) {
                    $access_port = $pm_port;
                }
            }
        }

        // Check if any child switch is linked from this switch
        $downstream_switch = null;
        try {
            $cStmt = $db->prepare("
                SELECT s.id, s.name, s.ip_addr, s.model, tl.link_label
                FROM topology_links tl
                JOIN switches s ON tl.target_id = s.id
                WHERE tl.parent_switch_id = ? AND tl.target_type = 'switch'
                LIMIT 1
            ");
            $cStmt->execute([$switch['id']]);
            $downstream_switch = $cStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        if (!$downstream_switch) {
            try {
                $cStmt = $db->prepare("SELECT id, name, ip_addr, model FROM switches WHERE parent_switch_id = ? LIMIT 1");
                $cStmt->execute([$switch['id']]);
                $downstream_switch = $cStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }

        if ($has_loop_symptom) {
            // Level 3: Downstream Target / Access Link
            if ($access_port && !empty($remote_origin_switch) && $remote_origin_switch !== $switch['name']) {
                $path[] = [
                    'level'       => 3,
                    'role'        => 'ACCESS_PORT',
                    'name'        => "Access Link (Switch {$remote_origin_switch}, Port {$access_port})",
                    'ip'          => 'Remote Edge Host Segment',
                    'model'       => "Access Port pada switch {$remote_origin_switch}",
                    'status'      => 'warning',
                    'status_text' => "Perangkat fisik tercolok di port Access {$access_port} switch {$remote_origin_switch}. Frame looping menyeberang via Uplink ke {$switch['name']}" . ($uplink_port && strtolower($uplink_port) !== 'uplink' ? " ({$uplink_port})" : ""),
                    'badge'       => 'REMOTE ORIGIN (ROOT CAUSE)',
                    'badge_color' => '#f97316'
                ];
            } elseif ($access_port) {
                $path[] = [
                    'level'       => 3,
                    'role'        => 'ACCESS_PORT',
                    'name'        => "Access Link (Port {$access_port})",
                    'ip'          => 'Edge Host Segment',
                    'model'       => 'Access Port ke End-Device',
                    'status'      => 'warning',
                    'status_text' => "Port fisik asal di switch ini yang terhubung langsung ke end-device (Root Cause Origin)",
                    'badge'       => 'ORIGIN LINK (ROOT CAUSE)',
                    'badge_color' => '#f97316'
                ];
            } elseif ($downstream_switch) {
                $path[] = [
                    'level'       => 3,
                    'role'        => 'DOWNSTREAM_SWITCH',
                    'name'        => $downstream_switch['name'],
                    'ip'          => $downstream_switch['ip_addr'],
                    'model'       => $downstream_switch['model'] ?: 'Downstream Switch',
                    'status'      => 'warning',
                    'status_text' => "Connected downstream on {$uplink_port} (Investigate downstream switch ports)",
                    'badge'       => 'INVESTIGATE TARGET',
                    'badge_color' => 'var(--warning)'
                ];
            } else {
                $downstream_label = ($uplink_port && strtolower($uplink_port) !== 'uplink') ? "Port {$uplink_port}" : 'Inter-Switch Trunk Link';
                $path[] = [
                    'level'       => 3,
                    'role'        => 'DOWNSTREAM_SEGMENT',
                    'name'        => "Impacted Uplink Trunk ({$downstream_label})",
                    'ip'          => 'Transit Backbone Link (Multi-Host)',
                    'model'       => 'Trunk / Uplink Segment',
                    'status'      => 'warning',
                    'status_text' => "Port {$downstream_label} adalah jalur Uplink/Trunk (bukan colokan end-device). Frame loop masuk dari switch tetangga melalui port ini.",
                    'badge'       => 'QUARANTINED UPLINK (VICTIM)',
                    'badge_color' => '#ef4444'
                ];
            }

            // Level 4: Endpoint / Culprit Device (Resolve MAC to IP & Vendor)
            if (!empty($flapping_macs)) {
                $bouncing_mac = $flapping_macs[0]['mac_addr'];
                $vendor = function_exists('get_vendor_by_mac') ? get_vendor_by_mac($bouncing_mac) : 'Unknown Vendor';

                // Look up in ip_addresses with flexible formatting
                $clean_mac = strtoupper(preg_replace('/[^0-9A-F]/i', '', (string)$bouncing_mac));
                $colon_mac = implode(':', str_split($clean_mac, 2));
                $dash_mac = implode('-', str_split($clean_mac, 2));

                $ipStmt = $db->prepare("
                    SELECT ip_addr, hostname, vendor, description 
                    FROM ip_addresses 
                    WHERE mac_addr IN (?, ?, ?) 
                       OR UPPER(REPLACE(REPLACE(mac_addr, ':', ''), '-', '')) = ? 
                    LIMIT 1
                ");
                $ipStmt->execute([$bouncing_mac, $colon_mac, $dash_mac, $clean_mac]);
                $ipRow = $ipStmt->fetch(PDO::FETCH_ASSOC);

                if (!$ipRow) {
                    try {
                        $saStmt = $db->prepare("SELECT ip_address as ip_addr, name as hostname, '' as vendor, '' as description FROM server_assets WHERE UPPER(REPLACE(REPLACE(mac_address, ':', ''), '-', '')) = ? LIMIT 1");
                        $saStmt->execute([$clean_mac]);
                        $ipRow = $saStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {}
                }

                $clean_vendor = ($vendor && $vendor !== 'Generic / Unknown' && $vendor !== 'Unknown Vendor') ? $vendor : null;
                $dev_name = $ipRow['hostname'] ?? ($clean_vendor ? "$clean_vendor Device" : "End-Device / Host");
                if ($clean_vendor === 'Cisco Meraki' && empty($ipRow['hostname'])) {
                    $dev_name = "Cisco Meraki (Access Point / Router)";
                }

                $dev_ip = $ipRow['ip_addr'] ?? 'Unknown / Non-ARP L2';
                $dev_desc = $ipRow['description'] ?? ("MAC: " . strtoupper($bouncing_mac));

                if ($access_port && !empty($remote_origin_switch) && $remote_origin_switch !== $switch['name']) {
                    $origin_note = "Tercolok di port Access {$access_port} pada switch {$remote_origin_switch}. ";
                } elseif ($access_port) {
                    $origin_note = "Tercolok di port Access {$access_port}. ";
                } else {
                    $origin_note = "Periksa switch tetangga yang terhubung ke port " . (($uplink_port && strtolower($uplink_port) !== 'uplink') ? $uplink_port : 'Uplink') . ". ";
                }

                $uplink_str = "";
                if ($uplink_port && strtolower($uplink_port) !== 'uplink') {
                    $uplink_str = " (Port {$uplink_port})";
                } elseif (!empty($parent_switch)) {
                    $uplink_str = " (menuju {$parent_switch['name']})";
                }

                $culprit_info = [
                    'level'       => 4,
                    'role'        => 'CULPRIT_DEVICE',
                    'name'        => $dev_name,
                    'ip'          => $dev_ip,
                    'model'       => "$vendor (" . strtoupper($bouncing_mac) . ")",
                    'status'      => 'culprit',
                    'status_text' => "{$origin_note}Frame looping memantul ke Uplink Trunk{$uplink_str}. {$dev_desc}",
                    'badge'       => 'ROOT CAUSE END-DEVICE',
                    'badge_color' => '#ef4444'
                ];
            } else {
                $uplink_str = ($uplink_port && strtolower($uplink_port) !== 'uplink') ? "Port {$uplink_port}" : "Uplink";
                $culprit_info = [
                    'level'       => 4,
                    'role'        => 'CULPRIT_DEVICE',
                    'name'        => 'End-Device / Dual-Connect Cable',
                    'ip'          => 'Periksa Perangkat di Port Access',
                    'model'       => 'IP Phone / PABX / Unmanaged Switch / PC Dual-NIC',
                    'status'      => 'culprit',
                    'status_text' => "{$uplink_str} di-block oleh STP. Cari perangkat di port access yang tercolok 2 kabel atau bridging.",
                    'badge'       => 'ROOT CAUSE SUSPECT',
                    'badge_color' => '#f97316'
                ];
            }
            $path[] = $culprit_info;
        }

        return $path;
    }

    /**
     * Generate Actionable Forensic Verdict and NOC Remediation Instructions
     */
    private static function generateVerdict(array $switch, int $risk_score, array $flapping_macs, array $blocked_ports, array $path): array {
        $sw_name = $switch['name'];

        // Extract culprit info if available from path
        $culprit_node = null;
        $access_node = null;
        foreach ($path as $node) {
            if (($node['role'] ?? '') === 'CULPRIT_DEVICE') $culprit_node = $node;
            if (($node['role'] ?? '') === 'ACCESS_PORT') $access_node = $node;
        }

        if (!empty($blocked_ports)) {
            $blocked_str = implode(', ', $blocked_ports);
            $has_culprit = $culprit_node && $culprit_node['name'] !== 'End-Device / Dual-Connect Cable';

            $summary = "Spanning Tree Protocol telah mem-block port Uplink [{$blocked_str}] pada {$sw_name} untuk mencegah network collapse.";
            if ($has_culprit && $access_node) {
                $summary .= " Analisis forensik menemukan sumber frame loop berasal dari perangkat {$culprit_node['name']} ({$culprit_node['model']}) pada {$access_node['name']}.";
            } else {
                $summary .= " Port [{$blocked_str}] adalah jalur Uplink/Trunk (rem darurat). Sumber fisik loop berada pada port-port Access atau unmanaged switch downstream.";
            }

            $actions = [
                "JANGAN cabut/putus kabel Uplink [{$blocked_str}], karena itu jalur trunk utama distribusi.",
            ];

            if ($has_culprit && $access_node) {
                $actions[] = "Periksa perangkat {$culprit_node['name']} (IP: {$culprit_node['ip']}) pada port fisik " . $access_node['name'] . ".";
                $actions[] = "Pastikan perangkat tersebut tidak dicolok 2 kabel LAN sekaligus (misal port PC & LAN pada IP Phone/PABX), atau matikan interface bridge internalnya.";
                $actions[] = "Aktifkan 'BPDU Guard' dan 'PortFast/Edge' pada port Access tersebut agar jika terjadi loop lokal di kemudian hari, hanya port access itu yang di-shutdown otomatis tanpa mengorbankan Uplink.";
            } else {
                $actions[] = "Telusuri perangkat access (IP Phone, VoIP Gateway, atau unmanaged switch) yang terhubung ke {$sw_name}.";
                $actions[] = "Cek apakah ada kabel patch cord yang tercolok loopback (ujung ke ujung di switch/wallplate yang sama).";
                $actions[] = "Aktifkan BPDU Guard pada seluruh port access non-trunk.";
            }

            return [
                'type'       => 'danger',
                'title'      => 'Active L2 Switching Loop (STP Rem Darurat di Uplink)',
                'summary'    => $summary,
                'evidence'   => [
                    "Port fisik Uplink [{$blocked_str}] berstatus BLOCKING untuk memutus amplifikasi paket.",
                    $has_culprit ? "End-Device Culprit teridentifikasi: {$culprit_node['name']} ({$culprit_node['model']})" : "STP mem-block jalur inter-switch untuk mengisolasi badai broadcast.",
                    "Frame forwarding pada port Uplink yang ter-block saat ini ditahan sementara oleh protokol STP."
                ],
                'scope_note' => "Port Uplink yang ter-block adalah korban mitigasi. Akar masalah loop fisik berada di port Access end-device.",
                'actions'    => $actions
            ];
        }

        if (!empty($flapping_macs)) {
            $flap = $flapping_macs[0];
            $mac_upper = strtoupper($flap['mac_addr']);
            $vendor = function_exists('get_vendor_by_mac') ? get_vendor_by_mac($flap['mac_addr']) : 'Vendor Unknown';

            return [
                'type'       => 'danger',
                'title'      => 'Possible Downstream L2 Switching Loop (MAC Thrashing)',
                'summary'    => "Abnormal MAC oscillation detected on {$sw_name}. MAC address {$mac_upper} ({$vendor}) is bouncing rapidly between ports [{$flap['port_pair']}].",
                'evidence'   => [
                    "CAM Table Thrashing: Single MAC address learned on multiple physical ports ({$flap['port_pair']}) within polling cycle.",
                    "Target device vendor: {$vendor} ({$mac_upper}).",
                    "Switching loops downstream cause identical source MAC frames to enter multiple switch ports concurrently."
                ],
                'scope_note' => "The physical loop is NOT inside {$sw_name} itself. Evidence suggests a loop exists on an access link or downstream switch connected to ports {$flap['port_pair']}.",
                'actions'    => [
                    "Isolate link on port " . explode(' ↔ ', $flap['port_pair'])[0] . " to verify if MAC flapping subsides.",
                    "Check for unmanaged switch or VoIP/PABX equipment connected to ports {$flap['port_pair']}.",
                    "Verify that BPDU Guard and Loop Protect are enabled on access/edge ports."
                ]
            ];
        }

        if ($risk_score > 30) {
            return [
                'type'       => 'warning',
                'title'      => 'Elevated Topology Instability',
                'summary'    => "Elevated topology recalculation activity detected on {$sw_name}. Network convergence is unstable.",
                'evidence'   => [
                    "High accumulated Spanning Tree Topology Change Notifications (TCN).",
                    "Potential link bouncing or rapid interface state transitions on downstream links."
                ],
                'scope_note' => "No active packet loop is quarantined at this moment, but frequent link flaps degrade L2 performance.",
                'actions'    => [
                    "Check port error counters (CRC, runts, link flaps) on connected uplinks.",
                    "Enable STP PortFast / Edge Port on all user-facing access ports to prevent TCN floods."
                ]
            ];
        }

        return [
            'type'       => 'success',
            'title'      => 'Normal Layer-2 Forwarding & Clean Topology',
            'summary'    => "No switching loop indicators, blocked ports, or CAM table thrashing observed on {$sw_name}.",
            'evidence'   => [
                "All operational ports are in stable FORWARDING state.",
                "CAM/FDB Forwarding Database is clean: MAC addresses uniquely mapped to single interfaces.",
                "Spanning Tree Protocol topology is steady with no active recalculations."
            ],
            'scope_note' => "Diagnostic scope covers the boundary of this switch and directly learned physical interfaces.",
            'actions'    => [
                "Maintain periodic STP SNMP polling to ensure link changes are logged automatically.",
                "Ensure new switch additions maintain proper STP priority designations (Core = 4096 / 8192)."
            ]
        ];
    }

    /**
     * Run full 6-phase live SNMP analysis on a target switch
     */
    public static function runDeepProbe(PDO $db, int $switch_id): array {
        $stmt = $db->prepare("SELECT * FROM switches WHERE id = ?");
        $stmt->execute([$switch_id]);
        $sw = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sw) {
            return ['error' => 'Switch not found'];
        }

        $sw_ip = $sw['ip_addr'];
        $community = $sw['community'] ?: 'public';

        // Check reachability
        $sys_descr = @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.1.1.0", 1000000, 1);
        $has_snmp = ($sys_descr !== false);

        // Phase 1: Identity
        $sys_name = $has_snmp ? trim(str_replace(['STRING: ', '"'], '', (string)@snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.1.5.0", 800000, 1))) : $sw['name'];
        $clean_descr = $has_snmp ? trim(str_replace(['STRING: ', '"'], '', (string)$sys_descr)) : ($sw['model'] ?: 'SNMP Off');

        // Phase 2: STP Protocol & Root
        $stp_spec = $has_snmp ? (int)trim(str_replace(['INTEGER: ', '"'], '', (string)@snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.1.0", 800000, 1))) : 0;
        $stp_name = match($stp_spec) {
            1 => 'STP (802.1D)',
            2 => 'None / Disabled',
            3 => 'RSTP (802.1w / Rapid STP)',
            4 => 'MSTP (802.1s / Multiple STP)',
            default => ($has_snmp ? 'Vendor Proprietary / Unknown' : 'Disabled / Offline')
        };

        $root_cost_raw = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.6.0", 800000, 1) : false;
        $root_cost = ($root_cost_raw !== false) ? (int)trim(str_replace(['INTEGER: ', '"'], '', (string)$root_cost_raw)) : 0;

        // Phase 3: Ports
        $blocked_ports = [];
        $forwarding_ports = [];
        $down_ports = [];

        if ($has_snmp) {
            $stp_raw = @snmprealwalk($sw_ip, $community, ".1.3.6.1.2.1.17.2.15.1.3", 1500000, 1);
            $oper_raw = @snmprealwalk($sw_ip, $community, ".1.3.6.1.2.1.2.2.1.8", 1500000, 1);
            $b2if_raw = @snmprealwalk($sw_ip, $community, ".1.3.6.1.2.1.17.1.4.1.2", 1500000, 1);
            $names_raw = @snmprealwalk($sw_ip, $community, ".1.3.6.1.2.1.31.1.1.1.1", 1500000, 1) ?: @snmprealwalk($sw_ip, $community, ".1.3.6.1.2.1.2.2.1.2", 1500000, 1);

            $b2if = [];
            if ($b2if_raw && is_array($b2if_raw)) {
                foreach ($b2if_raw as $oid => $val) {
                    $parts = explode('.', $oid);
                    $bp = end($parts);
                    $b2if[$bp] = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                }
            }

            $opers = [];
            if ($oper_raw && is_array($oper_raw)) {
                foreach ($oper_raw as $oid => $val) {
                    $parts = explode('.', $oid);
                    $ifidx = end($parts);
                    $opers[$ifidx] = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                }
            }

            $names = [];
            if ($names_raw && is_array($names_raw)) {
                foreach ($names_raw as $oid => $val) {
                    $parts = explode('.', $oid);
                    $ifidx = end($parts);
                    $names[$ifidx] = trim(str_replace(['STRING: ', '"'], '', $val));
                }
            }

            if ($stp_raw && is_array($stp_raw)) {
                foreach ($stp_raw as $oid => $val) {
                    $parts = explode('.', $oid);
                    $bp = end($parts);
                    $ifidx = $b2if[$bp] ?? $bp;
                    $pname = $names[$ifidx] ?? "Port #$bp";
                    $sint = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                    $is_up = ($opers[$ifidx] ?? 1) === 1;

                    if (!$is_up) {
                        $down_ports[] = $pname;
                    } elseif ($sint === 2) {
                        $blocked_ports[] = $pname;
                    } elseif ($sint === 5) {
                        $forwarding_ports[] = $pname;
                    }
                }
            }
        }

        // Fallback to DB if SNMP query is incomplete
        if (empty($blocked_ports) && empty($forwarding_ports)) {
            $db_ports = $db->prepare("SELECT port_name, stp_state, port_status FROM switch_port_map WHERE switch_id = ?");
            $db_ports->execute([$switch_id]);
            foreach ($db_ports->fetchAll(PDO::FETCH_ASSOC) as $dp) {
                $is_up = strtolower($dp['port_status'] ?? '') === 'up';
                if (!$is_up) {
                    $down_ports[] = $dp['port_name'];
                } elseif ($dp['stp_state'] === 'blocking') {
                    $blocked_ports[] = $dp['port_name'];
                } elseif ($dp['stp_state'] === 'forwarding') {
                    $forwarding_ports[] = $dp['port_name'];
                }
            }
        }

        // Phase 4: TCN Analysis
        $tcn_count_raw = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.4.0", 800000, 1) : false;
        $tcn_time_raw = $has_snmp ? @snmp2_get($sw_ip, $community, ".1.3.6.1.2.1.17.2.3.0", 800000, 1) : false;

        $tcn_count = ($tcn_count_raw !== false) ? (int)trim(str_replace(['INTEGER: ', 'Counter32: '], '', (string)$tcn_count_raw)) : ($sw['stp_topology_changes'] ?? 0);
        $time_ticks = ($tcn_time_raw !== false) ? (int)trim(str_replace(['Timeticks: (', ')', 'INTEGER: '], '', (string)$tcn_time_raw)) : 0;
        $tcn_sec = (int)($time_ticks / 100);

        // Phase 5: FDB Thrashing
        $fStmt = $db->prepare("
            SELECT mac_addr, COUNT(DISTINCT port_name) as port_cnt, GROUP_CONCAT(DISTINCT port_name ORDER BY port_name ASC SEPARATOR ' ↔ ') as port_pair
            FROM switch_port_map
            WHERE switch_id = ? AND mac_addr IS NOT NULL AND mac_addr != '' AND mac_addr NOT LIKE 'PORT:%'
            GROUP BY mac_addr
            HAVING port_cnt > 1
            LIMIT 5
        ");
        $fStmt->execute([$switch_id]);
        $flapping_macs = $fStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Calculate confidence
        $confidence = 30; // base
        if ($has_snmp) $confidence += 40;
        if (!empty($blocked_ports) || !empty($forwarding_ports)) $confidence += 15;
        if ($tcn_count > 0) $confidence += 15;
        $confidence = min(98, $confidence);

        return [
            'has_snmp'         => $has_snmp,
            'sys_name'         => $sys_name,
            'sys_descr'        => $clean_descr,
            'stp_name'         => $stp_name,
            'root_cost'        => $root_cost,
            'blocked_ports'    => $blocked_ports,
            'forwarding_ports' => $forwarding_ports,
            'down_ports'       => $down_ports,
            'tcn_count'        => $tcn_count,
            'tcn_seconds'      => $tcn_sec,
            'flapping_macs'    => $flapping_macs,
            'confidence'       => $confidence
        ];
    }
}
