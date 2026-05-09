<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/network.php';
require_once 'includes/audit.helper.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$subnet_id = $_GET['id'] ?? 0;

// Fetch subnet info
$stmt = $db->prepare("SELECT s.*, v.number as vlan_number FROM subnets s LEFT JOIN vlans v ON s.vlan_id = v.id WHERE s.id = ?");
$stmt->execute([$subnet_id]);
$subnet = $stmt->fetch();

if (!$subnet) {
    die("Subnet not found");
}

$all_vlans = $db->query("SELECT id, number, name FROM vlans ORDER BY number ASC")->fetchAll();
$all_switches = $db->query("SELECT id, name, ip_addr FROM switches ORDER BY name ASC")->fetchAll();

$page_title = 'Subnet Details: ' . $subnet['subnet'] . '/' . $subnet['mask'];

// Handle IP allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_ip']) && is_admin()) {
    $ip_addr = $_POST['ip_addr'];
    $description = $_POST['description'];
    $hostname = $_POST['hostname'];
    $state = $_POST['state'] ?? 'active';
    $asset_tag = $_POST['asset_tag'] ?? null;
    $owner = $_POST['owner'] ?? null;

    // Fetch old info for logging
    $stmt = $db->prepare("SELECT * FROM ip_addresses WHERE subnet_id = ? AND ip_addr = ?");
    $stmt->execute([$subnet_id, $ip_addr]);
    $old_info = $stmt->fetch();

    try {
        $stmt = $db->prepare("INSERT INTO ip_addresses (subnet_id, ip_addr, description, hostname, state, asset_tag, owner) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description), hostname=VALUES(hostname), state=VALUES(state), asset_tag=VALUES(asset_tag), owner=VALUES(owner)");
        $stmt->execute([$subnet_id, $ip_addr, $description, $hostname, $state, $asset_tag, $owner]);
        
        // Log the change
        $new_info = ['hostname' => $hostname, 'description' => $description, 'state' => $state, 'asset_tag' => $asset_tag, 'owner' => $owner];
        AuditLogHelper::logIpUpdate($ip_addr, $old_info, $new_info);
    } catch (Exception $e) {}
}

// Handle Subnet Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subnet_settings']) && is_admin()) {
    $scan_interval = (int)$_POST['scan_interval'];
    $description = $_POST['description'] ?? '';
    $vlan_id = (isset($_POST['vlan_id']) && $_POST['vlan_id'] !== '') ? (int)$_POST['vlan_id'] : null;
    $utilization_threshold = (isset($_POST['utilization_threshold']) && $_POST['utilization_threshold'] !== '') ? (int)$_POST['utilization_threshold'] : null;
    $parent_switch_id = (isset($_POST['parent_switch_id']) && $_POST['parent_switch_id'] !== '') ? (int)$_POST['parent_switch_id'] : null;

    try {
        $stmt = $db->prepare("UPDATE subnets SET scan_interval = ?, description = ?, vlan_id = ?, utilization_threshold = ?, parent_switch_id = ? WHERE id = ?");
        $stmt->execute([$scan_interval, $description, $vlan_id, $utilization_threshold, $parent_switch_id, $subnet_id]);
        $stmt = $db->prepare("SELECT s.*, v.number as vlan_number FROM subnets s LEFT JOIN vlans v ON s.vlan_id = v.id WHERE s.id = ?");
        $stmt->execute([$subnet_id]);
        $subnet = $stmt->fetch();
    } catch (Exception $e) {}
}


// Subnet Range & Pagination Logic
list($start_long, $end_long) = cidr_to_range($subnet['subnet'] . '/' . $subnet['mask']);
$total_hosts = ($end_long - $start_long) + 1;
$block_size = 256;
$total_blocks = ceil($total_hosts / $block_size);
$current_block = isset($_GET['block']) ? max(0, min($total_blocks - 1, (int)$_GET['block'])) : 0;

