<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/network.php';
require_once 'includes/conflict.helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$page_title = 'Conflict Center';
include 'includes/header.php';

$db = get_db_connection();

// 1. Fetch KPI metrics
$active_count = 0;
$flaps_24h = 0;
$resolved_today = 0;
$total_subnets = 0;

try {
    $active_count = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE conflict_detected = 1")->fetchColumn() ?: 0;
    $flaps_24h = $db->query("SELECT COALESCE(SUM(flap_count), 0) FROM ip_conflict_events WHERE detected_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() ?: 0;
    $resolved_today = $db->query("SELECT COUNT(*) FROM ip_conflict_events WHERE status = 'resolved' AND resolved_at >= CURDATE()")->fetchColumn() ?: 0;
    $total_subnets = $db->query("SELECT COUNT(*) FROM subnets")->fetchColumn() ?: 0;
} catch (Exception $e) {}

// 2. Fetch Active Conflict IP records with Subnet & Switch attachment context
$active_conflicts = [];
try {
    $stmt = $db->query("
        SELECT ip.*, s.subnet, s.mask, s.description as subnet_desc
        FROM ip_addresses ip
        LEFT JOIN subnets s ON ip.subnet_id = s.id
        WHERE ip.conflict_detected = 1
        ORDER BY ip.last_seen DESC
    ");
    $active_conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Enrich active conflicts with switch port attachments and latest event info
foreach ($active_conflicts as &$ac) {
    $ac['port_primary'] = ConflictHelper::findSwitchPort($db, $ac['mac_addr']);
    $ac['port_conflict'] = !empty($ac['conflict_mac']) ? ConflictHelper::findSwitchPort($db, $ac['conflict_mac']) : null;
    $ac['vendor_conflict'] = (!empty($ac['conflict_mac']) && function_exists('get_vendor_by_mac')) ? get_vendor_by_mac($ac['conflict_mac']) : 'Unknown';
    
    // Fetch latest event details
    try {
        $evStmt = $db->prepare("SELECT * FROM ip_conflict_events WHERE ip_addr = ? AND status = 'active' ORDER BY detected_at DESC LIMIT 1");
        $evStmt->execute([$ac['ip_addr']]);
        $ac['event'] = $evStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $ac['event'] = null;
    }
}
unset($ac);

// 3. Fetch Event History Timeline (last 30 events)
$history_events = [];
try {
    $history_events = $db->query("
        SELECT * FROM ip_conflict_events 
        ORDER BY detected_at DESC 
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<style>
.conflict-header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.conflict-actions-bar {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    flex-wrap: wrap;
}
.conflict-dual-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 1.5rem;
    align-items: stretch;
    margin-top: 1.25rem;
}
.conflict-vs-divider {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}
.conflict-vs-line {
    width: 1px;
    flex-grow: 1;
    background: rgba(239,68,68,0.25);
    min-height: 20px;
}

@media (max-width: 860px) {
    .conflict-dual-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    .conflict-vs-divider {
        flex-direction: row;
        width: 100%;
        margin: 0.5rem 0;
    }
    .conflict-vs-line {
        height: 1px;
        width: 100%;
        min-height: auto;
    }
    .conflict-header-bar {
        flex-direction: column;
        align-items: flex-start;
    }
    .conflict-actions-bar {
        width: 100%;
    }
    .conflict-actions-bar .btn {
        flex: 1 1 auto;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .conflict-actions-bar .btn {
        font-size: 0.7rem;
        padding: 0.35rem 0.5rem;
    }
}
</style>

<div class="page-header animate-up">
    <div>
        <h1 style="font-size: 1.5rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 0.6rem;">
            <i data-lucide="shield-alert" style="color: #ef4444; width: 26px; height: 26px;"></i>
            IP Conflict & Flap Center
        </h1>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin: 4px 0 0 0;">
            Real-time MAC flapping surveillance, rogue device identification, and 1-click conflict mitigation.
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <button type="button" class="btn btn-secondary" onclick="location.reload()" style="font-size: 0.8rem; background: var(--surface-light);">
            <i data-lucide="refresh-cw" style="width: 14px;"></i> Refresh
        </button>
        <a href="tools?tab=conflict" class="btn btn-primary" style="font-size: 0.8rem;">
            <i data-lucide="crosshair" style="width: 14px;"></i> Prober Tool
        </a>
    </div>
</div>

<!-- NOC Conflict KPI Metric Cards -->
<div class="grid-stats animate-up" style="margin-bottom: 2rem;">
    <!-- Active Conflicts -->
    <div class="stat-card" style="<?php echo $active_count > 0 ? 'border-color: rgba(239,68,68,0.5); background: linear-gradient(135deg, var(--surface) 0%, rgba(239,68,68,0.08) 100%);' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label">Active Conflicts</span>
            <div class="icon-box-sm" style="<?php echo $active_count > 0 ? 'color: #ef4444; background: rgba(239,68,68,0.15);' : ''; ?>">
                <i data-lucide="alert-triangle" style="width: 15px; height: 15px;"></i>
            </div>
        </div>
        <div class="stat-value" style="display: flex; align-items: baseline; gap: 0.4rem; margin: 0.25rem 0;">
            <span style="<?php echo $active_count > 0 ? 'color: #ef4444;' : 'color: var(--success);'; ?>">
                <?php echo $active_count; ?>
            </span>
            <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted); font-family: 'Inter', sans-serif;">IPs</span>
        </div>
        <div class="stat-meta">
            <?php if ($active_count > 0): ?>
                <span style="color: #ef4444; font-weight: 600;">⚠️ Requiring immediate NOC triage</span>
            <?php else: ?>
                <span style="color: var(--success); font-weight: 500;">✓ All IP assignments stable</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Flapping Events (24h) -->
    <div class="stat-card">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label">Flap Events (24h)</span>
            <div class="icon-box-sm">
                <i data-lucide="repeat" style="width: 15px; height: 15px; color: var(--warning);"></i>
            </div>
        </div>
        <div class="stat-value" style="display: flex; align-items: baseline; gap: 0.4rem; margin: 0.25rem 0;">
            <span><?php echo number_format($flaps_24h); ?></span>
            <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted); font-family: 'Inter', sans-serif;">Flaps</span>
        </div>
        <div class="stat-meta">
            <span>Cumulative ARP/MAC oscillations</span>
        </div>
    </div>

    <!-- Resolved Today -->
    <div class="stat-card">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label">Mitigated Today</span>
            <div class="icon-box-sm">
                <i data-lucide="check-circle" style="width: 15px; height: 15px; color: var(--success);"></i>
            </div>
        </div>
        <div class="stat-value" style="display: flex; align-items: baseline; gap: 0.4rem; margin: 0.25rem 0;">
            <span style="color: var(--success);"><?php echo $resolved_today; ?></span>
            <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted); font-family: 'Inter', sans-serif;">Resolved</span>
        </div>
        <div class="stat-meta">
            <span>Conflicts cleared or accepted</span>
        </div>
    </div>

    <!-- Subnets Monitored -->
    <div class="stat-card">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label">Protected Ranges</span>
            <div class="icon-box-sm">
                <i data-lucide="layers" style="width: 15px; height: 15px; color: var(--primary);"></i>
            </div>
        </div>
        <div class="stat-value" style="display: flex; align-items: baseline; gap: 0.4rem; margin: 0.25rem 0;">
            <span><?php echo $total_subnets; ?></span>
            <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted); font-family: 'Inter', sans-serif;">Subnets</span>
        </div>
        <div class="stat-meta">
            <span>Under continuous integrity sweep</span>
        </div>
    </div>
</div>

<!-- Section: Active Host Conflicts (Side-by-Side Comparison) -->
<div style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h2 style="font-size: 1.15rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="users" style="color: #f87171; width: 18px; height: 18px;"></i>
            Active Conflict Investigations (<?php echo count($active_conflicts); ?>)
        </h2>
    </div>

    <?php if (empty($active_conflicts)): ?>
        <div class="card" style="padding: 3rem 2rem; text-align: center; background: rgba(0,0,0,0.1); border-style: dashed;">
            <div style="width: 50px; height: 50px; border-radius: 50%; background: rgba(63,185,80,0.15); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; color: var(--success);">
                <i data-lucide="shield-check" style="width: 28px; height: 28px;"></i>
            </div>
            <h3 style="font-size: 1.15rem; font-weight: 700; margin: 0 0 0.5rem 0;">All Clear: No IP Conflicts Detected</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted); max-width: 500px; margin: 0 auto;">
                Every IP in your network is responding with a consistent, unique hardware MAC address. Scanner and switch telemetry are completely synchronized.
            </p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <?php foreach ($active_conflicts as $conflict): ?>
                <?php 
                    $flap_times = $conflict['event']['flap_count'] ?? 1;
                    $first_detected = $conflict['event']['detected_at'] ?? $conflict['last_seen'];
                ?>
                <div class="card animate-up" style="border: 1px solid rgba(239,68,68,0.4); background: linear-gradient(180deg, var(--surface) 0%, rgba(239,68,68,0.02) 100%); padding: 1.5rem; border-radius: var(--radius); box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
                    <!-- Header Bar of Conflict Card -->
                    <div class="conflict-header-bar">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="background: rgba(239,68,68,0.15); padding: 6px 10px; border-radius: 6px; font-family: 'JetBrains Mono', monospace; font-size: 1.15rem; font-weight: 700; color: #f87171; letter-spacing: 0.5px;">
                                <?php echo htmlspecialchars($conflict['ip_addr']); ?>
                            </div>
                            <div>
                                <span class="badge-pill" style="background: rgba(88,166,255,0.15); color: var(--primary); font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($conflict['subnet'] . '/' . $conflict['mask']); ?>
                                </span>
                                <?php if ($flap_times > 1): ?>
                                    <span class="badge-pill" style="background: rgba(245,158,11,0.15); color: #f59e0b; font-size: 0.75rem; font-weight: 700;">
                                        ⚡ Flapped <?php echo $flap_times; ?>x
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Action Buttons -->
                        <div class="conflict-actions-bar">
                            <button type="button" class="btn btn-secondary" onclick="openIpIntelligence('<?php echo htmlspecialchars($conflict['ip_addr']); ?>')" style="font-size: 0.75rem; padding: 0.4rem 0.75rem; color: var(--primary); border-color: rgba(88,166,255,0.3);">
                                <i data-lucide="scan" style="width: 13px;"></i> Dossier 360°
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="runLiveProbe('<?php echo htmlspecialchars($conflict['ip_addr']); ?>')" style="font-size: 0.75rem; padding: 0.4rem 0.75rem; background: rgba(59,130,246,0.15); color: #93c5fd; border-color: rgba(59,130,246,0.3);">
                                <i data-lucide="crosshair" style="width: 13px;"></i> Multi-Probe Live
                            </button>
                            <?php if (!empty($conflict['conflict_mac'])): ?>
                                <button type="button" class="btn btn-secondary" onclick="acceptNewHost('<?php echo htmlspecialchars($conflict['ip_addr']); ?>', '<?php echo htmlspecialchars($conflict['conflict_mac']); ?>')" style="font-size: 0.75rem; padding: 0.4rem 0.75rem; background: rgba(63,185,80,0.15); color: var(--success); border-color: rgba(63,185,80,0.3);" title="Accept and assign the new MAC as the legitimate owner of this IP">
                                    <i data-lucide="user-check" style="width: 13px;"></i> Accept New Host
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary" onclick="resolveConflict('<?php echo htmlspecialchars($conflict['ip_addr']); ?>')" style="font-size: 0.75rem; padding: 0.4rem 0.75rem; background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.3);">
                                <i data-lucide="check" style="width: 13px;"></i> Clear / Resolve
                            </button>
                        </div>
                    </div>

                    <!-- Side-by-Side Dual Host Comparison -->
                    <div class="conflict-dual-grid">
                        <!-- Host A (Primary / Registered) -->
                        <div style="background: rgba(0,0,0,0.2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 1.25rem; display: flex; flex-direction: column; gap: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--primary);">
                                    Host A (Inventory / Registered)
                                </span>
                                <span class="badge-pill" style="font-size: 0.7rem; background: rgba(88,166,255,0.15); color: var(--primary);">Primary</span>
                            </div>

                            <div>
                                <div style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; font-weight: 700; color: #fff;">
                                    <?php echo htmlspecialchars($conflict['mac_addr'] ?: 'Unknown MAC'); ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                                    Vendor: <strong style="color: var(--text);"><?php echo htmlspecialchars($conflict['vendor'] ?: 'Unknown'); ?></strong>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.78rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.5rem;">
                                <div>
                                    <span style="color: var(--text-muted);">Hostname:</span>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($conflict['hostname'] ?: '-'); ?></div>
                                </div>
                                <div>
                                    <span style="color: var(--text-muted);">OS Profile:</span>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($conflict['os'] ?: '-'); ?></div>
                                </div>
                            </div>

                            <div style="font-size: 0.78rem; background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 4px; border-left: 2px solid var(--primary); margin-top: auto;">
                                <span style="color: var(--text-muted); display: block; font-size: 0.7rem;">Physical Switch Attachment:</span>
                                <strong style="color: #fff; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($conflict['port_primary'] ?: 'Not mapped in switch CAM table'); ?>
                                </strong>
                            </div>
                        </div>

                        <!-- VS Badge Divider -->
                        <div class="conflict-vs-divider">
                            <div class="conflict-vs-line"></div>
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: rgba(239,68,68,0.2); border: 2px solid rgba(239,68,68,0.5); display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 800; color: #f87171; box-shadow: 0 0 10px rgba(239,68,68,0.3); flex-shrink: 0;">
                                VS
                            </div>
                            <div class="conflict-vs-line"></div>
                        </div>

                        <!-- Host B (Conflicting / Imposter) -->
                        <div style="background: rgba(239,68,68,0.05); border: 1px solid rgba(239,68,68,0.3); border-radius: var(--radius-sm); padding: 1.25rem; display: flex; flex-direction: column; gap: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #f87171;">
                                    Host B (Conflicting / Contending)
                                </span>
                                <span class="badge-pill" style="font-size: 0.7rem; background: rgba(239,68,68,0.2); color: #f87171;">Contender</span>
                            </div>

                            <div>
                                <div style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; font-weight: 700; color: #fca5a5;">
                                    <?php echo htmlspecialchars($conflict['conflict_mac'] ?: 'No alternate MAC recorded'); ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                                    Vendor: <strong style="color: var(--text);"><?php echo htmlspecialchars($conflict['vendor_conflict']); ?></strong>
                                </div>
                            </div>

                            <div style="font-size: 0.78rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.5rem;">
                                <span style="color: var(--text-muted);">Conflict Anomaly:</span>
                                <div style="color: #f87171; font-weight: 500; font-size: 0.75rem; margin-top: 2px;">
                                    <?php echo htmlspecialchars($conflict['conflict_details'] ?: 'Multiple hardware addresses responding on same IP'); ?>
                                </div>
                            </div>

                            <div style="font-size: 0.78rem; background: rgba(239,68,68,0.08); padding: 0.5rem 0.75rem; border-radius: 4px; border-left: 2px solid #ef4444; margin-top: auto;">
                                <span style="color: var(--text-muted); display: block; font-size: 0.7rem;">Physical Switch Attachment:</span>
                                <strong style="color: #fca5a5; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($conflict['port_conflict'] ?: 'Not mapped in switch CAM table'); ?>
                                </strong>
                            </div>
                        </div>
                    </div>

                    <!-- Live Probe Output Container (Injected via JS) -->
                    <div id="probe-container-<?php echo str_replace('.', '-', $conflict['ip_addr']); ?>" style="display: none; margin-top: 1.25rem; padding: 1rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(59,130,246,0.3); border-radius: var(--radius-sm);">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Section: Conflict & Flapping Timeline History -->
