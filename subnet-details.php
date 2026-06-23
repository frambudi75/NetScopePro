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

/**
 * Relative time helper: "3 min ago", "2 hours ago", etc.
 */
function time_ago($datetime) {
    if (!$datetime) return 'Never';
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->getTimestamp() - $ago->getTimestamp();
    if ($diff < 0) return 'Just now';
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('d M Y', strtotime($datetime));
}

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

<div class="card no-print" style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 10px;">
        <h3 style="font-size: 1.125rem; display: flex; align-items: center; gap: 8px; margin: 0;">
            <i data-lucide="activity" style="width: 18px;"></i> Network Analysis
        </h3>
        <button id="analyzeBtn" class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.75rem;" onclick="analyzeNetwork(<?php echo $subnet_id; ?>)">
            <i data-lucide="play" style="width: 12px;"></i> Quick Analysis
        </button>
    </div>
    <div id="analysisSummary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div class="analysis-box" style="background: var(--surface-light); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border);">
            <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Detected Gateway</div>
            <div id="gatewayResult" style="font-weight: 600; font-size: 0.9rem; font-family: 'JetBrains Mono', monospace;">-</div>
        </div>
        <div class="analysis-box" style="background: var(--surface-light); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border);">
            <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">DNS Resolvers (Local)</div>
            <div id="dnsResult" style="font-weight: 600; font-size: 0.9rem; font-family: 'JetBrains Mono', monospace;">-</div>
        </div>
        <div class="analysis-box" style="background: var(--surface-light); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border);">
            <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; font-weight: 600;">Routing Info</div>
            <div id="routingResult" style="font-weight: 600; font-size: 0.9rem; font-family: 'JetBrains Mono', monospace;">-</div>
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
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(42px, 1fr)); gap: 8px;" id="ipGrid">
        <?php foreach ($current_ips as $ip): ?>
            <?php 
                $info = $assigned_ips[$ip] ?? null; 
                $ip_parts = explode('.', $ip);
                $last_octet = end($ip_parts);
                $cell_state = $info ? $info['state'] : 'free';
                
                $bg = 'var(--surface-light)';
                $color = 'var(--text-muted)';
                $border = 'rgba(255,255,255,0.05)';
                $tooltip = $ip;
                
                if ($info) {
                    $tooltip = $ip . " | " . strtoupper($cell_state);
                    if ($info['hostname']) $tooltip .= " | " . $info['hostname'];
                    if ($info['mac_addr']) $tooltip .= " | " . $info['mac_addr'];
                    if ($info['last_seen']) $tooltip .= " | Last: " . time_ago($info['last_seen']);
                    
                    if ($info['state'] == 'active') { 
                        $bg = ($info['conflict_detected'] ?? 0) == 1 ? 'var(--danger)' : 'var(--success)';
                        $color = 'white'; 
                    }
                    else if ($info['state'] == 'reserved') { $bg = 'var(--warning)'; $color = 'white'; }
                    else if ($info['state'] == 'dhcp') { $bg = 'var(--primary)'; $color = 'white'; }
                    else if ($info['state'] == 'offline') { $bg = 'rgba(239, 68, 68, 0.25)'; $color = 'var(--danger)'; }
                    $border = ($info['conflict_detected'] ?? 0) == 1 ? 'var(--danger)' : 'transparent';
                }
                
                // Badge "NEW" if last_seen within 30 minutes
                $is_new = false;
                if ($info && $info['last_seen']) {
                    $seen_ts = strtotime($info['last_seen']);
                    $is_new = (time() - $seen_ts) < 1800; // 30 min
                }
            ?>
            <div 
                class="grid-cell"
                data-state="<?php echo $cell_state; ?>"
                data-ip="<?php echo $ip; ?>"
                <?php if (is_admin()): ?>
                onclick="openEditModal('<?php echo $ip; ?>', '<?php echo $info['hostname'] ?? ''; ?>', '<?php echo $info['description'] ?? ''; ?>', '<?php echo $info['state'] ?? 'active'; ?>', '<?php echo $info['asset_tag'] ?? ''; ?>', '<?php echo $info['owner'] ?? ''; ?>')"
                style="aspect-ratio: 1; background: <?php echo $bg; ?>; border: 1px solid <?php echo $border; ?>; border-radius: 6px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 600; color: <?php echo $color; ?>; opacity: <?php echo $info ? '1' : '0.4'; ?>; position: relative;"
                <?php else: ?>
                style="aspect-ratio: 1; background: <?php echo $bg; ?>; border: 1px solid <?php echo $border; ?>; border-radius: 6px; cursor: default; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 600; color: <?php echo $color; ?>; opacity: <?php echo $info ? '1' : '0.4'; ?>; position: relative;"
                <?php endif; ?>
                title="<?php echo htmlspecialchars($tooltip); ?>"
            >
                <?php echo $last_octet; ?>
                <?php if ($is_new): ?>
                <span style="position: absolute; top: -2px; right: -2px; background: var(--primary); color: white; font-size: 0.4rem; padding: 0 3px; border-radius: 3px; font-weight: 700; line-height: 1.4;">NEW</span>
                <?php endif; ?>
                <?php if (($info['conflict_detected'] ?? 0) == 1): ?>
                <span style="position: absolute; top: -2px; left: -2px; background: var(--danger); color: white; font-size: 0.45rem; width: 10px; height: 10px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">!</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>


