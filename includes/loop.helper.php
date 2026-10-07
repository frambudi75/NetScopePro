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
     * Get all managed switches with basic loop & STP summary
     */
    public static function getSwitches(PDO $db): array {
        $stmt = $db->query("
            SELECT s.id, s.name, s.ip_addr, s.model, s.loop_detected, s.loop_details, s.stp_topology_changes,
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

        // 2. Detect MAC Thrashing / Flapping across multiple ports on this same switch
        $fStmt = $db->prepare("
            SELECT mac_addr, COUNT(DISTINCT port_name) as port_cnt, GROUP_CONCAT(DISTINCT port_name ORDER BY port_name ASC SEPARATOR ' ↔ ') as port_pair
            FROM switch_port_map
            WHERE switch_id = ? AND mac_addr IS NOT NULL AND mac_addr != '' AND mac_addr NOT LIKE 'PORT:%'
            GROUP BY mac_addr
            HAVING port_cnt > 1
            LIMIT 10
        ");
        $fStmt->execute([$switch_id]);
        $flapping_macs = $fStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Check recorded loop flags
        $has_recorded_loop = !empty($switch['loop_detected']);
        $recorded_details = $switch['loop_details'] ?? '';

        // 3. Calculate Risk Score (0 - 100%)
        $risk_score = 0;
        $risk_factors = [];

        if (!empty($blocked_ports)) {
            $risk_score += 50;
            $risk_factors[] = "Active STP port quarantine (" . count($blocked_ports) . " ports in BLOCKING state)";
        }
        if (!empty($flapping_macs)) {
            $risk_score += 35;
            $risk_factors[] = "CAM Table Thrashing detected (" . count($flapping_macs) . " oscillating MAC addresses)";
        } elseif ($has_recorded_loop) {
            $risk_score += 25;
            $risk_factors[] = "Recent loop warning recorded in poller telemetry";
        }

        $tcn_count = (int)($switch['stp_topology_changes'] ?? 0);
        if ($tcn_count > 100) {
            $risk_score += 15;
            $risk_factors[] = "High accumulated STP Topology Changes ($tcn_count TCN events)";
        }

        $risk_score = min(100, max(0, $risk_score));

        // 4. Construct Investigation Path
        $investigation_path = self::buildInvestigationPath($db, $switch, $flapping_macs, $blocked_ports);

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
    private static function buildInvestigationPath(PDO $db, array $switch, array $flapping_macs, array $blocked_ports): array {
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

        // Level 3: Downstream Target / Connected Neighbor Switch
        $target_ports = [];
        if (!empty($flapping_macs)) {
            foreach ($flapping_macs as $fm) {
                $pairs = explode(' ↔ ', $fm['port_pair']);
                foreach ($pairs as $p) {
                    $target_ports[] = trim($p);
                }
            }
        }
        if (!empty($blocked_ports)) {
            foreach ($blocked_ports as $bp) {
                $target_ports[] = trim($bp);
            }
        }
        $target_ports = array_values(array_unique($target_ports));

        $downstream_switch = null;
        $downstream_port_label = !empty($target_ports) ? implode(' / ', array_slice($target_ports, 0, 2)) : 'Access Port';

        // Check if any child switch is linked from this switch
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
            if ($downstream_switch) {
                $path[] = [
                    'level'       => 3,
                    'role'        => 'DOWNSTREAM_SWITCH',
                    'name'        => $downstream_switch['name'],
                    'ip'          => $downstream_switch['ip_addr'],
                    'model'       => $downstream_switch['model'] ?: 'Downstream Switch',
                    'status'      => 'warning',
                    'status_text' => "Connected on {$downstream_port_label} (Investigate downstream cabling)",
                    'badge'       => 'INVESTIGATE TARGET',
                    'badge_color' => 'var(--warning)'
                ];
            } else {
                $path[] = [
                    'level'       => 3,
                    'role'        => 'DOWNSTREAM_SEGMENT',
                    'name'        => "Downstream Segment (Port {$downstream_port_label})",
                    'ip'          => 'Access / Patch Link',
                    'model'       => 'Unmanaged Switch or Edge Patch Panel',
                    'status'      => 'warning',
                    'status_text' => "Direct link on Port {$downstream_port_label} exhibiting frame oscillation",
                    'badge'       => 'DOWNSTREAM LINK',
                    'badge_color' => 'var(--warning)'
                ];
            }

            // Level 4: Endpoint / Culprit Device (Resolve MAC to IP & Vendor)
            if (!empty($flapping_macs)) {
                $bouncing_mac = $flapping_macs[0]['mac_addr'];
                $vendor = function_exists('get_vendor_by_mac') ? get_vendor_by_mac($bouncing_mac) : 'Unknown Vendor';

                // Look up in ip_addresses
                $ipStmt = $db->prepare("SELECT ip_addr, hostname, vendor, description FROM ip_addresses WHERE mac_addr = ? LIMIT 1");
                $ipStmt->execute([$bouncing_mac]);
                $ipRow = $ipStmt->fetch(PDO::FETCH_ASSOC);

                $dev_name = $ipRow['hostname'] ?? ($vendor !== 'Unknown' ? "$vendor Device" : "Unknown Host");
                $dev_ip = $ipRow['ip_addr'] ?? 'Static / Non-ARP L2';
                $dev_desc = $ipRow['description'] ?? "Bouncing MAC: " . strtoupper($bouncing_mac);

                $culprit_info = [
                    'level'       => 4,
                    'role'        => 'CULPRIT_DEVICE',
                    'name'        => $dev_name,
                    'ip'          => $dev_ip,
                    'model'       => "$vendor (" . strtoupper($bouncing_mac) . ")",
                    'status'      => 'culprit',
                    'status_text' => "MAC active across multiple ports simultaneously. $dev_desc",
                    'badge'       => 'POSSIBLE ROOT CAUSE',
                    'badge_color' => '#f97316'
                ];
            } else {
                $culprit_info = [
                    'level'       => 4,
                    'role'        => 'CULPRIT_DEVICE',
                    'name'        => 'Dual-Connect / Loopback Cable',
                    'ip'          => 'Physical Layer Defect',
                    'model'       => 'Unmanaged Loop / Cross-Connected Patch Cord',
                    'status'      => 'culprit',
                    'status_text' => 'Port quarantined by BPDU Guard or STP to break packet amplification',
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

        if (!empty($blocked_ports)) {
            return [
                'type'       => 'danger',
                'title'      => 'Active L2 Switching Loop (Quarantined by STP)',
                'summary'    => "Spanning Tree Protocol has actively isolated switching loops by placing port(s) " . implode(', ', $blocked_ports) . " into BLOCKING state on {$sw_name}.",
                'evidence'   => [
                    "Physical port(s) " . implode(', ', $blocked_ports) . " transitioned to BLOCKING state to eliminate bridge loop.",
                    "Loop protection prevented total network collapse, but redundant/looped cable path remains physically connected.",
                    "Frame forwarding on quarantined port(s) is currently suspended."
                ],
                'scope_note' => "The physical cable loop or unmanaged bridge exists directly on or downstream of the quarantined port(s).",
                'actions'    => [
                    "Inspect patch cabling connected to port(s) " . implode(', ', $blocked_ports) . " on {$sw_name}.",
                    "Check for unmanaged 5-port/8-port switches or IP phones with both LAN and PC ports plugged into wall jacks.",
                    "If dual-link redundancy is intended, configure LACP (802.3ad) aggregation instead of raw unbundled links."
                ]
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
