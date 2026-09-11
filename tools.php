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
                if (!empty($db_rows)) {
                    foreach ($db_rows as $row) {
                        $output .= "  • Subnet ID        : " . $row['subnet_id'] . "\n";
                        $output .= "  • Hostname         : " . ($row['hostname'] ?: '-') . "\n";
                        $output .= "  • Recorded MAC     : " . ($row['mac_addr'] ?: 'Unknown') . " (" . ($row['vendor'] ?: 'Unknown Vendor') . ")\n";
                        $output .= "  • Recorded OS      : " . ($row['os'] ?: '-') . "\n";
                        $output .= "  • Status           : " . strtoupper($row['state']) . " (Confidence: " . $row['confidence_score'] . "%)\n";
                        $output .= "  • Conflict Flag    : " . ($row['conflict_detected'] ? "YES (⚠️ ACTIVE CONFLICT)" : "None") . "\n";
                        if (!empty($row['conflict_mac'])) {
                            $output .= "  • Conflicting MAC  : " . $row['conflict_mac'] . "\n";
                        }
                        if (!empty($row['conflict_details'])) {
                            $output .= "  • Conflict Details : " . $row['conflict_details'] . "\n";
                        }
                        if ($row['conflict_detected']) $has_db_conflict = true;
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
                $unique_ports = [];
                if (!empty($ports)) {
                    foreach ($ports as $p) {
                        $output .= "  • Switch           : " . $p['switch_name'] . " (" . $p['switch_ip'] . ")\n";
                        $output .= "    Port             : " . $p['port_name'] . " (VLAN " . ($p['vlan_id'] ?: '1') . ", Status: " . ($p['port_status'] ?: 'up') . ")\n";
                        $output .= "    MAC Attached     : " . $p['mac_addr'] . "\n";
                        $unique_ports[$p['switch_id'] . '_' . $p['port_name']] = true;
                    }
                    if (count($unique_ports) > 1) {
                        $output .= "  ⚠️ Warning: MACs for this IP are mapped across " . count($unique_ports) . " different switch ports!\n";
                    }
                } else {
                    $output .= "  • No switch port entries directly associated with recorded MACs.\n";
                }
                $output .= "\n";

                // Phase 3: Live Probing & TTL Analysis
                $output .= "[Phase 3] Live Probing & TTL Consistency Analysis\n";
                
                // Run 6 pings
                $ping_cmd = $is_windows ? "ping -n 6 " . escapeshellarg($target) : "ping -c 6 " . escapeshellarg($target);
                $raw_ping = (string)shell_exec($ping_cmd);
                
                $ttls = [];
                if (preg_match_all('/TTL=(\d+)/i', $raw_ping, $matches)) {
                    $ttls = array_map('intval', $matches[1]);
                }

                // Read ARP after ping
                $arp_after_lines = [];
                if ($is_windows) {
                    @exec("arp -a " . escapeshellarg($target), $arp_after_lines);
                } else {
                    @exec("arp -n " . escapeshellarg($target), $arp_after_lines);
                }

                // Extract resolved MAC after ping
                $current_active_mac = null;
                foreach ($arp_after_lines as $aline) {
                    if (preg_match('/([0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2}[:-][0-9a-fA-F]{2})/', $aline, $m)) {
                        $current_active_mac = strtolower(str_replace('-', ':', $m[1]));
                        break;
                    }
                }

                $output .= "  • Packets Transmitted : 6\n";
                $output .= "  • Packets Received    : " . count($ttls) . "\n";
                $unique_ttls = array_values(array_unique($ttls));
                if (!empty($ttls)) {
                    $output .= "  • Observed TTL Values : " . implode(', ', $ttls) . " (Unique: " . implode(', ', $unique_ttls) . ")\n";
                    $output .= "  • Live ARP MAC        : " . ($current_active_mac ?: 'Not in ARP table') . "\n";
                } else {
                    $output .= "  • Host is completely unresponsive to ICMP ping probes.\n";
                }
                $output .= "\n";

                // Phase 4: Diagnosis & Verdict
                $output .= "==================== VERDICT ====================\n";
                $conflict_score = 0;
                $reasons = [];

                if (count($unique_ttls) > 1) {
                    $conflict_score += 40;
                    $reasons[] = "Fluctuating TTL detected (" . implode(' vs ', $unique_ttls) . "). Multiple different operating systems or devices are answering this IP!";
                }

                if ($has_db_conflict) {
                    $conflict_score += 35;
                    $reasons[] = "NetScope IPAM has flagged an active conflict (MAC flapping or conflicting OS fingerprints).";
                }

                if (count($unique_ports) > 1) {
                    $conflict_score += 35;
                    $reasons[] = "Associated MAC addresses are connected to multiple distinct switch ports.";
                }

                if ($conflict_score >= 35) {
                    $output .= "🚨 STATUS: CONFIRMED / HIGH RISK IP CONFLICT!\n";
                    $output .= "Risk Score: " . min(100, $conflict_score + 25) . "%\n\n";
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
                    $output .= "Host responses and network signatures appear steady and consistent.\n";
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
                <label>Target Host (IP / MAC / Domain)</label>
                <input type="text" name="target" value="<?php echo htmlspecialchars($target); ?>" class="input-control" placeholder="e.g. 192.168.1.1" required>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 0.8rem; margin-top: 1.5rem;">
                <?php 
                    $active_action = $action ?: 'ping'; 
                ?>
                <button type="submit" name="action" value="conflict" class="btn <?php echo $active_action === 'conflict' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'conflict' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="shield-alert" style="width: 16px;"></i> Cek Konflik IP (Prober)
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