<div class="card animate-up" style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1.1rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="history" style="width: 16px; height: 16px; color: var(--primary);"></i>
            Flapping Timeline & Event History
        </h3>
        <span style="font-size: 0.75rem; color: var(--text-muted);">Last 30 Events Logged</span>
    </div>

    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.75rem;">Timestamp</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.75rem;">IP Address</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.75rem;">Host A (Primary)</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.75rem;">Host B (Contender)</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.75rem; text-align: center;">Flaps</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.75rem;">Status</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.75rem; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history_events)): ?>
                    <tr>
                        <td colspan="7" style="padding: 2rem; text-align: center; color: var(--text-muted);">
                            No historical flapping events recorded in database yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history_events as $he): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 0.65rem 0.75rem; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: var(--text-muted);">
                                <?php echo htmlspecialchars($he['detected_at']); ?>
                            </td>
                            <td style="padding: 0.65rem 0.75rem; font-family: 'JetBrains Mono', monospace; font-weight: 700;">
                                <a href="javascript:void(0)" onclick="openIpIntelligence('<?php echo htmlspecialchars($he['ip_addr']); ?>')" style="color: inherit; text-decoration: none; border-bottom: 1px dashed rgba(255,255,255,0.25);" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='inherit'">
                                    <?php echo htmlspecialchars($he['ip_addr']); ?>
                                </a>
                            </td>
                            <td style="padding: 0.65rem 0.75rem; font-size: 0.8rem;">
                                <div style="font-family: 'JetBrains Mono', monospace; font-size: 0.75rem;"><?php echo htmlspecialchars($he['mac_a']); ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-muted);"><?php echo htmlspecialchars($he['vendor_a'] ?: 'Unknown'); ?></div>
                            </td>
                            <td style="padding: 0.65rem 0.75rem; font-size: 0.8rem;">
                                <div style="font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: #fca5a5;"><?php echo htmlspecialchars($he['mac_b'] ?: '-'); ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-muted);"><?php echo htmlspecialchars($he['vendor_b'] ?: 'Unknown'); ?></div>
                            </td>
                            <td style="padding: 0.65rem 0.75rem; text-align: center;">
                                <span class="badge-pill" style="font-size: 0.7rem; <?php echo $he['flap_count'] > 1 ? 'background: rgba(245,158,11,0.15); color: #f59e0b;' : 'background: var(--surface-light); color: var(--text-muted);'; ?>">
                                    <?php echo (int)$he['flap_count']; ?>x
                                </span>
                            </td>
                            <td style="padding: 0.65rem 0.75rem;">
                                <?php if ($he['status'] === 'active'): ?>
                                    <span class="badge-pill" style="background: rgba(239,68,68,0.2); color: #f87171; font-weight: 700; font-size: 0.7rem;">Active</span>
                                <?php elseif ($he['status'] === 'resolved'): ?>
                                    <span class="badge-pill" style="background: rgba(63,185,80,0.15); color: var(--success); font-size: 0.7rem;">Resolved</span>
                                <?php else: ?>
                                    <span class="badge-pill" style="background: var(--surface-light); color: var(--text-muted); font-size: 0.7rem;">Ignored</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.65rem 0.75rem; text-align: right;">
                                <button type="button" class="btn btn-secondary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem; color: var(--primary); border-color: rgba(88,166,255,0.3);" onclick="openIpIntelligence('<?php echo htmlspecialchars($he['ip_addr']); ?>')">
                                    <i data-lucide="scan" style="width: 12px;"></i> Dossier
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function resolveConflict(ip) {
    if (!confirm('Mark conflict state as resolved for IP ' + ip + '?')) return;
    const fd = new FormData();
    fd.append('action', 'resolve');
    fd.append('ip', ip);
    fetch('api/conflict-actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                alert('Error: ' + (res.error || res.message));
            }
        })
        .catch(e => alert('Network error: ' + e));
}