// 1. Fetch Stats for the ENTIRE subnet range (Calculated in SQL for performance & accuracy)
$stmt = $db->prepare("
    SELECT state, COUNT(*) as count 
    FROM ip_addresses 
    WHERE subnet_id = ? 
    AND INET_ATON(ip_addr) BETWEEN ? AND ? 
    GROUP BY state
");
$stmt->execute([$subnet_id, $start_long, $end_long]);
$stats = ['active' => 0, 'reserved' => 0, 'offline' => 0, 'dhcp' => 0];
$assigned_total = 0;
while ($row = $stmt->fetch()) {
    $stats[$row['state']] = (int)$row['count'];
    $assigned_total += (int)$row['count'];
}
$stats['free'] = max(0, $total_hosts - $assigned_total);

// 2. Fetch Detailed Info ONLY for the current block (Saves memory for /16 subnets)
$block_start = $start_long + ($current_block * $block_size);
$block_end = min($end_long, $block_start + $block_size - 1);

$stmt = $db->prepare("
    SELECT * FROM ip_addresses 
    WHERE subnet_id = ? 
    AND INET_ATON(ip_addr) BETWEEN ? AND ?
");
$stmt->execute([$subnet_id, $block_start, $block_end]);
$assigned_ips = [];
while ($row = $stmt->fetch()) {
    $assigned_ips[$row['ip_addr']] = $row;
}

// IPs for current display block grid
$current_ips = [];
for ($i = $block_start; $i <= $block_end; $i++) {
    $current_ips[] = long2ip($i);
}

$total_displayed = count($current_ips);
$used_percentage = round((($total_hosts - $stats['free']) / $total_hosts) * 100, 1);

include 'includes/header.php';
?>

<div style="margin-bottom: 2.5rem;">
    <a href="subnets" class="text-muted back-link" style="font-size: 0.875rem; display: flex; align-items: center; gap: 5px; margin-bottom: 1.5rem;">
        <i data-lucide="arrow-left" style="width: 14px;"></i> Back to Subnets
    </a>
    <div class="page-header">
        <div>
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 0.5rem; flex-wrap: wrap;">
                <h1 style="font-size: 2rem; font-weight: 700; margin: 0;"><?php echo $subnet['subnet']; ?>/<?php echo $subnet['mask']; ?></h1>
                <?php if ($subnet['vlan_number']): ?>
                    <span style="background: rgba(59, 130, 246, 0.1); color: var(--primary); padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; border: 1px solid rgba(59, 130, 246, 0.2);">
                        VLAN <?php echo $subnet['vlan_number']; ?>
                    </span>
                <?php endif; ?>
            </div>
            <p style="color: var(--text-muted); font-size: 1rem; margin-bottom: 1rem;"><?php echo $subnet['description'] ?: 'No description provided'; ?></p>
            <div class="no-print" style="display: flex; gap: 1.5rem; flex-wrap: wrap; font-size: 0.75rem; color: var(--text-muted);">
                <span><i data-lucide="clock" style="width: 12px; vertical-align: middle;"></i> Auto-Scan: <?php echo ($subnet['scan_interval'] ?? 0) > 0 ? "Every {$subnet['scan_interval']} min" : "Disabled"; ?></span>
                <?php if ($subnet['last_scan'] ?? null): ?>
                    <span><i data-lucide="calendar" style="width: 12px; vertical-align: middle;"></i> Last: <?php echo date('d M Y H:i', strtotime($subnet['last_scan'])); ?></span>
                <?php endif; ?>
                <?php if (is_admin()): ?>
                    <a href="#" onclick="document.getElementById('settingsModal').style.display='flex'" style="color: var(--primary); text-decoration: none; font-weight: 600;">Edit Settings</a>
                <?php endif; ?>
            </div>
        </div>
        
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <a href="export?type=subnet_details&id=<?php echo $subnet_id; ?>" class="btn btn-secondary" style="font-size: 0.8125rem;" title="Export IP list to CSV">
                <i data-lucide="download" style="width: 14px;"></i> Export
            </a>
            <button class="btn btn-secondary" style="font-size: 0.8125rem;" onclick="window.print()" title="Generate PDF Report">
                <i data-lucide="printer" style="width: 14px;"></i> Print
            </button>
            <?php if (is_admin()): ?>
            <button id="scanBtn" class="btn btn-primary" style="font-size: 0.8125rem;" onclick="scanSubnet(<?php echo $subnet_id; ?>)">
                <i data-lucide="search" style="width: 14px;"></i> Scan Subnet
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Usage Bar -->
<div class="no-print" style="margin-bottom: 3rem;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 0.75rem; font-size: 0.875rem; flex-wrap: wrap; gap: 10px;">
        <span>Scale Utilization (<?php echo ($total_hosts - $stats['free']); ?> / <?php echo $total_hosts; ?>)</span>
        <span class="text-muted">Available: <?php echo $stats['free']; ?> IPs</span>
    </div>
    <div style="height: 12px; background: var(--surface-light); border-radius: 6px; overflow: hidden; display: flex;">
        <div style="width: <?php echo ($stats['active'] / $total_hosts) * 100; ?>%; background: var(--success);" title="Active"></div>
        <div style="width: <?php echo ($stats['reserved'] / $total_hosts) * 100; ?>%; background: var(--warning);" title="Reserved"></div>
        <div style="width: <?php echo ($stats['dhcp'] / $total_hosts) * 100; ?>%; background: var(--primary);" title="DHCP"></div>
    </div>
    <div style="display: flex; gap: 1rem; margin-top: 1rem; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: var(--text-muted);">
            <div style="width: 10px; height: 10px; border-radius: 2px; background: var(--success);"></div> Active
        </div>
        <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: var(--text-muted);">
            <div style="width: 10px; height: 10px; border-radius: 2px; background: var(--warning);"></div> Reserved
        </div>
        <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: var(--text-muted);">
            <div style="width: 10px; height: 10px; border-radius: 2px; background: var(--primary);"></div> DHCP
        </div>
        <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: var(--text-muted);">
            <div style="width: 10px; height: 10px; border-radius: 2px; background: var(--surface-light);"></div> Free
        </div>
    </div>
</div>

<div class="card no-print" style="margin-bottom: 2rem; border-left: 4px solid var(--primary);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 10px;">
        <h3 style="font-size: 1.125rem; display: flex; align-items: center; gap: 8px; margin: 0;">
            <i data-lucide="activity" style="width: 18px;"></i> Network Analysis
        </h3>
        <button id="analyzeBtn" class="btn" style="padding: 6px 12px; font-size: 0.75rem; background: rgba(59, 130, 246, 0.1); color: var(--primary);" onclick="analyzeNetwork(<?php echo $subnet_id; ?>)">
            <i data-lucide="play" style="width: 12px;"></i> Quick Analysis
        </button>
    </div>
    <div id="analysisSummary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div class="analysis-box" style="background: rgba(255,255,255,0.03); padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem;">Detected Gateway</div>
            <div id="gatewayResult" style="font-weight: 600; font-size: 0.9rem;">-</div>
        </div>
        <div class="analysis-box" style="background: rgba(255,255,255,0.03); padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem;">DNS Resolvers (Local)</div>
            <div id="dnsResult" style="font-weight: 600; font-size: 0.9rem;">-</div>
        </div>
        <div class="analysis-box" style="background: rgba(255,255,255,0.03); padding: 1rem; border-radius: 8px;">
            <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem;">Routing Info</div>
            <div id="routingResult" style="font-weight: 600; font-size: 0.9rem;">-</div>
        </div>
    </div>
</div>

<div id="scanStatus" style="display: none; padding: 1.25rem; background: rgba(59, 130, 246, 0.05); border: 1px dashed var(--primary); color: var(--text); border-radius: 12px; margin-bottom: 2rem; align-items: center; gap: 15px;">
    <div class="spinner" style="width: 20px; height: 20px; border: 3px solid var(--primary); border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
    <span id="scanStatusText" style="font-weight: 500;">Scanning subnet IPs...</span>
</div>

<!-- Visual Grid -->
<div class="card no-print" style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <h3 style="font-size: 1.125rem; display: flex; align-items: center; gap: 8px; margin: 0;">
            <i data-lucide="grid-3x3" style="width: 18px;"></i> Visual IP Grid
        </h3>
        
        <?php if ($total_blocks > 1): ?>
            <div style="display: flex; align-items: center; gap: 10px; background: rgba(59, 130, 246, 0.05); padding: 5px 12px; border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.1); flex-wrap: wrap;">
                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Viewing Block:</span>
                <select onchange="location.href='?id=<?php echo $subnet_id; ?>&block=' + this.value" style="background: transparent; border: none; color: var(--primary); font-weight: 700; font-size: 0.875rem; outline: none; cursor: pointer;">
                    <?php for ($b = 0; $b < $total_blocks; $b++): ?>
                        <?php 
                            $b_start = long2ip($start_long + ($b * $block_size));
                            $b_end = long2ip(min($end_long, $start_long + ($b + 1) * $block_size - 1));
                            $b_parts = explode('.', $b_start);
                            $display_range = $b_parts[0] . '.' . $b_parts[1] . '.' . $b_parts[2] . '.x';
                        ?>
                        <option value="<?php echo $b; ?>" <?php echo $current_block == $b ? 'selected' : ''; ?>>
                            <?php echo $display_range; ?> (<?php echo $b_start; ?>)
                        </option>
                    <?php endfor; ?>
                </select>
                <div style="display: flex; gap: 5px; margin-left: 5px; border-left: 1px solid rgba(59, 130, 246, 0.2); padding-left: 10px;">
                    <button onclick="location.href='?id=<?php echo $subnet_id; ?>&block=<?php echo max(0, $current_block - 1); ?>'" class="btn" style="padding: 2px; background: transparent; <?php echo $current_block == 0 ? 'opacity: 0.3; pointer-events: none;' : ''; ?>">
                        <i data-lucide="chevron-left" style="width: 16px;"></i>
                    </button>
                    <button onclick="location.href='?id=<?php echo $subnet_id; ?>&block=<?php echo min($total_blocks - 1, $current_block + 1); ?>'" class="btn" style="padding: 2px; background: transparent; <?php echo $current_block >= $total_blocks - 1 ? 'opacity: 0.3; pointer-events: none;' : ''; ?>">
                        <i data-lucide="chevron-right" style="width: 16px;"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(42px, 1fr)); gap: 8px;">
        <?php foreach ($current_ips as $ip): ?>
            <?php 
                $info = $assigned_ips[$ip] ?? null; 
                $ip_parts = explode('.', $ip);
                $last_octet = end($ip_parts);
                
                $bg = 'var(--surface-light)';
                $color = 'var(--text-muted)';
                $border = 'rgba(255,255,255,0.05)';
                
                if ($info) {
                    if ($info['state'] == 'active') { 
                        $bg = ($info['conflict_detected'] ?? 0) == 1 ? 'var(--danger)' : 'var(--success)';
                        $color = 'white'; 
                    }
                    else if ($info['state'] == 'reserved') { $bg = 'var(--warning)'; $color = 'white'; }
                    else if ($info['state'] == 'dhcp') { $bg = 'var(--primary)'; $color = 'white'; }
                    $border = ($info['conflict_detected'] ?? 0) == 1 ? 'var(--danger)' : 'transparent';
                }
            ?>
            <div 
                <?php if (is_admin()): ?>
                onclick="openEditModal('<?php echo $ip; ?>', '<?php echo $info['hostname'] ?? ''; ?>', '<?php echo $info['description'] ?? ''; ?>', '<?php echo $info['state'] ?? 'active'; ?>', '<?php echo $info['asset_tag'] ?? ''; ?>', '<?php echo $info['owner'] ?? ''; ?>')"
                style="aspect-ratio: 1; background: <?php echo $bg; ?>; border: 1px solid <?php echo $border; ?>; border-radius: 6px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 600; color: <?php echo $color; ?>; opacity: <?php echo $info ? '1' : '0.4'; ?>;"
                <?php else: ?>
                style="aspect-ratio: 1; background: <?php echo $bg; ?>; border: 1px solid <?php echo $border; ?>; border-radius: 6px; cursor: default; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 600; color: <?php echo $color; ?>; opacity: <?php echo $info ? '1' : '0.4'; ?>;"
                <?php endif; ?>
                title="<?php echo $ip; ?>"
            >
                <?php echo $last_octet; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>


<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.125rem; display: flex; align-items: center; gap: 8px; margin: 0;">
            <i data-lucide="list" class="no-print" style="width: 18px;"></i> Detailed Allocation
        </h3>
    </div>
    
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">IP Address</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">Status</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">Hostname</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">Asset / Owner</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">Confidence</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">Switch Port</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">MAC / Vendor</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">OS / Device</th>
                    <th class="no-print" style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $port_map = $db->query("SELECT m.mac_addr, m.port_name, s.name as switch_name FROM switch_port_map m JOIN switches s ON m.switch_id = s.id")->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);
                
                foreach ($current_ips as $ip): 
                    $info = $assigned_ips[$ip] ?? null;
                    $mac = $info['mac_addr'] ?? null;
                    $port_info = ($mac && isset($port_map[$mac])) ? $port_map[$mac][0] : null;
                    $confidence = $info ? (int)($info['confidence_score'] ?? 0) : 0;
                    $source_labels = [];
                    if ($info && !empty($info['data_sources'])) {
                        foreach (explode(',', $info['data_sources']) as $src) {
                            $src = strtoupper(trim($src));
                            if ($src !== '') $source_labels[] = $src;
                        }
                    }
                    $confidence_color = $confidence >= 80 ? 'var(--success)' : ($confidence >= 60 ? 'var(--primary)' : ($confidence >= 40 ? 'var(--warning)' : 'var(--text-muted)'));
                ?>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 1rem; font-family: monospace; font-size: 0.9375rem; font-weight: 500; color: <?php echo $info ? 'var(--text)' : 'var(--text-muted)'; ?>;">
                            <?php echo $ip; ?>
                        </td>
                        <td style="padding: 1rem;">
                            <?php if ($info): ?>
                                <span style="font-size: 0.7rem; padding: 4px 10px; border-radius: 6px; background: <?php echo $info['state'] == 'active' ? 'rgba(16, 185, 129, 0.1)' : ($info['state'] == 'reserved' ? 'rgba(245, 158, 11, 0.1)' : 'rgba(100, 116, 139, 0.1)'); ?>; color: <?php echo $info['state'] == 'active' ? 'var(--success)' : ($info['state'] == 'reserved' ? 'var(--warning)' : 'var(--text-muted)'); ?>; text-transform: uppercase; font-weight: 700;">
                                    <?php echo $info['state']; ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size: 0.7rem; color: var(--text-muted); opacity: 0.4;">FREE</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 1rem; font-size: 0.875rem;"><?php echo htmlspecialchars($info['hostname'] ?? '') ?: '<span style="opacity: 0.3">-</span>'; ?></td>
                        <td style="padding: 1rem; font-size: 0.875rem;">
                            <?php if ($info): ?>
                                <div style="display: flex; flex-direction: column;">
                                    <div style="font-weight: 600; color: var(--primary);"><?php echo htmlspecialchars($info['asset_tag'] ?? '-'); ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($info['owner'] ?? ''); ?></div>
                                </div>
                            <?php else: ?>
                                <span style="opacity: 0.3">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 1rem; font-size: 0.875rem;">
                            <?php if ($info): ?>
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <span style="font-weight: 700; color: <?php echo $confidence_color; ?>;"><?php echo $confidence; ?>%</span>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <?php foreach ($source_labels as $label): ?>
                                            <span style="font-size: 0.6rem; padding: 2px 6px; border-radius: 999px; border: 1px solid rgba(148, 163, 184, 0.3); color: var(--text-muted);"><?php echo htmlspecialchars($label); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span style="opacity: 0.3">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 1rem; font-size: 0.875rem;">
                            <?php if ($port_info): ?>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($port_info['port_name']); ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-muted);"><?php echo htmlspecialchars($port_info['switch_name']); ?></div>
                            <?php else: ?>
                                <span style="opacity: 0.3">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 1rem; font-size: 0.8125rem;">
                            <div style="font-family: monospace;"><?php echo $info['mac_addr'] ?? ''; ?></div>
                            <div style="font-size: 0.7rem; color: var(--primary);"><?php echo $info['vendor'] ?? ''; ?></div>
                        </td>
                        <td style="padding: 1rem;">
                            <?php if (!empty($info['os'])): ?>
                                <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem;">
                                    <i data-lucide="monitor" style="width: 12px;"></i>
                                    <span><?php echo htmlspecialchars($info['os']); ?></span>
                                </div>
                            <?php else: ?>
                                <span style="opacity: 0.3">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="no-print" style="padding: 1rem; text-align: right;">
                            <?php if (is_admin()): ?>
                            <button class="btn" style="padding: 6px; background: var(--surface-light);" onclick="openEditModal('<?php echo $ip; ?>', '<?php echo $info['hostname'] ?? ''; ?>', '<?php echo $info['description'] ?? ''; ?>', '<?php echo $info['state'] ?? 'active'; ?>', '<?php echo $info['asset_tag'] ?? ''; ?>', '<?php echo $info['owner'] ?? ''; ?>')">
                                <i data-lucide="edit-3" style="width: 14px;"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- Settings Modal -->