<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <h3 style="font-size: 1.125rem; display: flex; align-items: center; gap: 8px; margin: 0;">
            <i data-lucide="list" class="no-print" style="width: 18px;"></i> Detailed Allocation
            <span style="font-size: 0.7rem; padding: 2px 8px; border-radius: 6px; background: var(--badge-bg); color: var(--text-muted); font-weight: 600;" id="tableCount"><?php echo count(array_filter($assigned_ips)); ?> entries</span>
        </h3>
        <div class="no-print" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
            <select id="stateFilter" onchange="applyFilters()" style="background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 6px 12px; font-size: 0.75rem; color: var(--text); outline: none; cursor: pointer;">
                <option value="all">All States</option>
                <option value="active">Active</option>
                <option value="reserved">Reserved</option>
                <option value="offline">Offline</option>
                <option value="dhcp">DHCP</option>
                <option value="free">Free</option>
            </select>
            <input type="text" id="tableSearch" placeholder="Search IP, MAC, hostname..." oninput="applyFilters()" style="background: var(--surface-light); border: 1px solid var(--border); border-radius: 8px; padding: 6px 12px; font-size: 0.75rem; color: var(--text); outline: none; width: 220px;">
        </div>
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
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">Last Seen</th>
                    <th class="no-print" style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody id="ipTableBody">
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
                    $row_state = $info ? $info['state'] : 'free';
                    
                    // State badge colors
                    $state_bg = 'rgba(100, 116, 139, 0.1)';
                    $state_color = 'var(--text-muted)';
                    if ($info) {
                        switch ($info['state']) {
                            case 'active':  $state_bg = 'rgba(16, 185, 129, 0.1)'; $state_color = 'var(--success)'; break;
                            case 'reserved': $state_bg = 'rgba(245, 158, 11, 0.1)'; $state_color = 'var(--warning)'; break;
                            case 'dhcp':     $state_bg = 'rgba(59, 130, 246, 0.1)'; $state_color = 'var(--primary)'; break;
                            case 'offline':  $state_bg = 'rgba(239, 68, 68, 0.1)'; $state_color = 'var(--danger)'; break;
                        }
                    }
                ?>
                    <tr class="ip-row" data-state="<?php echo $row_state; ?>" data-search="<?php echo strtolower($ip . ' ' . ($info['hostname'] ?? '') . ' ' . ($info['mac_addr'] ?? '') . ' ' . ($info['vendor'] ?? '') . ' ' . ($info['asset_tag'] ?? '') . ' ' . ($info['owner'] ?? '')); ?>" style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 1rem; font-family: monospace; font-size: 0.9375rem; font-weight: 500; color: <?php echo $info ? 'var(--text)' : 'var(--text-muted)'; ?>;">
                            <?php echo $ip; ?>
                        </td>
                        <td style="padding: 1rem;">
                            <?php if ($info): ?>
                                <div style="display: flex; align-items: center; gap: 4px;">
                                    <span style="font-size: 0.7rem; padding: 4px 10px; border-radius: 6px; background: <?php echo $state_bg; ?>; color: <?php echo $state_color; ?>; text-transform: uppercase; font-weight: 700;">
                                        <?php echo $info['state']; ?>
                                    </span>
                                    <?php if (($info['conflict_detected'] ?? 0) == 1): ?>
                                    <span title="MAC Conflict Detected" style="color: var(--danger); display: flex;"><i data-lucide="alert-triangle" style="width: 14px;"></i></span>
                                    <?php endif; ?>
                                </div>
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
                                    <div style="display: flex; align-items: center; gap: 4px;">
                                        <span style="font-weight: 700; color: <?php echo $confidence_color; ?>;"><?php echo $confidence; ?>%</span>
                                        <?php if ($confidence < 40 && $confidence > 0): ?>
                                        <span title="Low confidence" style="color: var(--warning); display: flex;"><i data-lucide="alert-triangle" style="width: 12px;"></i></span>
                                        <?php endif; ?>
                                    </div>
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
                        <td style="padding: 1rem; font-size: 0.75rem; color: var(--text-muted); white-space: nowrap;">
                            <?php echo $info ? time_ago($info['last_seen']) : '-'; ?>
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
// ====== FILTER & SEARCH ======
function applyFilters() {
    const stateFilter = document.getElementById('stateFilter').value;
    const searchQuery = document.getElementById('tableSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#ipTableBody .ip-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowState = row.dataset.state;
        const searchText = row.dataset.search;
        const stateMatch = (stateFilter === 'all' || rowState === stateFilter);
        const searchMatch = !searchQuery || searchText.includes(searchQuery);

        if (stateMatch && searchMatch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    // Also filter grid cells
    const cells = document.querySelectorAll('#ipGrid .grid-cell');
    cells.forEach(cell => {
        const cellState = cell.dataset.state;
        const cellIp = cell.dataset.ip || '';
        const stateMatch = (stateFilter === 'all' || cellState === stateFilter);
        const searchMatch = !searchQuery || cellIp.includes(searchQuery);

        if (stateMatch && searchMatch) {
            cell.style.display = '';
        } else {
            cell.style.display = 'none';
        }
    });

    document.getElementById('tableCount').textContent = visibleCount + ' entries';
}

// ====== SCAN ======
async function scanSubnet(id) {
    const btn = document.getElementById('scanBtn');
    const status = document.getElementById('scanStatus');
    const statusText = document.getElementById('scanStatusText');
    btn.disabled = true;
    status.style.display = 'flex';
    
    const uiBlock = <?php echo $current_block; ?>;
    const chunksPerBlock = 4;
    let totalFound = 0;
    let totalScanned = 0;
    let discoveryMethod = 'legacy';
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
                if (data.data.discovery_method === 'masscan') {
                    discoveryMethod = 'masscan';
                    if (data.data.masscan_discovered) {
                        statusText.innerText = `⚡ Masscan discovered ${data.data.masscan_discovered} hosts in block ${i+1}...`;
                    }
                }
            } else {
                console.warn("Chunk failed:", data);
            }
        }
        
        const totalTime = ((Date.now() - scanStart) / 1000).toFixed(1);
        const methodLabel = discoveryMethod === 'masscan' ? '⚡ Masscan' : '🔍 Legacy';
        statusText.innerText = `✅ Scan Complete! ${methodLabel} — Found ${totalFound} active hosts (${totalScanned} IPs scanned in ${totalTime}s)`;
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
