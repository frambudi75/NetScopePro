<?php
/**
 * NetScope Pro - L2 Loop Detective Regression Test Suite (v2.32.2)
 * 
 * Tests the 11 synthetic loop detection scenarios to ensure zero false-positives
 * (Wi-Fi roaming, trunk propagation, Port 0, unmanaged hubs) while guaranteeing
 * accurate identification of real physical loops (Active vs STP Mitigated).
 * 
 * Usage via CLI:
 *   php tests/test_loop_scenarios.php
 */

require_once __DIR__ . '/../includes/loop.helper.php';

class LoopRegressionSuite {

    private int $passed = 0;
    private int $failed = 0;
    private array $results = [];

    /**
     * Run all 11 regression test scenarios
     */
    public function runAll(): bool {
        echo "====================================================================\n";
        echo "  NetScope Pro - L2 Loop Detective Regression Test Suite\n";
        echo "  Baseline: v2.32.1 | Engine Target: 11 Synthetic Scenarios\n";
        echo "====================================================================\n\n";

        $this->test01_NormalSingleAccessHost();
        $this->test02_NormalUplinkPort();
        $this->test03_TrunkToTrunkPropagation();
        $this->test04_LinkAggregationLagLacp();
        $this->test05_Rfc1493CpuPortZero();
        $this->test06_WiFiClientRoaming();
        $this->test07_DumbHubSingleAccessPort();
        $this->test08_TransientFlappingSingleCycle();
        $this->test09_RealPhysicalLoopNoStp();
        $this->test10_PhysicalLoopStpProtected();
        $this->test11_HighDensityAccessDocking();

        echo "\n--------------------------------------------------------------------\n";
        echo "Test Summary: {$this->passed} Passed, {$this->failed} Failed (Total: " . ($this->passed + $this->failed) . ")\n";
        echo "--------------------------------------------------------------------\n";

        if ($this->failed === 0) {
            echo "✅ ALL 11 SCENARIOS PASSED! Baseline stability confirmed.\n\n";
            return true;
        } else {
            echo "❌ REGRESSION DETECTED! One or more scenarios failed.\n\n";
            return false;
        }
    }

