<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/network.php';
require_once 'includes/loop.helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$page_title = 'Loop Detective';
include 'includes/header.php';

$db = get_db_connection();

// 1. Fetch all managed switches
$switches = LoopDetectiveHelper::getSwitches($db);

// Determine initial switch to analyze (from GET query or highest priority)
$selected_switch_id = (int)($_GET['switch_id'] ?? 0);
if (!$selected_switch_id && !empty($switches)) {
    // Pick the switch with most issues first, or first in list
    $selected_switch_id = (int)$switches[0]['id'];
}

// 2. Analyze selected switch
$analysis = $selected_switch_id ? LoopDetectiveHelper::analyze($db, $selected_switch_id) : null;

// 3. Global KPI counts
$total_switches = count($switches);
$total_blocked_ports = 0;
$total_loop_suspects = 0;
$total_flapping_pairs = 0;

foreach ($switches as $s) {
    if (!empty($s['blocked_ports']) && $s['blocked_ports'] > 0) {
        $total_blocked_ports += (int)$s['blocked_ports'];
        $total_loop_suspects++;
    } elseif (!empty($s['loop_detected'])) {
        $total_loop_suspects++;
    }
}
?>

<style>
.detective-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    margin-bottom: 1.5rem;
}
.detective-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.detective-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    position: relative;
}
.target-selector-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.forensic-workspace {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 1.5rem;
    align-items: stretch;
    margin-bottom: 1.5rem;
}
@media (max-width: 992px) {
    .forensic-workspace {
        grid-template-columns: 1fr;
    }
}
.radar-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.65rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    font-size: 0.85rem;
}
.radar-item:last-child {
    border-bottom: none;
}
.radar-label {
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.radar-value {
    font-weight: 600;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.8rem;
}
.risk-meter-container {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}
.risk-meter-bar {
    height: 8px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 4px;
    overflow: hidden;
    margin-top: 0.5rem;
}
.risk-meter-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.5s ease;
}

/* Investigation Path Visual Tree */
.path-container {
    display: flex;
    flex-direction: column;
    gap: 0;
    position: relative;
    padding: 0.5rem 0;
}
.path-node {
    display: grid;
    grid-template-columns: 48px 1fr;
    position: relative;
    padding-bottom: 1.5rem;
}
.path-node:last-child {
    padding-bottom: 0;
}
.path-connector {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
}
.path-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--surface);
    border: 2px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
    transition: all 0.2s ease;
}
.path-line {
    position: absolute;
    top: 32px;
    bottom: -4px;
    width: 2px;
    background: rgba(255, 255, 255, 0.12);
    z-index: 1;
}
.path-node:last-child .path-line {
    display: none;
}
.path-content {
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 0.9rem 1.15rem;
    margin-left: 0.5rem;
}
.path-node-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.35rem;
}
.path-node-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.path-node-meta {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    color: var(--text-muted);
}
.path-node-desc {
    font-size: 0.8rem;
    color: var(--text-muted);
    line-height: 1.4;
}

/* Status variants */
.path-node.status-normal .path-circle { border-color: var(--text-muted); color: var(--text-muted); }
.path-node.status-warning .path-circle { border-color: var(--warning); color: var(--warning); background: rgba(245, 158, 11, 0.1); }
.path-node.status-warning .path-content { border-color: rgba(245, 158, 11, 0.3); background: rgba(245, 158, 11, 0.03); }
.path-node.status-critical .path-circle { border-color: var(--danger); color: var(--danger); background: rgba(239, 68, 68, 0.15); }
.path-node.status-critical .path-content { border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.04); }
.path-node.status-culprit .path-circle { border-color: #f97316; color: #f97316; background: rgba(249, 115, 22, 0.15); }
.path-node.status-culprit .path-content { border-color: rgba(249, 115, 22, 0.35); background: rgba(249, 115, 22, 0.04); }

.verdict-box {
    border-radius: var(--radius-md);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--border);
}
.verdict-box.type-danger {
    background: rgba(239, 68, 68, 0.05);
    border-color: rgba(239, 68, 68, 0.3);
}
.verdict-box.type-warning {
    background: rgba(245, 158, 11, 0.05);
    border-color: rgba(245, 158, 11, 0.3);
}
.verdict-box.type-success {
    background: rgba(16, 185, 129, 0.05);
    border-color: rgba(16, 185, 129, 0.3);
}

