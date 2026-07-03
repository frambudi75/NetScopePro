<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$page_title = 'Network Reports';
include 'includes/header.php';

// 1. Fetch Global Recap
$total_subnets = $db->query("SELECT COUNT(*) FROM subnets")->fetchColumn() ?: 0;
$total_ips = $db->query("SELECT COUNT(*) FROM ip_addresses")->fetchColumn() ?: 0;
$active_ips = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state = 'active'")->fetchColumn() ?: 0;
$reserved_ips = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state = 'reserved'")->fetchColumn() ?: 0;
$offline_ips = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state = 'offline'")->fetchColumn() ?: 0;
$dhcp_ips = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state = 'dhcp'")->fetchColumn() ?: 0;

// 2. Fetch Usage Trend (Last 30 snapshots)
$history = $db->query("SELECT snapshot_date, total_active FROM stats_history ORDER BY snapshot_date ASC LIMIT 30")->fetchAll();

// 3. Fetch Subnet Breakdown
$subnet_list = $db->query("
    SELECT s.id, s.subnet, s.mask, s.description, 
           COUNT(ip.id) as used_ips,
           v.number as vlan_num
    FROM subnets s
    LEFT JOIN ip_addresses ip ON ip.subnet_id = s.id AND ip.state = 'active'
    LEFT JOIN vlans v ON s.vlan_id = v.id
    GROUP BY s.id
    ORDER BY (COUNT(ip.id) * 1.0 / POW(2, (32 - s.mask))) DESC
")->fetchAll();
?>

<div class="page-header" style="margin-bottom: 2rem;">
    <div>
        <h1 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem;">Network Analytics & Reports</h1>
        <p class="text-muted">Comprehensive overview of IP allocation, consumption trends, and subnet health.</p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <button onclick="window.print()" class="btn btn-secondary">
            <i data-lucide="printer"></i> Print Report
        </button>
        <a href="export?type=all" class="btn btn-primary">
            <i data-lucide="download"></i> Export CSV
        </a>
    </div>
</div>

<!-- Stats Recap -->
<div class="grid-stats" style="margin-bottom: 2rem;">
    <div class="stat-card">
        <span class="stat-label">Allocation Rate</span>
        <span class="stat-value">
            <?php 
                $total_capacity = 0;
                foreach($subnet_list as $s) {
                    $cap = pow(2, (32 - (int)$s['mask']));
                    if ((int)$s['mask'] < 31) $cap -= 2;
                    $total_capacity += max(1, $cap);
                }
                $alloc_pct = round(($active_ips / max(1, $total_capacity)) * 100, 1);
                echo $alloc_pct; 
            ?>%
        </span>
        <span class="stat-meta">of total discoverable capacity</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Active Hosts</span>
        <span class="stat-value"><?php echo $active_ips; ?></span>
        <span class="stat-meta">Live devices in last scan</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Network Depth</span>
        <span class="stat-value"><?php echo $total_subnets; ?></span>
        <span class="stat-meta">Managed subnets & segments</span>
    </div>
</div>

<div class="grid-2-1" style="margin-bottom: 2rem;">
    <!-- Historical Trend -->
    <div class="card" style="min-height: 400px; display: flex; flex-direction: column;">
        <h3 style="font-size: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="trending-up" style="color: var(--primary);"></i> Consumption Trend (30 Days)
        </h3>
        <div style="flex-grow: 1; position: relative;"><canvas id="historyChart"></canvas></div>
    </div>

    <!-- State Distribution -->
    <div class="card" style="min-height: 400px; display: flex; flex-direction: column;">
        <h3 style="font-size: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="pie-chart" style="color: var(--success);"></i> State Distribution
        </h3>
        <div style="flex-grow: 1; position: relative;"><canvas id="stateChart"></canvas></div>
    </div>
</div>

<!-- Detailed Subnet Density -->
<div class="card">
    <h3 style="font-size: 1rem; margin-bottom: 1.5rem;">Subnet Utilization Breakdown</h3>
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border); text-align: left;">
                    <th style="padding: 1rem; color: var(--text-muted); font-weight: 500;">Subnet</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-weight: 500;">VLAN</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-weight: 500;">Description</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-weight: 500;">Usage</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-weight: 500; text-align: right;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($subnet_list as $s): ?>
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 1rem; font-family: monospace;">
                        <a href="subnet-details?id=<?php echo $s['id']; ?>" class="text-primary"><?php echo $s['subnet']; ?>/<?php echo $s['mask']; ?></a>
                    </td>
                    <td style="padding: 1rem;">
                        <span class="badge-pill"><?php echo $s['vlan_num'] ? 'VLAN '.$s['vlan_num'] : '-'; ?></span>
                    </td>
                    <td style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">
                        <?php echo htmlspecialchars($s['description'] ?: 'Unlabeled'); ?>
                    </td>
                    <td style="padding: 1rem; width: 250px;">
                        <?php 
                            $cap = pow(2, (32 - (int)$s['mask']));
                            if ((int)$s['mask'] < 31) $cap -= 2;
                            $pct = round(($s['used_ips'] / max(1, $cap)) * 100, 1);
                            $color = $pct > 90 ? 'var(--danger)' : ($pct > 70 ? 'var(--warning)' : 'var(--success)');
                        ?>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="flex-grow: 1; height: 6px; background: rgba(0,0,0,0.1); border-radius: 3px; overflow: hidden;">
                                <div style="width: <?php echo min(100, $pct); ?>%; height: 100%; background: <?php echo $color; ?>;"></div>
                            </div>
                            <span style="font-size: 0.75rem; font-weight: 600; min-width: 35px;"><?php echo $pct; ?>%</span>
                        </div>
                    </td>
                    <td style="padding: 1rem; text-align: right;">
                        <?php if ($pct > 95): ?>
                            <span style="color: var(--danger); font-size: 0.75rem; font-weight: 700;"><i data-lucide="alert-octagon" style="width:12px; vertical-align:middle;"></i> CRITICAL</span>
                        <?php elseif ($pct > 80): ?>
                            <span style="color: var(--warning); font-size: 0.75rem; font-weight: 700;">WARNING</span>
                        <?php else: ?>
                            <span style="color: var(--success); font-size: 0.75rem; font-weight: 700;">HEALTHY</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
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
        const gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, color);
        gradient.addColorStop(1, 'rgba(63, 185, 80, 0)');
        return gradient;
    };

    // 1. History Chart
    const historyCtx = document.getElementById('historyChart').getContext('2d');
    new Chart(historyCtx, {
        type: 'line',
        data: {
            labels: [<?php echo implode(',', array_map(function($h) { return "'".date('d M', strtotime($h['snapshot_date']))."'"; }, $history)); ?>],
            datasets: [
                {
                    label: 'Active Hosts',
                    data: [<?php echo implode(',', array_map(function($h) { return $h['total_active']; }, $history)); ?>],
                    borderColor: '#3fb950',
                    borderWidth: 2,
                    backgroundColor: getGradient(historyCtx, 'rgba(63, 185, 80, 0.15)'),
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 6
                }
            ]
        },
        options: premiumOptions
    });

    // 2. State Chart
    new Chart(document.getElementById('stateChart'), {
        type: 'doughnut',
        data: {
            labels: ['Active', 'Reserved', 'Offline', 'DHCP'],
            datasets: [{
                data: [<?php echo "$active_ips, $reserved_ips, $offline_ips, $dhcp_ips"; ?>],
                backgroundColor: ['#3fb950', '#d29922', '#30363d', '#58a6ff'],
                borderWidth: 2,
                borderColor: '#161b22',
                borderRadius: 4,
                spacing: 2
            }]
        },
        options: {
            ...premiumOptions,
            cutout: '75%',
            scales: { x: { display: false }, y: { display: false } },
            plugins: {
                ...premiumOptions.plugins,
                legend: { display: true, position: 'bottom', labels: { ...premiumOptions.plugins.legend.labels, padding: 25 } }
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