<div id="settingsModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 450px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Subnet Settings</h3>
            <button onclick="document.getElementById('settingsModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="update_subnet_settings" value="1">
            <div class="input-group">
                <label>Description</label>
                <input type="text" name="description" class="input-control" value="<?php echo htmlspecialchars($subnet['description'] ?? ''); ?>">
            </div>
            <div class="input-group">
                <label>VLAN (optional)</label>
                <select name="vlan_id" class="input-control">
                    <option value="" <?php echo empty($subnet['vlan_id']) ? 'selected' : ''; ?>>No VLAN</option>
                    <?php foreach ($all_vlans as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>" <?php echo ((int)$subnet['vlan_id'] === (int)$v['id']) ? 'selected' : ''; ?>>
                            VLAN <?php echo $v['number']; ?> — <?php echo $v['name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Auto-Scan Interval</label>
                <select name="scan_interval" class="input-control">
                    <option value="0" <?php echo ($subnet['scan_interval'] == 0) ? 'selected' : ''; ?>>Manual Only</option>
                    <option value="60" <?php echo ($subnet['scan_interval'] == 60) ? 'selected' : ''; ?>>Every 1 Hour</option>
                    <option value="1440" <?php echo ($subnet['scan_interval'] == 1440) ? 'selected' : ''; ?>>Every 24 Hours</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Save Settings</button>
        </form>
    </div>
</div>

<!-- Assign Modal -->
<div id="editModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 450px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 id="modalTitle">Manage IP</h3>
            <button onclick="document.getElementById('editModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="assign_ip" value="1">
            <input type="hidden" name="ip_addr" id="modalIp">
            <div class="input-group">
                <label>Hostname</label>
                <input type="text" name="hostname" id="modalHostname" class="input-control">
            </div>
            <div class="input-group">
                <label>Description</label>
                <input type="text" name="description" id="modalDescription" class="input-control">
            </div>
            <div class="input-group">
                <label>Status</label>
                <select name="state" id="modalState" class="input-control">
                    <option value="active">Active</option>
                    <option value="reserved">Reserved</option>
                    <option value="offline">Offline</option>
                    <option value="dhcp">DHCP Pool</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="input-group"><label>Asset Tag</label><input type="text" name="asset_tag" id="modalAssetTag" class="input-control"></div>
                <div class="input-group"><label>Owner</label><input type="text" name="owner" id="modalOwner" class="input-control"></div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Save Changes</button>
        </form>
    </div>
</div>

<script>
async function scanSubnet(id) {
    const btn = document.getElementById('scanBtn');
    const status = document.getElementById('scanStatus');
    const statusText = document.getElementById('scanStatusText');
    btn.disabled = true;
    status.style.display = 'flex';
    
    const uiBlock = <?php echo $current_block; ?>;
    const chunksPerBlock = 4; // 256 / 64 = 4 chunks (optimized: larger chunks, fewer HTTP calls)
    let totalFound = 0;
    let totalScanned = 0;
    const scanStart = Date.now();

    try {
        for (let i = 0; i < chunksPerBlock; i++) {
            const apiBlock = (uiBlock * chunksPerBlock) + i;
            const elapsed = ((Date.now() - scanStart) / 1000).toFixed(1);
            statusText.innerText = `⚡ Scanning block ${i+1}/${chunksPerBlock}... (Found: ${totalFound} | Scanned: ${totalScanned} | ${elapsed}s)`;
            
            const response = await fetch(`api/scan?id=${id}&block=${apiBlock}`);
            if (!response.ok) throw new Error("Server responded with error");
            
            const data = await response.json();
            if (data.success) {
                totalFound += data.data.found;
                totalScanned += data.data.scanned;
            } else {
                console.warn("Chunk failed:", data);
            }
        }
        
        const totalTime = ((Date.now() - scanStart) / 1000).toFixed(1);
        statusText.innerText = `✅ Scan Complete! Found ${totalFound} active hosts (${totalScanned} IPs scanned in ${totalTime}s)`;
        setTimeout(() => location.reload(), 2000);
    } catch (err) {
        console.error(err);
        const totalTime = ((Date.now() - scanStart) / 1000).toFixed(1);
        statusText.innerText = `❌ Error during scan after ${totalTime}s. (Timeout or Server Error)`;
        btn.disabled = false;
    }
}

async function analyzeNetwork(id) {
    const btn = document.getElementById('analyzeBtn');
    btn.disabled = true;
    try {
        const response = await fetch(`api/analyze?id=${id}`);
        const data = await response.json();
        if (data.success) {
            document.getElementById('gatewayResult').innerText = data.data.gateways.map(g => g.ip).join(', ') || 'Not detected';
            document.getElementById('dnsResult').innerText = data.data.dns_resolvers.map(d => d.ip).join(', ') || 'None found';
            document.getElementById('routingResult').innerText = data.data.routing.local_interface;
        }
    } catch (err) { console.error(err); } finally { btn.disabled = false; }
}

function openEditModal(ip, hostname, desc, state, asset, owner) {
    document.getElementById('modalTitle').innerText = 'Manage IP: ' + ip;
    document.getElementById('modalIp').value = ip;
    document.getElementById('modalHostname').value = hostname || '';
    document.getElementById('modalDescription').value = desc || '';
    document.getElementById('modalState').value = state || 'active';
    document.getElementById('modalAssetTag').value = asset || '';
    document.getElementById('modalOwner').value = owner || '';
    document.getElementById('editModal').style.display = 'flex';
}
</script>

<?php include 'includes/footer.php'; ?>
