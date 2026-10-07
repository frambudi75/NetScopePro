<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}
// Release session lock to prevent blocking parallel requests
session_write_close();

$page_title = 'Dashboard';
include 'includes/header.php';

// Fetch stats (with dummy fallbacks if DB tables are empty/missing)
$db = get_db_connection();
try {
    $subnet_count = $db->query("SELECT COUNT(*) FROM subnets")->fetchColumn() ?: 0;
    $ip_count = $db->query("SELECT COUNT(*) FROM ip_addresses")->fetchColumn() ?: 0;
    $vlan_count = $db->query("SELECT COUNT(*) FROM vlans")->fetchColumn() ?: 0;

    // Server Assets Stats
    $asset_count = $db->query("SELECT COUNT(*) FROM server_assets")->fetchColumn() ?: 0;
    $asset_online = $db->query("SELECT COUNT(*) FROM server_assets WHERE status = 'ONLINE'")->fetchColumn() ?: 0;
    $asset_offline = $db->query("SELECT COUNT(*) FROM server_assets WHERE status = 'OFFLINE'")->fetchColumn() ?: 0;
    
    // Netwatch Stats
    try {
        $netwatch_count = $db->query("SELECT COUNT(*) FROM netwatch")->fetchColumn() ?: 0;
        $netwatch_up = $db->query("SELECT COUNT(*) FROM netwatch WHERE status = 'up'")->fetchColumn() ?: 0;
        $netwatch_down = $db->query("SELECT COUNT(*) FROM netwatch WHERE status = 'down'")->fetchColumn() ?: 0;
    } catch (Exception $e) {
        $netwatch_count = $netwatch_up = $netwatch_down = 0;
    }
    
    // Asset Category distribution
    $asset_categories = $db->query("
        SELECT COALESCE(category, 'General') as cat, COUNT(*) as count 
        FROM server_assets 
        GROUP BY category 
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $active_count = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state = 'active'")->fetchColumn() ?: 0;
    $reserved_count = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state = 'reserved'")->fetchColumn() ?: 0;
    $offline_count = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state = 'offline'")->fetchColumn() ?: 0;
    $avg_confidence = $db->query("SELECT ROUND(AVG(confidence_score), 1) FROM ip_addresses WHERE state IN ('active', 'reserved', 'dhcp')")->fetchColumn();
    $avg_confidence = $avg_confidence !== null ? $avg_confidence : 0;
    
    // Needs Attention count (IPs with low confidence or missing data)
    $attention_count = $db->query("
        SELECT COUNT(*) FROM ip_addresses 
        WHERE state IN ('active', 'reserved', 'dhcp')
          AND (confidence_score < 60 OR COALESCE(hostname, '') = '' OR COALESCE(mac_addr, '') = '')
    ")->fetchColumn() ?: 0;

    // Vendor Distribution Data
    $vendor_data = $db->query("
        SELECT COALESCE(vendor, 'Unknown') as vendor, COUNT(*) as count 
        FROM ip_addresses 
        WHERE state = 'active' 
        GROUP BY vendor 
        ORDER BY count DESC 
        LIMIT 6
    ")->fetchAll();

    // Network Health Data
    $health_stats = $db->query("
        SELECT state, COUNT(*) as count 
        FROM ip_addresses 
        GROUP BY state
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $recent_logs = $db->query("
        SELECT a.*, u.username 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.created_at DESC 
        LIMIT 5
    ")->fetchAll();

    // Usage Trends (Last 7 Days)
    $usage_trends = $db->query("SELECT snapshot_date, total_active FROM stats_history ORDER BY snapshot_date DESC LIMIT 7")->fetchAll();
    $usage_trends = array_reverse($usage_trends);
    
    // Fallback if empty
    if (empty($usage_trends)) {
        $usage_trends = [['snapshot_date' => date('Y-m-d'), 'total_active' => $active_count]];
    }

    // Densest Subnets
    $dense_subnets = $db->query("
        SELECT s.subnet, s.mask, 
               (COUNT(ip.id) * 100.0 / (POW(2, (32 - s.mask)) - (CASE WHEN s.mask < 31 THEN 2 ELSE 0 END))) as usage_percent
        FROM subnets s
        LEFT JOIN ip_addresses ip ON ip.subnet_id = s.id AND ip.state = 'active'
        GROUP BY s.id
        ORDER BY usage_percent DESC
        LIMIT 5
    ")->fetchAll();

    $recent_subnets = $db->query("
        SELECT s.id, s.subnet, s.mask, s.description,
               COUNT(CASE WHEN ip.state IN ('active', 'reserved', 'dhcp') THEN ip.id END) AS used_ips
        FROM subnets s
        LEFT JOIN ip_addresses ip ON ip.subnet_id = s.id
        GROUP BY s.id, s.subnet, s.mask, s.description
        ORDER BY s.id DESC
        LIMIT 8
    ")->fetchAll();

    $needs_attention = $db->query("
        SELECT ip.ip_addr, ip.hostname, ip.mac_addr, ip.state, ip.last_seen, ip.confidence_score, ip.data_sources,
               s.subnet, s.mask
        FROM ip_addresses ip
        JOIN subnets s ON s.id = ip.subnet_id
        WHERE ip.state IN ('active', 'reserved', 'dhcp')
          AND (
                ip.confidence_score < 60
                OR COALESCE(ip.hostname, '') = ''
                OR COALESCE(ip.mac_addr, '') = ''
              )
        ORDER BY ip.confidence_score ASC, ip.last_seen DESC
        LIMIT 10
    ")->fetchAll();

    // Switch Capacity Data
    $switch_stats = $db->query("SELECT SUM(total_ports) as total, SUM(active_ports) as active, COUNT(*) as count FROM switches")->fetch(PDO::FETCH_ASSOC);
    $total_switch_ports = (int)($switch_stats['total'] ?? 0);
    $active_switch_ports = (int)($switch_stats['active'] ?? 0);
    $switch_count = (int)($switch_stats['count'] ?? 0);
    $switch_online = 0;
    if ($switch_count > 0) {
        try {
            $switch_online = $db->query("SELECT COUNT(*) FROM switches WHERE last_poll > DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn() ?: 0;
        } catch (Exception $e) {}
    }
    
    // IP Conflict Stats & Active Alerts
    $conflict_count = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE conflict_detected = 1")->fetchColumn() ?: 0;
    $conflict_ips = [];
    if ($conflict_count > 0) {
        $conflict_ips = $db->query("
            SELECT ip.id, ip.ip_addr, ip.mac_addr, ip.conflict_mac, ip.hostname, ip.conflict_details, ip.subnet_id, s.subnet, s.mask
            FROM ip_addresses ip
            LEFT JOIN subnets s ON s.id = ip.subnet_id
            WHERE ip.conflict_detected = 1
            ORDER BY ip.last_seen DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // L2 Loop Detection Stats
    $loop_switches = [];
    try {
        $loop_switches = $db->query("SELECT id, name, ip_addr, loop_details FROM switches WHERE loop_detected = 1")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
} catch (Exception $e) {
    $subnet_count = 0; $ip_count = 0; $vlan_count = 0;
    $active_count = 0; $offline_count = 0; $avg_confidence = 0; $low_confidence_count = 0;
    $asset_count = 0; $asset_online = 0; $asset_offline = 0; $asset_categories = [];
    $recent_subnets = []; $needs_attention = [];
    $conflict_count = 0; $conflict_ips = [];
    $loop_switches = [];
}
?>

<?php if (!empty($loop_switches)): ?>
<!-- Critical NOC Switching Loop Alert Banner -->
<div class="section-container animate-up" style="margin-bottom: 2rem;">
    <div style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.2) 0%, rgba(220, 38, 38, 0.1) 100%); border: 1px solid rgba(239, 68, 68, 0.5); border-radius: var(--radius); padding: 1.25rem 1.5rem; position: relative; box-shadow: 0 4px 20px rgba(239, 68, 68, 0.18);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.75rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(239, 68, 68, 0.25); display: flex; align-items: center; justify-content: center; color: #ef4444; flex-shrink: 0; box-shadow: 0 0 12px rgba(239, 68, 68, 0.4);">
                    <i data-lucide="refresh-cw" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 style="font-size: 1.1rem; font-weight: 700; color: #f87171; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                        NOC Alert: L2 Switching Loop / STP Blocking Detected (<?php echo count($loop_switches); ?> Switch<?php echo count($loop_switches) > 1 ? 'es' : ''; ?>)
                    </h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0 0;">
                        Active bridge loop or STP port blocking detected. Immediate physical topology inspection recommended.
                    </p>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <a href="loop-detective" class="btn btn-primary" style="font-size: 0.8rem; padding: 0.5rem 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="search-check" style="width: 14px;"></i> Open Loop Detective
                </a>
                <a href="switches" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 0.85rem; background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #fca5a5; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="server" style="width: 14px;"></i> View Switches
                </a>
            </div>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem;">
            <?php foreach ($loop_switches as $lsw): ?>
                <div style="background: rgba(0,0,0,0.25); border: 1px solid rgba(239,68,68,0.3); border-radius: 6px; padding: 0.5rem 0.75rem; display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-weight: 700; color: #fff; font-size: 0.85rem;"><?php echo htmlspecialchars($lsw['name']); ?></span>
                    <code style="font-size: 0.75rem; color: #fca5a5;"><?php echo htmlspecialchars($lsw['ip_addr']); ?></code>
                    <span style="font-size: 0.75rem; color: #f87171;"><?php echo htmlspecialchars($lsw['loop_details'] ?: 'Loop detected'); ?></span>
                    <a href="loop-detective?switch_id=<?php echo (int)($lsw['id'] ?? 0); ?>" class="btn" style="padding: 2px 8px; font-size: 0.7rem; background: rgba(56,189,248,0.2); color: #7dd3fc; border: 1px solid rgba(56,189,248,0.3);">
                        Investigasi &rarr;
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($conflict_count > 0): ?>
<!-- Critical NOC IP Conflict Alert Banner -->
<div class="section-container animate-up" style="margin-bottom: 2rem;">
    <div style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(245, 158, 11, 0.1) 100%); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: var(--radius); padding: 1.25rem 1.5rem; position: relative; box-shadow: 0 4px 20px rgba(239, 68, 68, 0.12);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.75rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(239, 68, 68, 0.2); display: flex; align-items: center; justify-content: center; color: #ef4444; flex-shrink: 0; box-shadow: 0 0 12px rgba(239, 68, 68, 0.3);">
                    <i data-lucide="alert-triangle" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 style="font-size: 1.1rem; font-weight: 700; color: #f87171; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                        NOC Alert: IP Conflict Detected (<?php echo $conflict_count; ?> Host<?php echo $conflict_count > 1 ? 's' : ''; ?>)
                    </h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0.25rem 0 0 0;">
                        Multiple MAC addresses or conflicting OS signatures are responding to the same IP address.
                    </p>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <a href="conflicts" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 0.85rem; background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #fca5a5; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="shield-alert" style="width: 14px;"></i> Open Conflict Center &rarr;
                </a>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 0.75rem; margin-top: 1rem;">
            <?php foreach ($conflict_ips as $cip): ?>
                <div style="background: var(--surface); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: calc(var(--radius) - 2px); padding: 0.75rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;">
                    <div>
                        <div style="font-family: monospace; font-weight: 700; font-size: 0.95rem;">
                            <a href="javascript:void(0)" onclick="openIpIntelligence('<?php echo htmlspecialchars($cip['ip_addr']); ?>')" style="color: #ef4444; text-decoration: none; border-bottom: 1px dashed #ef4444; cursor: pointer;" title="Open IP Intelligence Dossier">
                                <?php echo htmlspecialchars($cip['ip_addr']); ?>
                            </a>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                            <?php echo htmlspecialchars($cip['conflict_details'] ?: ($cip['mac_addr'] . ' vs ' . ($cip['conflict_mac'] ?: 'Unknown'))); ?>
                        </div>
                        <?php if (!empty($cip['subnet'])): ?>
                            <div style="font-size: 0.7rem; color: var(--text-muted); opacity: 0.8;">
                                Subnet: <?php echo htmlspecialchars($cip['subnet'] . '/' . $cip['mask']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.35rem; align-items: flex-end;">
                        <button type="button" class="btn btn-secondary" style="font-size: 0.7rem; padding: 0.3rem 0.6rem; color: var(--primary); border-color: rgba(88, 166, 255, 0.3);" onclick="openIpIntelligence('<?php echo htmlspecialchars($cip['ip_addr']); ?>')">
                            <i data-lucide="scan" style="width: 12px; height: 12px;"></i> Dossier
                        </button>
                        <?php if (!empty($cip['subnet_id'])): ?>
                            <a href="subnet-details?id=<?php echo $cip['subnet_id']; ?>&filter=conflict" class="btn btn-secondary" style="font-size: 0.7rem; padding: 0.3rem 0.6rem; display: inline-flex; align-items: center; gap: 3px;">
                                View Subnet <i data-lucide="arrow-right" style="width: 12px;"></i>
                            </a>
                        <?php endif; ?>
                        <a href="tools?tab=conflict&ip=<?php echo urlencode($cip['ip_addr']); ?>" class="btn btn-secondary" style="font-size: 0.7rem; padding: 0.3rem 0.6rem; color: #f87171; border-color: rgba(239,68,68,0.3);">
                            Probe IP
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Executive NOC Metric Bar: 4 Core Pillars -->
<div class="grid-stats animate-up" style="margin-bottom: 2rem;">
    <!-- Pillar 1: IPAM Fleet -->
    <a href="subnets" class="stat-card" style="text-decoration: none; transition: transform 0.15s ease, border-color 0.15s ease;" onmouseover="this.style.borderColor='var(--primary)';" onmouseout="this.style.borderColor='var(--border)';">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label">IPAM Fleet</span>
            <div class="icon-box-sm">
                <i data-lucide="network" style="width: 15px; height: 15px; color: var(--primary);"></i>
            </div>
        </div>
        <div class="stat-value" style="display: flex; align-items: baseline; gap: 0.4rem; margin: 0.25rem 0;">
            <span><?php echo number_format($ip_count); ?></span>
            <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted); font-family: 'Inter', sans-serif;">IPs</span>
        </div>
        <div class="stat-meta" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 4px;">
            <span><strong><?php echo $subnet_count; ?></strong> Subnets • <strong><?php echo $vlan_count; ?></strong> VLANs</span>
            <span style="color: var(--success); font-weight: 600;"><?php echo $active_count; ?> Active</span>
        </div>
    </a>

    <!-- Pillar 2: Switch Fabric & Ports -->
    <a href="switches" class="stat-card" style="text-decoration: none; transition: transform 0.15s ease, border-color 0.15s ease;" onmouseover="this.style.borderColor='var(--success)';" onmouseout="this.style.borderColor='var(--border)';">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label">Switch Fabric</span>
            <div class="icon-box-sm">
                <i data-lucide="server" style="width: 15px; height: 15px; color: var(--success);"></i>
            </div>
        </div>
        <div class="stat-value" style="display: flex; align-items: baseline; gap: 0.4rem; margin: 0.25rem 0;">
            <span style="color: var(--success);"><?php echo number_format($active_switch_ports); ?></span>
            <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted); font-family: 'Inter', sans-serif;">/ <?php echo number_format($total_switch_ports); ?> Ports</span>
        </div>
        <div class="stat-meta" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 4px;">
            <span><strong><?php echo $switch_count; ?></strong> Switches (<?php echo $switch_online; ?> live)</span>
            <?php $port_pct = $total_switch_ports > 0 ? round(($active_switch_ports / $total_switch_ports) * 100) : 0; ?>
            <span class="badge-pill" style="font-size: 0.7rem; padding: 2px 6px; background: rgba(63, 185, 80, 0.15); color: var(--success);"><?php echo $port_pct; ?>% Used</span>
        </div>
    </a>

    <!-- Pillar 3: Netwatch & Infra Health -->
    <a href="netwatch" class="stat-card" style="text-decoration: none; transition: transform 0.15s ease, border-color 0.15s ease;" onmouseover="this.style.borderColor='#f59e0b';" onmouseout="this.style.borderColor='var(--border)';">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label">Netwatch Monitor</span>
            <div class="icon-box-sm">
                <i data-lucide="eye" style="width: 15px; height: 15px; color: #f59e0b;"></i>
            </div>
        </div>
        <div class="stat-value" style="display: flex; align-items: baseline; gap: 0.4rem; margin: 0.25rem 0;">
            <span><?php echo $netwatch_count; ?></span>
            <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted); font-family: 'Inter', sans-serif;">Targets</span>
        </div>
        <div class="stat-meta" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 4px;">
            <span>
                <strong style="color: var(--success);"><?php echo $netwatch_up; ?> UP</strong>
                <?php if ($netwatch_down > 0): ?>
                    • <strong style="color: var(--danger);"><?php echo $netwatch_down; ?> DOWN</strong>
                <?php else: ?>
                    • <span style="color: var(--text-muted);">0 Down</span>
                <?php endif; ?>
            </span>
            <?php if ($asset_count > 0): ?>
                <span style="color: var(--text-muted); font-size: 0.7rem;"><?php echo $asset_online; ?>/<?php echo $asset_count; ?> Srv</span>
            <?php endif; ?>
        </div>
    </a>

    <!-- Pillar 4: Discovery Quality & Confidence -->
    <a href="#needs-attention" class="stat-card" style="text-decoration: none; transition: transform 0.15s ease, border-color 0.15s ease;" onmouseover="this.style.borderColor='var(--primary)';" onmouseout="this.style.borderColor='var(--border)';">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="stat-label">Discovery Quality</span>
            <div class="icon-box-sm">
                <i data-lucide="shield-check" style="width: 15px; height: 15px; color: <?php echo ($avg_confidence >= 80 ? 'var(--success)' : ($avg_confidence >= 60 ? 'var(--warning)' : 'var(--danger)')); ?>;"></i>
            </div>
        </div>
        <div class="stat-value" style="display: flex; align-items: baseline; gap: 0.4rem; margin: 0.25rem 0;">
            <span style="color: <?php echo ($avg_confidence >= 80 ? 'var(--success)' : ($avg_confidence >= 60 ? 'var(--warning)' : 'var(--danger)')); ?>;"><?php echo $avg_confidence; ?>%</span>
            <span style="font-size: 0.85rem; font-weight: 500; color: var(--text-muted); font-family: 'Inter', sans-serif;">Avg Conf</span>
        </div>
        <div class="stat-meta" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 4px;">
            <?php if ($attention_count > 0): ?>
                <span style="color: var(--warning); font-weight: 600;">⚠️ <?php echo $attention_count; ?> Need Info</span>
            <?php else: ?>
                <span style="color: var(--success); font-weight: 500;">✓ High Confidence</span>
            <?php endif; ?>
            <span style="color: var(--text-muted); font-size: 0.7rem;"><?php echo $offline_count; ?> Offline</span>
        </div>
    </a>
</div>

<!-- Telemetry & Growth Row -->
<div class="grid-2-1 animate-up" style="margin-bottom: 2rem;">
    <!-- 7-Day Usage Trend Chart -->
    <div class="card chart-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="font-size: 0.875rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="trending-up" style="width: 15px; height: 15px; color: var(--primary);"></i>
                7-Day Host Growth & Usage Trend
            </h3>
            <span style="font-size: 0.75rem; color: var(--text-muted);">Daily Active Count</span>
        </div>
        <div style="flex-grow: 1; position: relative;"><canvas id="trendChart"></canvas></div>
    </div>
    
    <!-- Modern Progress Widget -->
    <div class="card" style="display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1.25rem;">
            <div>
                <h5 style="font-size: 1.05rem; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                    Network Allocation
                    <i data-lucide="help-circle" style="width: 14px; color: var(--text-muted); cursor: help;" title="Overall pool capacity across all configured subnets"></i>
                </h5>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">Real-time allocation & health status.</p>
            </div>
        </div>

        <div style="background: rgba(0,0,0,0.1); border: 1px solid var(--border); padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1rem;">
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-bottom: 0.75rem;">
                <dl class="progress-stat-card bg-brand-soft" style="margin: 0; padding: 0.5rem;">
                    <dt class="stat-circle-brand"><?php echo $attention_count; ?></dt>
                    <dd style="color: var(--primary); font-size: 0.7rem;">Needs Info</dd>
                </dl>
                <dl class="progress-stat-card bg-warning-soft" style="margin: 0; padding: 0.5rem;">
                    <dt class="stat-circle-warning"><?php echo $reserved_count; ?></dt>
                    <dd style="color: var(--warning); font-size: 0.7rem;">Reserved</dd>
                </dl>
                <dl class="progress-stat-card bg-success-soft" style="margin: 0; padding: 0.5rem;">
                    <dt class="stat-circle-success"><?php echo $active_count; ?></dt>
                    <dd style="color: var(--success); font-size: 0.7rem;">Active</dd>
                </dl>
            </div>
            
            <button id="toggle-details" style="background: transparent; border: none; font-size: 0.75rem; color: var(--text-muted); font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 4px; padding: 0;">
                Show more details <i data-lucide="chevron-down" style="width: 13px;"></i>
            </button>
            
            <div id="extra-details" style="display: none; border-top: 1px solid var(--border); margin-top: 0.75rem; padding-top: 0.75rem; flex-direction: column; gap: 0.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Avg Confidence:</span>
                    <span class="badge-pill" style="color: var(--success); font-size: 0.75rem;"><?php echo $avg_confidence; ?>%</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Offline Hosts:</span>
                    <span class="badge-pill" style="color: var(--text); font-size: 0.75rem;"><?php echo $offline_count; ?></span>
                </div>
            </div>
        </div>

        <!-- Radial Chart Section -->
        <div style="flex-grow: 1; min-height: 180px; display: flex; align-items: center; justify-content: center; position: relative;">
            <canvas id="radialChart"></canvas>
            <div style="position: absolute; top: 62%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                <?php 
                    $total_allocated = $active_count + $reserved_count;
                    $total_all = max(1, $subnet_count * 254);
                    $progress_pct = round(($total_allocated / $total_all) * 100, 1);
                ?>
                <span style="display: block; font-size: 1.5rem; font-weight: 700; line-height: 1;"><?php echo $progress_pct; ?>%</span>
                <span style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Allocated</span>
            </div>
        </div>

        <div style="margin-top: auto; border-top: 1px solid var(--border); padding-top: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo number_format($total_allocated); ?> of ~<?php echo number_format($total_all); ?> IPs</span>
            <a href="reports" class="text-primary" style="font-size: 0.75rem; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                Full Report <i data-lucide="arrow-right" style="width: 13px;"></i>
            </a>
        </div>
    </div>
</div>

<!-- Hardware Capacity & Distribution Row -->
<div class="grid-2-1 animate-up" style="margin-bottom: 2rem;">
    <!-- Switch Capacity Overview -->
    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; padding: 1.25rem 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="font-size: 0.875rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="server" style="width: 15px; height: 15px; color: var(--success);"></i>
                Switch Hardware Capacity & Port Distribution
            </h3>
            <a href="switches" class="text-primary" style="font-size: 0.75rem; text-decoration: none; display: flex; align-items: center; gap: 3px;">
                Manage Switches <i data-lucide="arrow-right" style="width: 12px;"></i>
            </a>
        </div>
        
        <div style="display: flex; align-items: center; gap: 2rem; flex-wrap: wrap;">
            <div style="flex-shrink: 0; position: relative; width: 110px; height: 110px;">
                <canvas id="switchCapacityChart"></canvas>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                    <?php $pct = $total_switch_ports > 0 ? round(($active_switch_ports / $total_switch_ports) * 100) : 0; ?>
                    <span style="display: block; font-size: 1.35rem; font-weight: 700;"><?php echo $pct; ?>%</span>
                </div>
            </div>
            <div style="flex-grow: 1; min-width: 240px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.85rem;">
                    <div>
                        <h4 style="font-size: 1.2rem; font-weight: 700; margin: 0;"><?php echo number_format($total_switch_ports); ?></h4>
                        <p style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; margin: 2px 0 0 0;">Physical Ports</p>
                    </div>
                    <div>
                        <h4 style="font-size: 1.2rem; font-weight: 700; color: var(--success); margin: 0;"><?php echo number_format($active_switch_ports); ?></h4>
                        <p style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; margin: 2px 0 0 0;">Active / UP</p>
                    </div>
                    <div>
                        <h4 style="font-size: 1.2rem; font-weight: 700; color: var(--text); margin: 0;"><?php echo number_format(max(0, $total_switch_ports - $active_switch_ports)); ?></h4>
                        <p style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; margin: 2px 0 0 0;">Available</p>
                    </div>
                </div>
                <div style="height: 8px; background: rgba(255,255,255,0.05); border-radius: 4px; overflow: hidden; display: flex;">
                    <div style="width: <?php echo $pct; ?>%; background: var(--success); transition: width 0.3s ease;"></div>
                    <div style="flex-grow: 1; background: var(--surface-light);"></div>
                </div>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.65rem; margin-bottom: 0;">
                    Live data aggregated from <strong><?php echo $switch_count; ?></strong> managed switches via SNMP.
                </p>
            </div>
        </div>
    </div>

    <!-- Network Health Distribution Chart -->
    <div class="card chart-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="font-size: 0.875rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="pie-chart" style="width: 15px; height: 15px; color: var(--success);"></i>
                Host State Distribution
            </h3>
            <span style="font-size: 0.75rem; color: var(--text-muted);">By Protocol State</span>
        </div>
        <div style="flex-grow: 1; position: relative;"><canvas id="healthChart"></canvas></div>
    </div>
</div>

<!-- Tactical Operations & Command Center Row -->
<div class="grid-2-1 animate-up" style="margin-bottom: 2rem;">
    <!-- Recent Subnets with Utilization Progress -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.1rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="layers" style="width: 16px; height: 16px; color: var(--primary);"></i>
                Subnet Utilization & Density
            </h3>
            <a href="subnets" class="text-primary" style="font-size: 0.8rem; text-decoration: none;">View All Subnets &rarr;</a>
        </div>
        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">Subnet</th>
                        <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">Description</th>
                        <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">Utilization</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_subnets)): ?>
                        <tr>
                            <td colspan="3" style="padding: 2rem; text-align: center; color: var(--text-muted);">No subnets found. Add one to get started!</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_subnets as $subnet): ?>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 0.65rem 0.75rem; font-family: monospace;">
                                    <a href="subnet-details?id=<?php echo (int)$subnet['id']; ?>" class="text-primary" style="text-decoration: none; font-weight: 600;">
                                        <?php echo htmlspecialchars($subnet['subnet']); ?>/<?php echo (int)$subnet['mask']; ?>
                                    </a>
                                </td>
                                <td style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($subnet['description'] ?: 'No description'); ?>
                                </td>
                                <td style="padding: 0.65rem 0.75rem; vertical-align: middle;">
                                    <?php 
                                        $capacity = pow(2, (32 - (int)$subnet['mask']));
                                        if ((int)$subnet['mask'] < 31) $capacity -= 2;
                                        $percent = round(($subnet['used_ips'] / max(1, $capacity)) * 100, 1);
                                        $bar_color = 'var(--success)';
                                        if ($percent >= 90) $bar_color = 'var(--danger)';
                                        elseif ($percent >= 70) $bar_color = 'var(--warning)';
                                    ?>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex-grow: 1; height: 6px; background: rgba(255,255,255,0.05); border-radius: 3px; overflow: hidden; min-width: 60px;">
                                            <div style="width: <?php echo min(100, $percent); ?>%; height: 100%; background: <?php echo $bar_color; ?>; border-radius: 3px;"></div>
                                        </div>
                                        <span style="font-size: 0.75rem; font-weight: 600; min-width: 38px; text-align: right;"><?php echo $percent; ?>%</span>
                                    </div>
                                    <p style="font-size: 0.68rem; color: var(--text-muted); margin: 2px 0 0 0;">
                                        <?php echo (int)$subnet['used_ips']; ?> / <?php echo $capacity; ?> IPs
                                    </p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Side: NOC Quick Command Center & Recent Activity -->
    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
        <!-- Quick Action Commands -->
        <div class="card">
            <h3 style="font-size: 0.875rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="zap" style="width: 15px; height: 15px; color: var(--primary);"></i>
                NOC Quick Actions
            </h3>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem;">
                <?php if (is_admin()): ?>
                <a href="subnets" class="btn btn-primary" style="font-size: 0.8rem; padding: 0.5rem 0.75rem; justify-content: center;">
                    <i data-lucide="plus-circle" style="width: 14px;"></i> New Subnet
                </a>
                <?php endif; ?>
                <a href="switches" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 0.75rem; justify-content: center; background: var(--surface-light);">
                    <i data-lucide="server" style="width: 14px; color: var(--success);"></i> Switches
                </a>
                <a href="netwatch" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 0.75rem; justify-content: center; background: var(--surface-light);">
                    <i data-lucide="eye" style="width: 14px; color: #f59e0b;"></i> Netwatch
                </a>
                <a href="vlans" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 0.75rem; justify-content: center; background: var(--surface-light);">
                    <i data-lucide="network" style="width: 14px;"></i> VLANs
                </a>
                <a href="conflicts" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 0.75rem; justify-content: center; background: var(--surface-light);">
                    <i data-lucide="shield-alert" style="width: 14px; color: #ef4444;"></i> Conflict Center
                </a>
                <?php if (is_admin()): ?>
                <a href="settings" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 0.75rem; justify-content: center; background: var(--surface-light);">
                    <i data-lucide="settings" style="width: 14px;"></i> Settings
                </a>
                <?php else: ?>
                <a href="logs" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 0.75rem; justify-content: center; background: var(--surface-light);">
                    <i data-lucide="scroll" style="width: 14px;"></i> Audit Logs
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity Feed -->
        <div class="card" style="flex-grow: 1;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem;">
                <h3 style="font-size: 0.875rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="clock" style="width: 15px; height: 15px; color: var(--text-muted);"></i>
                    Recent Activity
                </h3>
                <a href="logs" class="text-primary" style="font-size: 0.75rem; text-decoration: none;">View All</a>
            </div>
            <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                <?php if (empty($recent_logs)): ?>
                    <p style="text-align: center; color: var(--text-muted); font-size: 0.8rem; padding: 0.75rem;">No recent activity.</p>
                <?php else: ?>
                    <?php foreach ($recent_logs as $log): ?>
                    <div style="border-left: 2px solid var(--primary); padding-left: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.72rem; font-weight: 600; color: var(--text);"><?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($log['action']))); ?></span>
                            <span style="font-size: 0.65rem; color: var(--text-muted); font-family: monospace;"><?php echo date('H:i', strtotime($log['created_at'])); ?></span>
                        </div>
                        <p style="font-size: 0.72rem; color: var(--text-muted); margin: 2px 0 0 0; line-height: 1.25;">
                            <?php echo htmlspecialchars(substr($log['details'], 0, 75)) . (strlen($log['details']) > 75 ? '...' : ''); ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Focus Table: Needs Attention & Low Info -->
<div class="card animate-up" id="needs-attention" style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="alert-circle" style="width: 16px; height: 16px; color: var(--warning);"></i>
                Needs Attention (Flagged Hosts / Incomplete Data)
            </h3>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin: 2px 0 0 0;">
                IPs with confidence score &lt; 60% or missing MAC address/hostname. Click any IP to open its Intelligence Dossier.
            </p>
        </div>
        <a href="devices" class="text-primary" style="font-size: 0.8rem; text-decoration: none;">View All Devices &rarr;</a>
    </div>
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">IP Address</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">Subnet</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">Hostname</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">MAC Address</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">Confidence</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem;">Last Seen</th>
                    <th style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-weight: 500; font-size: 0.75rem; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($needs_attention)): ?>
                    <tr>
                        <td colspan="7" style="padding: 1.5rem; text-align: center; color: var(--text-muted);">
                            <i data-lucide="check-circle" style="width: 20px; height: 20px; color: var(--success); display: inline-block; vertical-align: middle; margin-right: 6px;"></i>
                            No flagged devices. Discovery quality looks good across all subnets!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($needs_attention as $item): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 0.65rem 0.75rem; font-family: monospace;">
                                <a href="javascript:void(0)" onclick="openIpIntelligence('<?php echo htmlspecialchars($item['ip_addr']); ?>')" style="color: inherit; text-decoration: none; border-bottom: 1px dashed rgba(255,255,255,0.25); cursor: pointer; font-weight: 600;" title="Open IP Intelligence Dossier" onmouseover="this.style.color='var(--primary)';" onmouseout="this.style.color='inherit';">
                                    <?php echo htmlspecialchars($item['ip_addr']); ?>
                                </a>
                            </td>
                            <td style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.8rem;"><?php echo htmlspecialchars($item['subnet']); ?>/<?php echo (int)$item['mask']; ?></td>
                            <td style="padding: 0.65rem 0.75rem; font-size: 0.8rem;"><?php echo htmlspecialchars($item['hostname'] ?: '-'); ?></td>
                            <td style="padding: 0.65rem 0.75rem; font-family: monospace; font-size: 0.8rem;"><?php echo htmlspecialchars($item['mac_addr'] ?: '-'); ?></td>
                            <td style="padding: 0.65rem 0.75rem;">
                                <span style="font-weight: 700; font-size: 0.8rem; color: <?php echo ((int)$item['confidence_score'] < 60) ? 'var(--warning)' : 'var(--success)'; ?>;">
                                    <?php echo (int)$item['confidence_score']; ?>%
                                </span>
                            </td>
                            <td style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-size: 0.75rem;"><?php echo htmlspecialchars($item['last_seen'] ?: 'Never'); ?></td>
                            <td style="padding: 0.65rem 0.75rem; text-align: right;">
                                <button type="button" class="btn btn-secondary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem; color: var(--primary); border-color: rgba(88, 166, 255, 0.3);" onclick="openIpIntelligence('<?php echo htmlspecialchars($item['ip_addr']); ?>')">
                                    <i data-lucide="scan" style="width: 12px; height: 12px;"></i> Dossier
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
document.addEventListener('DOMContentLoaded', function() {
    const premiumOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false,
                labels: { color: '#8b949e', font: { size: 11, family: 'Inter', weight: '500' }, usePointStyle: true, padding: 15 }
            },
            tooltip: {
                backgroundColor: '#161b22',
                titleColor: '#fff',
                bodyColor: '#8b949e',
                borderColor: '#30363d',
                borderWidth: 1,
                padding: 10,
                boxPadding: 6,
                usePointStyle: true,
                cornerRadius: 4,
                titleFont: { size: 12, weight: '700', family: 'Inter' },
                bodyFont: { size: 11, family: 'Inter' }
            }
        },
        scales: {
            x: { 
                grid: { display: false }, 
                ticks: { color: '#8b949e', font: { size: 10, family: 'JetBrains Mono' }, padding: 10 } 
            },
            y: { 
                grid: { color: 'rgba(255,255,255,0.03)', drawBorder: false }, 
                ticks: { color: '#8b949e', font: { size: 10, family: 'JetBrains Mono' }, padding: 10, beginAtZero: true } 
            }
        }
    };

    const getGradient = (ctx, color) => {
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, color);
        gradient.addColorStop(1, 'rgba(88, 166, 255, 0)');
        return gradient;
    };

    // Trend Chart
    const trendEl = document.getElementById('trendChart');
    if (trendEl) {
        const trendCtx = trendEl.getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', array_map(function($t) { return "'".date('d M', strtotime($t['snapshot_date']))."'"; }, $usage_trends)); ?>],
                datasets: [{
                    label: 'Active Hosts',
                    data: [<?php echo implode(',', array_map(function($t) { return $t['total_active']; }, $usage_trends)); ?>],
                    borderColor: '#58a6ff',
                    borderWidth: 2,
                    pointBackgroundColor: '#58a6ff',
                    pointBorderColor: 'rgba(255,255,255,0.1)',
                    pointHoverRadius: 6,
                    pointRadius: 0,
                    backgroundColor: getGradient(trendCtx, 'rgba(88, 166, 255, 0.15)'),
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                ...premiumOptions,
                plugins: { ...premiumOptions.plugins, legend: { display: false } },
                scales: {
                    x: { display: true, grid: { display: false }, ticks: { color: '#8b949e', font: { family: 'JetBrains Mono', size: 10 } }, border: { display: false } },
                    y: { display: true, grid: { color: 'rgba(255,255,255,0.03)', drawBorder: false }, border: { display: false }, ticks: { color: '#8b949e', font: { family: 'JetBrains Mono', size: 10 } } }
                }
            }
        });
    }

    // Health Chart
    const healthEl = document.getElementById('healthChart');
    if (healthEl) {
        new Chart(healthEl, {
            type: 'bar',
            data: {
                labels: ['Active', 'Offline', 'Reserved', 'DHCP'],
                datasets: [{
                    data: [<?php echo $health_stats['active']??0;?>, <?php echo $health_stats['offline']??0;?>, <?php echo $health_stats['reserved']??0;?>, <?php echo $health_stats['dhcp']??0;?>],
                    backgroundColor: ['#3fb950', '#f85149', '#d29922', '#58a6ff'],
                    borderRadius: 4,
                    barThickness: 16
                }]
            },
            options: { 
                ...premiumOptions,
                scales: {
                    ...premiumOptions.scales,
                    x: { ...premiumOptions.scales.x, grid: { display: false } }
                }
            }
        });
    }

    // Radial Chart (Progress)
    const radialEl = document.getElementById('radialChart');
    if (radialEl) {
        const radialCtx = radialEl.getContext('2d');
        new Chart(radialCtx, {
            type: 'doughnut',
            data: {
                labels: ['Allocated', 'Free'],
                datasets: [{
                    data: [<?php echo $total_allocated; ?>, <?php echo max(0, $total_all - $total_allocated); ?>],
                    backgroundColor: ['#58a6ff', 'rgba(88, 166, 255, 0.05)'],
                    borderWidth: 0,
                    circumference: 180,
                    rotation: 270,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '85%',
                events: [],
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            }
        });
    }

    // Switch Capacity Radial Chart
    const switchEl = document.getElementById('switchCapacityChart');
    if (switchEl) {
        const switchCtx = switchEl.getContext('2d');
        new Chart(switchCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active Ports', 'Available Ports'],
                datasets: [{
                    data: [<?php echo $active_switch_ports; ?>, <?php echo max(0, $total_switch_ports - $active_switch_ports); ?>],
                    backgroundColor: ['#3fb950', 'rgba(63, 185, 80, 0.05)'],
                    borderWidth: 0,
                    circumference: 360,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            }
        });
    }

    // Toggle logic for details
    const toggleBtn = document.getElementById('toggle-details');
    const extraDetails = document.getElementById('extra-details');
    if (toggleBtn && extraDetails) {
        toggleBtn.addEventListener('click', () => {
            const isHidden = extraDetails.style.display === 'none';
            extraDetails.style.display = isHidden ? 'flex' : 'none';
            toggleBtn.innerHTML = isHidden ? 
                'Show less details <i data-lucide="chevron-up" style="width: 14px;"></i>' : 
                'Show more details <i data-lucide="chevron-down" style="width: 14px;"></i>';
            if (window.lucide) lucide.createIcons();
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>