function acceptNewHost(ip, mac) {
    if (!confirm('Accept new MAC ' + mac + ' as the legitimate host for ' + ip + '?\nThis will update your IP inventory with this MAC address.')) return;
    const fd = new FormData();
    fd.append('action', 'accept_new');
    fd.append('ip', ip);
    fd.append('mac', mac);
    fetch('api/conflict-actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                alert('Error: ' + (res.error || res.message));
            }
        })
        .catch(e => alert('Network error: ' + e));
}

function runLiveProbe(ip) {
    const safeIp = ip.replace(/\./g, '-');
    const container = document.getElementById('probe-container-' + safeIp);
    if (!container) return;

    container.style.display = 'block';
    container.innerHTML = '<div style="display: flex; align-items: center; gap: 0.5rem; color: var(--primary); font-size: 0.8rem;"><i data-lucide="loader" class="spin" style="width: 16px;"></i> Running 3-cycle ARP & TTL jitter prober on ' + ip + '...</div>';
    if (window.lucide) lucide.createIcons();

    const fd = new FormData();
    fd.append('action', 'probe');
    fd.append('ip', ip);

    fetch('api/conflict-actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                container.innerHTML = '<span style="color: #f87171; font-size: 0.8rem;">Probe failed: ' + (res.error || 'Unknown error') + '</span>';
                return;
            }

            let verdictColor = '#3fb950';
            let verdictLabel = 'STABLE (No active flapping detected during 3-cycle probe)';
            if (res.is_flapping) {
                verdictColor = '#ef4444';
                verdictLabel = 'CRITICAL: Live MAC Flapping Observed! Unique MACs: ' + res.unique_macs.join(', ');
            } else if (res.has_ttl_jitter) {
                verdictColor = '#f59e0b';
                verdictLabel = 'WARNING: TTL variance detected (possible routing asymmetry or dual stack)';
            }

            let html = '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.5rem;">';
            html += '<strong style="font-size: 0.8rem; color: ' + verdictColor + ';">Verdict: ' + verdictLabel + '</strong>';
            html += '<button type="button" class="btn" style="padding: 2px 8px; font-size: 0.7rem; background: var(--surface-light);" onclick="this.parentElement.parentElement.style.display=\'none\'">Close</button>';
            html += '</div>';

            html += '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem;">';
            res.probes.forEach(p => {
                html += '<div style="background: rgba(255,255,255,0.03); padding: 0.6rem; border-radius: 4px; font-size: 0.75rem;">';
                html += '<div style="font-weight: 700; color: var(--text-muted); margin-bottom: 4px;">Cycle ' + p.cycle + '</div>';
                html += '<div>TTL: <strong>' + (p.ttl !== null ? p.ttl : 'No response') + '</strong></div>';
                html += '<div>MAC: <code style="color: var(--primary);">' + (p.mac || 'None') + '</code></div>';
                if (p.vendor) html += '<div style="color: var(--text-muted); font-size: 0.7rem;">' + p.vendor + '</div>';
                html += '</div>';
            });
            html += '</div>';

            container.innerHTML = html;
            if (window.lucide) lucide.createIcons();
        })
        .catch(err => {
            container.innerHTML = '<span style="color: #f87171; font-size: 0.8rem;">Probe request failed: ' + err + '</span>';
        });
}
</script>

<?php include 'includes/footer.php'; ?>