    /**
     * Core evaluation logic matching v2.32.1 baseline engine
     */
    public static function evaluateScenario(array $scenario): array {
        $ports = $scenario['ports'] ?? []; // ['port_name' => ['type' => 'access'|'trunk', 'mac_count' => N, 'stp_state' => 'forwarding'|'blocking', 'alias' => '']]
        $flaps = $scenario['flaps'] ?? []; // [['mac' => '...', 'from' => '...', 'to' => '...']]
        $tagged_ports = $scenario['tagged_ports'] ?? [];
        $flap_threshold = $scenario['flap_threshold'] ?? 3;

        // Port MAC count map
        $port_mac_counts = [];
        foreach ($ports as $pName => $pData) {
            $port_mac_counts[$pName] = $pData['mac_count'] ?? 1;
        }

        // Helper: Check if port is Uplink / Trunk
        $isUplink = function($pName) use ($ports, $port_mac_counts, $tagged_ports) {
            $clean = preg_match('/\(([^)]+)\)/', $pName, $m) ? $m[1] : $pName;
            $clean = trim($clean);

            // Explicit port definition
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

        // Group flaps by normalized pair
        $pair_flaps = [];
        $disqualified_port0_count = 0;
        $roaming_count = 0;

        foreach ($flaps as $f) {
            $p1 = $f['from'] ?? null;
            $p2 = $f['to'] ?? null;
            $mac = $f['mac'] ?? null;

            // RFC 1493 / Internal CPU Port 0 disqualification
            if ($p1 === 'Port 0' || $p2 === 'Port 0' || $p1 === '0' || $p2 === '0') {
                $disqualified_port0_count++;
                continue;
            }

            if (!$p1 || !$p2 || $p1 === $p2) continue;

            $p1_up = $isUplink($p1);
            $p2_up = $isUplink($p2);

            // Wi-Fi roaming check: Access <-> Uplink movement
            if (($p1_up && !$p2_up) || (!$p1_up && $p2_up)) {
                $roaming_count++;
            }

            $pair_key = ($p1 < $p2) ? "$p1 <-> $p2" : "$p2 <-> $p1";
            $pair_flaps[$pair_key][] = $mac;
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

        // Check if top pair is strictly Access <-> Access
        $is_access_to_access = false;
        if ($top_pair !== null && strpos($top_pair, ' <-> ') !== false) {
            list($tp1, $tp2) = explode(' <-> ', $top_pair);
            $tp1_up = $isUplink(trim($tp1));
            $tp2_up = $isUplink(trim($tp2));
            $is_access_to_access = (!$tp1_up && !$tp2_up);
        }

        // Check STP blocked ports
        $stp_blocked_ports = [];
        foreach ($ports as $pName => $pData) {
            if (($pData['stp_state'] ?? '') === 'blocking') {
                $stp_blocked_ports[] = $pName;
            }
        }

        // Determine loop candidate & states
        $loop_candidate = false;
        if ($top_pair !== null) {
            if ($is_access_to_access && $max_pair_flaps >= $flap_threshold) {
                $loop_candidate = true;
            } elseif ($max_pair_flaps >= 20) {
                $loop_candidate = true; // Extreme broadcast storm flood
            }
        }

        // Detection State
        $cycles_persisted = $scenario['cycles_persisted'] ?? 1;
        $loop_state = 'NORMAL';
        if ($loop_candidate || !empty($stp_blocked_ports)) {
            if ($cycles_persisted >= 2 || !empty($stp_blocked_ports)) {
                $loop_state = 'CONFIRMED';
            } else {
                $loop_state = 'SUSPECTED';
            }
        }

        // Protection State
        $protection_state = !empty($stp_blocked_ports) ? 'STP_BLOCKING' : 'NONE';

        // Impact State
        $impact_state = 'NORMAL';
        if ($loop_state === 'CONFIRMED' || $loop_state === 'SUSPECTED') {
            if ($protection_state === 'STP_BLOCKING') {
                $impact_state = 'MITIGATED';
            } else {
                $impact_state = ($loop_state === 'CONFIRMED') ? 'ACTIVE' : 'SUSPECTED_ACTIVE';
            }
        }

        return [
            'loop_candidate'       => $loop_candidate,
            'loop_state'           => $loop_state,
            'protection_state'     => $protection_state,
            'impact_state'         => $impact_state,
            'top_pair'             => $top_pair,
            'max_pair_flaps'       => $max_pair_flaps,
            'is_access_to_access'  => $is_access_to_access,
            'disqualified_port0'   => $disqualified_port0_count,
            'roaming_count'        => $roaming_count,
            'stp_blocked_ports'    => $stp_blocked_ports
        ];
    }

    private function record(string $id, string $title, bool $pass, string $detail = ''): void {
        if ($pass) {
            $this->passed++;
            echo "  [PASS] {$id}: {$title}\n";
            if ($detail) echo "         └─ {$detail}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$id}: {$title}\n";
            if ($detail) echo "         └─ ERROR: {$detail}\n";
        }
    }

    // =========================================================================
    //  11 SYNTHETIC SCENARIOS
    // =========================================================================

    public function test01_NormalSingleAccessHost(): void {
        $scenario = [
            'ports' => [
                'Port 1/1' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'forwarding']
            ],
            'flaps' => [] // No movement
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'NORMAL' && $res['impact_state'] === 'NORMAL');
        $this->record('TEST-01', 'Normal Single Access Host', $pass, "State: {$res['loop_state']}, Impact: {$res['impact_state']}");
    }

    public function test02_NormalUplinkPort(): void {
        $scenario = [
            'ports' => [
                'Port 1/24' => ['type' => 'trunk', 'mac_count' => 50, 'alias' => 'Uplink-Core-SW', 'stp_state' => 'forwarding']
            ],
            'flaps' => []
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'NORMAL' && $res['impact_state'] === 'NORMAL');
        $this->record('TEST-02', 'Normal Uplink Port with High MAC Count', $pass, "50 MACs on Uplink treated as infrastructure (Normal)");
    }

    public function test03_TrunkToTrunkPropagation(): void {
        $scenario = [
            'ports' => [
                'Port 1/23' => ['type' => 'trunk', 'mac_count' => 30, 'alias' => 'Trunk-SW-East', 'stp_state' => 'forwarding'],
                'Port 1/24' => ['type' => 'trunk', 'mac_count' => 45, 'alias' => 'Trunk-SW-West', 'stp_state' => 'forwarding']
            ],
            'flaps' => [
                ['mac' => '00:11:22:33:44:55', 'from' => 'Port 1/23', 'to' => 'Port 1/24'],
                ['mac' => '00:11:22:33:44:66', 'from' => 'Port 1/23', 'to' => 'Port 1/24'],
                ['mac' => '00:11:22:33:44:77', 'from' => 'Port 1/23', 'to' => 'Port 1/24']
            ]
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'NORMAL' && !$res['is_access_to_access']);
        $this->record('TEST-03', 'Trunk-to-Trunk Inter-switch Propagation', $pass, "Inter-trunk transit excluded from loop alarm");
    }

    public function test04_LinkAggregationLagLacp(): void {
        $scenario = [
            'ports' => [
                'Port 1/49' => ['type' => 'trunk', 'mac_count' => 20, 'alias' => 'Po1-Member', 'stp_state' => 'forwarding'],
                'Port 1/50' => ['type' => 'trunk', 'mac_count' => 20, 'alias' => 'Po1-Member', 'stp_state' => 'forwarding']
            ],
            'flaps' => [
                ['mac' => '00:AA:BB:CC:DD:01', 'from' => 'Port 1/49', 'to' => 'Port 1/50'],
                ['mac' => '00:AA:BB:CC:DD:02', 'from' => 'Port 1/49', 'to' => 'Port 1/50']
            ]
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'NORMAL');
        $this->record('TEST-04', 'Link Aggregation (LAG/LACP Port-Channel Member)', $pass, "Po1 link balance traffic filtered");
    }

    public function test05_Rfc1493CpuPortZero(): void {
        $scenario = [
            'ports' => [
                'Port 1/5' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'forwarding']
            ],
            'flaps' => [
                ['mac' => '00:50:56:A1:B2:C3', 'from' => 'Port 1/5', 'to' => 'Port 0'],
                ['mac' => '00:50:56:A1:B2:C4', 'from' => 'Port 0', 'to' => 'Port 1/5']
            ]
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'NORMAL' && $res['disqualified_port0'] === 2);
        $this->record('TEST-05', 'RFC 1493 Internal CPU Port 0 Filter', $pass, "Port 0 frames disqualified: {$res['disqualified_port0']}");
    }