.terminal-box {
    background: #090d16;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: var(--radius-sm);
    padding: 1rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.8rem;
    color: #cbd5e1;
    white-space: pre-wrap;
    max-height: 400px;
    overflow-y: auto;
}

@media (max-width: 640px) {
    .forensic-workspace {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    .path-node {
        grid-template-columns: 36px 1fr;
        padding-bottom: 1rem;
    }
    .path-circle {
        width: 28px;
        height: 28px;
    }
    .path-circle svg {
        width: 14px;
        height: 14px;
    }
    .path-line {
        top: 28px;
    }
    .path-content {
        margin-left: 0.25rem;
        padding: 0.75rem 0.85rem;
    }
    .path-node-title {
        font-size: 0.85rem;
    }
    .path-node-meta {
        font-size: 0.7rem;
    }
    .path-node-desc {
        font-size: 0.75rem;
    }
    .verdict-box {
        padding: 1rem;
    }
    .detective-card {
        padding: 1rem;
    }
}
</style>

<div class="detective-header">
    <div>
        <h1 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="search-check" style="color: var(--primary); width: 24px; height: 24px;"></i>
            L2 Loop Detective
        </h1>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;">
            Forensic Switching Loop, MAC Thrashing & Downstream Root-Cause Investigator
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <a href="conflicts" class="btn btn-secondary" style="font-size: 0.8rem;">
            <i data-lucide="shield-alert" style="width: 14px;"></i> Conflict Center
        </a>
        <a href="topology" class="btn btn-secondary" style="font-size: 0.8rem;">
            <i data-lucide="map" style="width: 14px;"></i> Network Map
        </a>
    </div>
</div>

<!-- Global KPI Strip -->
<div class="detective-kpi-grid">
    <div class="detective-card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;">Monitored Switches</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: var(--text); margin-top: 0.25rem; font-family: 'JetBrains Mono', monospace;"><?php echo $total_switches; ?></div>
            </div>
            <div style="padding: 8px; background: rgba(56, 189, 248, 0.1); border-radius: var(--radius-sm); color: #38bdf8;">
                <i data-lucide="server" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.5rem;">Managed bridge devices</div>
    </div>

    <div class="detective-card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;">Active Loop Suspects</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: <?php echo $total_loop_suspects > 0 ? 'var(--danger)' : 'var(--success)'; ?>; margin-top: 0.25rem; font-family: 'JetBrains Mono', monospace;">
                    <?php echo $total_loop_suspects; ?>
                </div>
            </div>
            <div style="padding: 8px; background: <?php echo $total_loop_suspects > 0 ? 'rgba(239, 68, 68, 0.1)' : 'rgba(16, 185, 129, 0.1)'; ?>; border-radius: var(--radius-sm); color: <?php echo $total_loop_suspects > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                <i data-lucide="<?php echo $total_loop_suspects > 0 ? 'alert-triangle' : 'shield-check'; ?>" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.5rem;">
            <?php echo $total_loop_suspects > 0 ? 'Switches exhibiting loop flags' : 'All switch topologies clean'; ?>
        </div>
    </div>

    <div class="detective-card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;">Quarantined Ports</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: <?php echo $total_blocked_ports > 0 ? '#f59e0b' : 'var(--text)'; ?>; margin-top: 0.25rem; font-family: 'JetBrains Mono', monospace;">
                    <?php echo $total_blocked_ports; ?>
                </div>
            </div>
            <div style="padding: 8px; background: rgba(245, 158, 11, 0.1); border-radius: var(--radius-sm); color: #f59e0b;">
                <i data-lucide="shield-alert" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.5rem;">STP Blocking state active</div>
    </div>

    <div class="detective-card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;">Diagnostic Engine</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: #10b981; margin-top: 0.25rem; font-family: 'JetBrains Mono', monospace;">Ready</div>
            </div>
            <div style="padding: 8px; background: rgba(16, 185, 129, 0.1); border-radius: var(--radius-sm); color: #10b981;">
                <i data-lucide="activity" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.5rem;">6-Phase SNMP Live Prober</div>
    </div>
</div>

<!-- Target Switch Selector -->
<div class="target-selector-card">
    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; flex: 1;">
        <label for="switchSelect" style="font-weight: 600; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
            Target Switch:
        </label>
        <select id="switchSelect" onchange="switchTarget(this.value)" style="min-width: 280px; background: var(--background); border: 1px solid var(--border); color: var(--text); border-radius: var(--radius-sm); padding: 7px 12px; font-size: 0.9rem; font-weight: 500;">
            <?php foreach ($switches as $sw): ?>
                <?php
                $sw_alert = '';
                if (!empty($sw['blocked_ports']) && $sw['blocked_ports'] > 0) {
                    $sw_alert = " 🚫 (" . $sw['blocked_ports'] . " BLOCKED)";
                } elseif (!empty($sw['loop_detected'])) {
                    $sw_alert = " ⚠️ (LOOP ALERT)";
                }
                ?>
                <option value="<?php echo $sw['id']; ?>" <?php echo $sw['id'] == $selected_switch_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sw['name'] . ' (' . ($sw['ip_addr'] ?? '') . ')' . $sw_alert); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div style="display: flex; gap: 0.75rem; align-items: center;">
        <button id="btnStartAnalysis" class="btn btn-primary" onclick="triggerAnalyze()" style="font-size: 0.85rem; gap: 6px;">
            <i data-lucide="search" style="width: 15px;"></i> Start Loop Analysis
        </button>
        <button id="btnDeepProbe" class="btn btn-secondary" onclick="triggerDeepProbe()" style="font-size: 0.85rem; gap: 6px;">
            <i data-lucide="zap" style="width: 15px;"></i> Deep Investigation
        </button>
    </div>
