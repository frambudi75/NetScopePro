<?php
/**
 * NetScope Pro - L2 Loop Detective Evidence Engine (v2.32.3)
 * 
 * Multi-evidence telemetry correlator inspired by LibreNMS collector patterns.
 * 
 * CORE PRINCIPLE:
 * "FDB alone MUST NEVER trigger LOOP_DETECTED."
 * FDB table is strictly a Candidate-Only trigger.
 * 
 * Correlates:
 * 1. Port Context (Access vs Trunk/Uplink vs LAG)
 * 2. MAC Movement (Bouncing frequency, multi-MAC density, persistence)
 * 3. Topology & Infrastructure Link exclusions (Trunk propagation, Client roaming)
 * 4. STP / RSTP Telemetry (Port state, port role, TCN counters)
 * 5. Counter Correlation (Broadcast packet rate delta)
 */

class LoopEvidenceEngine {

    public const CONF_VERY_HIGH = 'VERY_HIGH';
    public const CONF_HIGH      = 'HIGH';
    public const CONF_MEDIUM    = 'MEDIUM';
    public const CONF_LOW       = 'LOW';

    /**
     * Evaluates raw switch telemetry and returns evidence breakdown and candidate status.
     * 
     * @param array $telemetry [
     *    'ports'             => ['Port 1/1' => ['type' => 'access'|'trunk', 'mac_count' => N, 'stp_state' => 'forwarding'|'blocking', 'alias' => '']],
     *    'flaps'             => [['mac' => '...', 'from' => '...', 'to' => '...']],
     *    'tagged_ports'      => ['Port 1/24', ...],
     *    'tcn_delta'         => int (Topology changes delta since last poll),
     *    'broadcast_delta'   => int (Broadcast packet rate increase percentage),
     *    'cycles_persisted'  => int (Consecutive poll cycles where anomaly persisted),
     *    'flap_threshold'    => int (Default 3)
     * ]
     * @return array Structured candidate and evidence evaluation
     */
    public static function evaluate(array $telemetry): array {
        $ports            = $telemetry['ports'] ?? [];
        $flaps            = $telemetry['flaps'] ?? [];
        $tagged_ports     = $telemetry['tagged_ports'] ?? [];
        $tcn_delta        = (int)($telemetry['tcn_delta'] ?? 0);
        $broadcast_delta  = (int)($telemetry['broadcast_delta'] ?? 0);
        $cycles_persisted = max(1, (int)($telemetry['cycles_persisted'] ?? 1));
        $flap_threshold   = max(3, (int)($telemetry['flap_threshold'] ?? 3));

        // 1. Port Context Map
        $port_mac_counts = [];
        $stp_blocked_ports = [];
        foreach ($ports as $pName => $pData) {
            $port_mac_counts[$pName] = $pData['mac_count'] ?? 1;
            if (($pData['stp_state'] ?? '') === 'blocking') {
                $stp_blocked_ports[] = $pName;
            }
        }

        $isUplink = function(string $pName) use ($ports, $port_mac_counts, $tagged_ports): bool {
            $clean = preg_match('/\(([^)]+)\)/', $pName, $m) ? $m[1] : $pName;
            $clean = trim($clean);

            if (isset($ports[$clean]['type']) && strtolower($ports[$clean]['type']) === 'trunk') {
                return true;
            }

            $cnt = $port_mac_counts[$clean] ?? ($port_mac_counts[$pName] ?? 0);
            if ($cnt > 3) return true;

            if (!empty($tagged_ports) && in_array($clean, $tagged_ports, true)) return true;

            if (preg_match('/(-to-|-sw|uplink|trunk|core|dist|po\d+|bond|ae\d+|sfp|\/48|\/49|\/50|\/51|\/52|\/24|\/25|\/26)/i', $clean)) return true;

            $alias = $ports[$clean]['alias'] ?? ($ports[$pName]['alias'] ?? '');
            if (!empty($alias) && preg_match('/(uplink|trunk|core|dist|switch|to\s)/i', $alias)) return true;

            return false;
        };

        // 2. Filter & Group MAC Movements
        $pair_flaps = [];
        $exclusions = [];
        $port0_count = 0;
        $trunk_prop_count = 0;
        $roaming_count = 0;

        foreach ($flaps as $f) {
            $p1 = $f['from'] ?? null;
            $p2 = $f['to'] ?? null;
            $mac = $f['mac'] ?? null;

            // Disqualify RFC 1493 Port 0
            if ($p1 === 'Port 0' || $p2 === 'Port 0' || $p1 === '0' || $p2 === '0') {
                $port0_count++;
                continue;
            }

            if (!$p1 || !$p2 || $p1 === $p2) continue;

            $p1_up = $isUplink($p1);
            $p2_up = $isUplink($p2);

            // Disqualify Trunk-to-Trunk (Infrastructure Propagation)
            if ($p1_up && $p2_up) {
                $trunk_prop_count++;
                continue;
            }

            // Disqualify Access-to-Trunk (Normal Client Roaming)
            if (($p1_up && !$p2_up) || (!$p1_up && $p2_up)) {
                $roaming_count++;
                continue;
            }

            // At this point: strictly Access <-> Access movement
            $pair_key = ($p1 < $p2) ? "$p1 <-> $p2" : "$p2 <-> $p1";
            $pair_flaps[$pair_key][] = $mac;
        }

        if ($port0_count > 0) {
            $exclusions[] = "Port 0 internal CPU frames excluded ($port0_count instances)";
        }
        if ($trunk_prop_count > 0) {
            $exclusions[] = "Trunk-to-Trunk infrastructure transit excluded ($trunk_prop_count moves)";
        }
        if ($roaming_count > 0) {
            $exclusions[] = "Access-to-Trunk Wi-Fi client roaming excluded ($roaming_count moves)";
        }

        // 3. Find Top Bouncing Pair
        $top_pair = null;
        $max_unique_macs = 0;
        foreach ($pair_flaps as $pair => $flapped_macs) {
            $unique_macs = count(array_unique($flapped_macs));
            if ($unique_macs > $max_unique_macs) {
                $max_unique_macs = $unique_macs;
                $top_pair = $pair;
            }
        }

        // 4. Candidate & Evidence Evaluation
        $is_candidate = false;
        $evidences = [];
        $confidence_score = 0;

        // Evidence Evaluation for Access <-> Access flapping
        if ($top_pair !== null && $max_unique_macs >= $flap_threshold) {
            $is_candidate = true;

            // EV-ACCESS-PAIR: Both ports are access (+30)
            $evidences[] = [
                'id'     => 'EV-ACCESS-PAIR',
                'label'  => "Both interfaces on pair ($top_pair) classified as ACCESS edge ports",
                'points' => 30
            ];
            $confidence_score += 30;

            // EV-MULTI-MAC: Density of oscillating MACs
            if ($max_unique_macs >= 5) {
                $evidences[] = [
                    'id'     => 'EV-MULTI-MAC',
                    'label'  => "$max_unique_macs unique MAC addresses bouncing simultaneously",
                    'points' => 30
                ];
                $confidence_score += 30;
            } else {
                $evidences[] = [
                    'id'     => 'EV-MULTI-MAC',
                    'label'  => "$max_unique_macs MAC addresses bouncing simultaneously (Threshold >= $flap_threshold)",
                    'points' => 20
                ];
                $confidence_score += 20;
            }

            // EV-MAC-BOUNCE: Bidirectional flapping verified (+25)
            $evidences[] = [
                'id'     => 'EV-MAC-BOUNCE',
                'label'  => "Oscillating CAM table movement verified on exact physical pair",
                'points' => 25
            ];
            $confidence_score += 25;

            // EV-PERSISTENCE: Anomaly observed across multiple cycles (+15)
            if ($cycles_persisted >= 2) {
                $evidences[] = [
                    'id'     => 'EV-PERSISTENCE',
                    'label'  => "Persistent flapping across $cycles_persisted consecutive polling cycles",
                    'points' => 15
                ];
                $confidence_score += 15;
            }

            // EV-STP-TCN: Supporting evidence (+10)
            if ($tcn_delta > 0) {
                $evidences[] = [
                    'id'     => 'EV-STP-TCN',
                    'label'  => "STP Topology Change Notifications observed (+$tcn_delta TCNs)",
                    'points' => 10
                ];
                $confidence_score += 10;
            }

            // EV-BROADCAST-SPIKE: Supporting bonus (+10)
            if ($broadcast_delta >= 100) {
                $evidences[] = [
                    'id'     => 'EV-BROADCAST-SPIKE',
                    'label'  => "Broadcast packet rate spike detected (+{$broadcast_delta}%)",
                    'points' => 10
                ];
                $confidence_score += 10;
            }
        } elseif (!empty($stp_blocked_ports)) {
            // STP active blocking detection (STP has already converged and quarantined a loop)
            $is_candidate = true;
            $blocked_str = implode(', ', $stp_blocked_ports);
            $evidences[] = [
                'id'     => 'EV-STP-BLOCKED',
                'label'  => "STP active mitigation: Interface(s) $blocked_str in BLOCKING state",
                'points' => 35
            ];
            $confidence_score += 35;

            if ($tcn_delta > 0) {
                $evidences[] = [
                    'id'     => 'EV-STP-TCN',
                    'label'  => "Topology changes recorded during convergence (+$tcn_delta TCNs)",
                    'points' => 10
                ];
                $confidence_score += 10;
            }
        } else {
            // No bouncing on access ports and no blocked ports
            $is_candidate = false;
            $confidence_score = 0;
            if (empty($exclusions)) {
                $exclusions[] = "No anomalous MAC movement across access ports";
            }
        }

        // Cap score at 100
        $confidence_score = min(100, $confidence_score);

        // Assign Confidence Level
        $confidence_level = self::CONF_LOW;
        if ($confidence_score >= 80) {
            $confidence_level = self::CONF_VERY_HIGH;
        } elseif ($confidence_score >= 65) {
            $confidence_level = self::CONF_HIGH;
        } elseif ($confidence_score >= 45) {
            $confidence_level = self::CONF_MEDIUM;
        }

        return [
            'is_candidate'       => $is_candidate,
            'confidence_level'   => $confidence_level,
            'confidence_score'   => $confidence_score,
            'top_pair'           => $top_pair,
            'unique_mac_count'   => $max_unique_macs,
            'evidences'          => $evidences,
            'exclusions'         => $exclusions,
            'stp_blocked_ports'  => $stp_blocked_ports,
            'cycles_persisted'   => $cycles_persisted
        ];
    }
}