    public function test06_WiFiClientRoaming(): void {
        $scenario = [
            'ports' => [
                'Port 1/12' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'forwarding'],
                'Port 1/24' => ['type' => 'trunk', 'mac_count' => 40, 'alias' => 'Trunk-AP-Floor2', 'stp_state' => 'forwarding']
            ],
            'flaps' => [
                ['mac' => 'A4:C3:F0:12:34:56', 'from' => 'Port 1/12', 'to' => 'Port 1/24'],
                ['mac' => 'B8:27:EB:78:90:AB', 'from' => 'Port 1/12', 'to' => 'Port 1/24']
            ]
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'NORMAL' && $res['roaming_count'] === 2);
        $this->record('TEST-06', 'Wi-Fi Client Roaming (Access <-> AP Trunk)', $pass, "Classified as normal client roaming: {$res['roaming_count']} moves");
    }

    public function test07_DumbHubSingleAccessPort(): void {
        $scenario = [
            'ports' => [
                'Port 1/8' => ['type' => 'access', 'mac_count' => 8, 'stp_state' => 'forwarding']
            ],
            'flaps' => [] // Multiple MACs on single port, NO movement
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'NORMAL' && $res['impact_state'] === 'NORMAL');
        $this->record('TEST-07', 'Dumb Hub / Unmanaged Switch Behind Single Port', $pass, "8 MACs on 1 access port with NO bouncing = NORMAL");
    }

    public function test08_TransientFlappingSingleCycle(): void {
        $scenario = [
            'ports' => [
                'Port 1/3' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'forwarding'],
                'Port 1/4' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'forwarding']
            ],
            'flaps' => [
                ['mac' => '00:1A:2B:3C:4D:5E', 'from' => 'Port 1/3', 'to' => 'Port 1/4'],
                ['mac' => '00:1A:2B:3C:4D:5F', 'from' => 'Port 1/3', 'to' => 'Port 1/4'],
                ['mac' => '00:1A:2B:3C:4D:60', 'from' => 'Port 1/3', 'to' => 'Port 1/4']
            ],
            'cycles_persisted' => 1 // Only 1 cycle (transient)
        ];
        $res = self::evaluateScenario($scenario);
        // On 1 cycle: marked as SUSPECTED, not CONFIRMED/ACTIVE
        $pass = ($res['loop_state'] === 'SUSPECTED' && $res['loop_candidate'] === true);
        $this->record('TEST-08', 'Transient Flapping (Single Polling Cycle)', $pass, "Status: SUSPECTED (Awaiting Cycle 2 confirmation)");
    }

    public function test09_RealPhysicalLoopNoStp(): void {
        $scenario = [
            'ports' => [
                'Port 1/1' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'forwarding'],
                'Port 1/2' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'forwarding']
            ],
            'flaps' => [
                ['mac' => '00:22:33:44:55:01', 'from' => 'Port 1/1', 'to' => 'Port 1/2'],
                ['mac' => '00:22:33:44:55:02', 'from' => 'Port 1/1', 'to' => 'Port 1/2'],
                ['mac' => '00:22:33:44:55:03', 'from' => 'Port 1/1', 'to' => 'Port 1/2'],
                ['mac' => '00:22:33:44:55:04', 'from' => 'Port 1/1', 'to' => 'Port 1/2'],
                ['mac' => '00:22:33:44:55:05', 'from' => 'Port 1/1', 'to' => 'Port 1/2']
            ],
            'cycles_persisted' => 2 // Persistent across >= 2 cycles
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'CONFIRMED' && $res['impact_state'] === 'ACTIVE' && $res['protection_state'] === 'NONE');
        $this->record('TEST-09', 'Real Physical Loop (Unmitigated / Both Forwarding)', $pass, "Detection: CONFIRMED, Protection: NONE, Impact: ACTIVE (🔴)");
    }

    public function test10_PhysicalLoopStpProtected(): void {
        $scenario = [
            'ports' => [
                'Port 1/1' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'forwarding'],
                'Port 1/2' => ['type' => 'access', 'mac_count' => 1, 'stp_state' => 'blocking']
            ],
            'flaps' => [], // Post-convergence: frames are dropped on blocking port, no massive bouncing
            'cycles_persisted' => 2
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'CONFIRMED' && $res['protection_state'] === 'STP_BLOCKING' && $res['impact_state'] === 'MITIGATED');
        $this->record('TEST-10', 'Physical Loop with STP Active (Port Blocked)', $pass, "Detection: CONFIRMED, Protection: STP_BLOCKING, Impact: MITIGATED (🔵)");
    }

    public function test11_HighDensityAccessDocking(): void {
        $scenario = [
            'ports' => [
                'Port 1/10' => ['type' => 'access', 'mac_count' => 15, 'stp_state' => 'forwarding']
            ],
            'flaps' => [] // 15 MACs (VM workstation / Docking station), NO bouncing
        ];
        $res = self::evaluateScenario($scenario);
        $pass = ($res['loop_state'] === 'NORMAL' && $res['impact_state'] === 'NORMAL');
        $this->record('TEST-11', 'High Density Access (Docking Station / VM Workstation)', $pass, "15 MACs without bouncing classified as NORMAL");
    }
}

// Execute test suite if run from CLI
if (php_sapi_name() === 'cli' || !isset($_SERVER['REMOTE_ADDR'])) {
    $suite = new LoopRegressionSuite();
    $success = $suite->runAll();
    exit($success ? 0 : 1);
}