</div>

<?php if ($analysis): ?>
    <?php
    $sw = $analysis['switch'];
    $risk = $analysis['risk_score'];
    $risk_color = $risk >= 70 ? 'var(--danger)' : ($risk >= 30 ? 'var(--warning)' : 'var(--success)');
    $stp_status_text = !empty($analysis['blocked_ports']) ? '⚠️ ' . count($analysis['blocked_ports']) . ' Quarantined' : 'Stable Forwarding';
    $tcn_val = (int)($sw['stp_topology_changes'] ?? 0);
    $fdb_status = !empty($analysis['flapping_macs']) ? '🔴 Anomaly' : '🟢 Clean';
    $flapping_status = !empty($analysis['flapping_macs']) ? '🔴 Detected' : '🟢 None';
    $port_movement = !empty($analysis['flapping_macs']) ? $analysis['flapping_macs'][0]['port_pair'] : 'Single Interface';
    ?>

    <!-- Main Forensic Workspace -->
    <div class="forensic-workspace">
        
        <!-- Column 1: Live Telemetry Radar -->
        <div class="detective-card" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="font-size: 0.85rem; font-weight: 700; color: var(--text); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                    <span>🔎 Live Telemetry Radar</span>
                    <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 400; font-family: 'JetBrains Mono', monospace;"><?php echo htmlspecialchars($sw['ip_addr'] ?? ''); ?></span>
                </div>

                <div class="radar-item">
                    <span class="radar-label"><i data-lucide="shield" style="width: 14px;"></i> STP / RSTP</span>
                    <span class="radar-value" style="color: <?php echo !empty($analysis['blocked_ports']) ? '#f59e0b' : 'var(--success)'; ?>;">
                        <?php echo $stp_status_text; ?>
                    </span>
                </div>

                <div class="radar-item">
                    <span class="radar-label"><i data-lucide="git-commit" style="width: 14px;"></i> TCN Activity</span>
                    <span class="radar-value" style="color: <?php echo $tcn_val > 50 ? 'var(--warning)' : 'var(--text)'; ?>;">
                        <?php echo $tcn_val; ?> changes
                    </span>
                </div>

                <div class="radar-item">
                    <span class="radar-label"><i data-lucide="database" style="width: 14px;"></i> FDB Table</span>
                    <span class="radar-value"><?php echo $fdb_status; ?></span>
                </div>

                <div class="radar-item">
                    <span class="radar-label"><i data-lucide="refresh-cw" style="width: 14px;"></i> MAC Flapping</span>
                    <span class="radar-value"><?php echo $flapping_status; ?></span>
                </div>

                <div class="radar-item">
                    <span class="radar-label"><i data-lucide="arrow-left-right" style="width: 14px;"></i> Port Movement</span>
                    <span class="radar-value" style="color: <?php echo !empty($analysis['flapping_macs']) ? 'var(--danger)' : 'var(--text-muted)'; ?>;">
                        <?php echo htmlspecialchars($port_movement); ?>
                    </span>
                </div>

                <?php if (!empty($analysis['flapping_macs'])): ?>
                    <div style="margin-top: 0.75rem; padding: 0.65rem 0.75rem; background: rgba(239, 68, 68, 0.08); border-radius: var(--radius-sm); border: 1px solid rgba(239, 68, 68, 0.2); font-size: 0.75rem;">
                        <span style="color: var(--danger); font-weight: 600;">Oscillating MAC:</span>
                        <div style="font-family: 'JetBrains Mono', monospace; font-weight: 600; color: var(--text); margin-top: 2px;">
                            <?php echo strtoupper($analysis['flapping_macs'][0]['mac_addr']); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Risk Gauge Meter -->
            <div class="risk-meter-container">
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                    <span style="font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Loop Risk</span>
                    <span style="font-weight: 700; font-family: 'JetBrains Mono', monospace; color: <?php echo $risk_color; ?>; font-size: 1.1rem;">
                        <?php echo $risk; ?>%
                    </span>
                </div>
                <div class="risk-meter-bar">
                    <div class="risk-meter-fill" style="width: <?php echo $risk; ?>%; background: <?php echo $risk_color; ?>;"></div>
                </div>
            </div>
        </div>

        <!-- Column 2: Investigation Path (Visual Tree) -->
        <div class="detective-card">
            <div style="font-size: 0.85rem; font-weight: 700; color: var(--text); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                <span>🧭 Investigation Path (Downstream Trace)</span>
                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 400;">From Core to Root-Cause Suspect</span>
            </div>

            <div class="path-container">
                <?php foreach ($analysis['investigation_path'] as $idx => $node): ?>
                    <?php
                    $node_icon = match($node['role']) {
                        'UPSTREAM_CORE'      => 'server',
                        'TARGET_SWITCH'      => 'layers',
                        'DOWNSTREAM_SWITCH',
                        'DOWNSTREAM_SEGMENT' => 'git-branch',
                        'CULPRIT_DEVICE'     => 'alert-circle',
                        default              => 'hash'
                    };
                    ?>
                    <div class="path-node status-<?php echo $node['status']; ?>">
                        <div class="path-connector">
                            <div class="path-circle">
                                <i data-lucide="<?php echo $node_icon; ?>" style="width: 15px; height: 15px;"></i>
                            </div>
                            <div class="path-line"></div>
                        </div>
                        <div class="path-content">
                            <div class="path-node-header">
                                <div class="path-node-title">
                                    <?php echo htmlspecialchars($node['name']); ?>
                                </div>
                                <span class="badge" style="background: <?php echo $node['badge_color']; ?>; color: white; font-size: 0.65rem; font-weight: 700; padding: 2px 7px;">
                                    <?php echo htmlspecialchars($node['badge']); ?>
                                </span>
                            </div>
                            <div class="path-node-meta" style="margin-bottom: 0.35rem;">
                                <?php echo htmlspecialchars($node['ip']); ?> • <?php echo htmlspecialchars($node['model']); ?>
                            </div>
                            <div class="path-node-desc">
                                <?php echo htmlspecialchars($node['status_text']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Forensic Verdict & Action Plan -->
    <?php $v = $analysis['verdict']; ?>
    <div class="verdict-box type-<?php echo $v['type']; ?>">
        <div style="display: flex; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.75rem;">
            <i data-lucide="<?php echo $v['type'] === 'danger' ? 'alert-octagon' : ($v['type'] === 'warning' ? 'alert-triangle' : 'check-circle-2'); ?>" style="width: 22px; height: 22px; color: <?php echo $v['type'] === 'danger' ? 'var(--danger)' : ($v['type'] === 'warning' ? 'var(--warning)' : 'var(--success)'); ?>; flex-shrink: 0; margin-top: 2px;"></i>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text); margin-bottom: 0.25rem;">
                    <?php echo htmlspecialchars($v['title']); ?>
                </h3>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0; line-height: 1.4;">
                    <?php echo htmlspecialchars($v['summary']); ?>
                </p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255, 255, 255, 0.08);">
            <div>
                <h4 style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text); margin-bottom: 0.5rem;">
                    Key Evidence:
                </h4>
                <ul style="margin: 0; padding-left: 1.25rem; font-size: 0.8rem; color: var(--text-muted); line-height: 1.5;">
                    <?php foreach ($v['evidence'] as $ev): ?>
                        <li><?php echo htmlspecialchars($ev); ?></li>
                    <?php endforeach; ?>
                </ul>
                <div style="margin-top: 0.75rem; font-size: 0.75rem; color: var(--text-muted); font-style: italic;">
                    <strong>Scope Boundary:</strong> <?php echo htmlspecialchars($v['scope_note']); ?>
                </div>
            </div>

            <div>
                <h4 style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text); margin-bottom: 0.5rem;">
                    NOC Remediation Steps:
                </h4>
                <ol style="margin: 0; padding-left: 1.25rem; font-size: 0.8rem; color: var(--text-muted); line-height: 1.5;">
                    <?php foreach ($v['actions'] as $act): ?>
                        <li style="margin-bottom: 0.25rem;"><?php echo htmlspecialchars($act); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </div>

    <!-- Deep Prober Terminal Modal / Container -->
    <div id="deepProbeSection" class="detective-card" style="display: none; margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
            <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="terminal" style="width: 16px;"></i> Deep Investigation Telemetry
            </div>
            <button class="btn btn-secondary" onclick="document.getElementById('deepProbeSection').style.display='none'" style="padding: 2px 8px; font-size: 0.75rem;">
                Close Inspector
            </button>
        </div>
        <div id="deepProbeTerminal" class="terminal-box">Running 6-phase live SNMP probe...</div>
    </div>

<?php endif; ?>

<!-- Global Managed Switches STP Matrix -->
<div class="detective-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <h3 style="font-size: 0.95rem; font-weight: 700; color: var(--text); margin-bottom: 0.2rem;">
                Global Switches STP Health Matrix
            </h3>
            <p style="color: var(--text-muted); font-size: 0.75rem; margin: 0;">
                All network bridge nodes monitored by background SNMP telemetry
            </p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table" style="width: 100%; font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>Switch Name</th>
                    <th>IP Address</th>
                    <th>Model / Vendor</th>
                    <th>STP State</th>
                    <th>Blocked Ports</th>
                    <th>Topology Changes</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($switches as $sw): ?>
                    <?php
                    $is_target = ($sw['id'] == $selected_switch_id);
                    $blocked_cnt = (int)($sw['blocked_ports'] ?? 0);
                    $has_loop = !empty($sw['loop_detected']) || $blocked_cnt > 0;
                    ?>
                    <tr style="<?php echo $is_target ? 'background: rgba(56, 189, 248, 0.05);' : ''; ?>">
                        <td style="font-weight: 600;">
                            <?php echo htmlspecialchars($sw['name']); ?>
                            <?php if ($is_target): ?>
                                <span class="badge" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8; font-size: 0.65rem; padding: 1px 5px; margin-left: 4px;">ACTIVE</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-family: 'JetBrains Mono', monospace; font-size: 0.8rem;">
                            <?php echo htmlspecialchars($sw['ip_addr'] ?? ''); ?>
                        </td>
                        <td style="color: var(--text-muted);">
                            <?php echo htmlspecialchars($sw['model'] ?: 'Generic Switch'); ?>
                        </td>
                        <td>
                            <?php if ($blocked_cnt > 0): ?>
                                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: var(--danger); font-size: 0.75rem;">
                                    Quarantined (<?php echo $blocked_cnt; ?>)
                                </span>
                            <?php elseif (!empty($sw['loop_detected'])): ?>
                                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: var(--danger); font-size: 0.75rem;">
                                    MAC Thrashing
                                </span>
                            <?php elseif (isset($sw['stp_enabled']) && (int)$sw['stp_enabled'] === 0): ?>
                                <span class="badge" style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted); font-size: 0.75rem;">
                                    Disabled
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: var(--success); font-size: 0.75rem;">
                                    Forwarding
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-family: 'JetBrains Mono', monospace;">
                            <?php if ($blocked_cnt > 0): ?>
                                <span style="color: var(--danger); font-weight: 700;"><?php echo $blocked_cnt; ?> port(s)</span>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">0</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-family: 'JetBrains Mono', monospace; color: var(--text-muted);">
                            <?php echo (int)($sw['stp_topology_changes'] ?? 0); ?> events
                        </td>
                        <td style="text-align: right;">
                            <a href="loop-detective?switch_id=<?php echo $sw['id']; ?>" class="btn btn-secondary" style="padding: 3px 9px; font-size: 0.75rem;">
                                <i data-lucide="search" style="width: 12px;"></i> Investigate
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function switchTarget(switchId) {
    if (!switchId) return;
    window.location.href = 'loop-detective?switch_id=' + switchId;
}

function triggerAnalyze() {
    const swId = document.getElementById('switchSelect').value;
    switchTarget(swId);
}

async function triggerDeepProbe() {
    const swId = document.getElementById('switchSelect').value;
    const sec = document.getElementById('deepProbeSection');
    const term = document.getElementById('deepProbeTerminal');

    sec.style.display = 'block';
    term.innerText = '⚡ Connecting to switch via SNMP...\nExecuting 6-phase diagnostic probe...\n';

    try {
        const res = await fetch('api/loop-detective.php?action=deep_probe&switch_id=' + swId);
        const json = await res.json();

        if (json.error) {
            term.innerText = '❌ Error: ' + json.error;
            return;
        }

        const p = json.probe;
        let out = '';
        out += '==================== 6-PHASE FORENSIC PROBE ====================\n';
        out += `[Phase 1] Target Identity       : ${p.sys_name} (${p.sys_descr.substring(0, 60)}...)\n`;
        out += `[Phase 2] Spanning Tree Protocol: ${p.stp_name} (Root Path Cost: ${p.root_cost})\n`;
        out += `[Phase 3] Port State Audit      : ${p.forwarding_ports.length} Forwarding, ${p.blocked_ports.length} Quarantined, ${p.down_ports.length} Down\n`;
        if (p.blocked_ports.length > 0) {
            out += `          ⚠️ Quarantined Ports  : ${p.blocked_ports.join(', ')}\n`;
        }
        out += `[Phase 4] TCN Activity          : ${p.tcn_count} Total Events (${p.tcn_seconds}s since last change)\n`;
        out += `[Phase 5] CAM Table Audit       : `;
        if (p.flapping_macs.length > 0) {
            out += `⚠️ FLAPPING DETECTED (${p.flapping_macs.length} oscillating MACs)\n`;
            p.flapping_macs.forEach(fm => {
                out += `          • MAC ${fm.mac_addr.toUpperCase()} active on [${fm.port_pair}]\n`;
            });
        } else {
            out += `Clean (No multi-port MAC oscillation)\n`;
        }
        out += `[Phase 6] Diagnostic Confidence : ${p.confidence}% Grade\n`;
        out += '=================================================================\n';

        term.innerText = out;
    } catch (e) {
        term.innerText = '❌ Probe Execution Failed: ' + e.message;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
