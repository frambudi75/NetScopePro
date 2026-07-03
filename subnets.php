<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$page_title = 'Subnets';

$vlans_list = $db->query("SELECT id, number, name FROM vlans ORDER BY number ASC")->fetchAll();

// Handle Add Subnet
$message = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'added') {
    $message = 'Subnet added successfully!';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subnet']) && is_admin()) {
    $subnet = $_POST['subnet'] ?? '';
    $mask = $_POST['mask'] ?? '';
    $description = $_POST['description'] ?? '';
    $section_id = $_POST['section_id'] ?? 1;
    $vlan_id = (isset($_POST['vlan_id']) && $_POST['vlan_id'] !== '') ? (int)$_POST['vlan_id'] : null;
    $scan_interval = (int)($_POST['scan_interval'] ?? 0);

    try {
        $stmt = $db->prepare("INSERT INTO subnets (subnet, mask, description, section_id, vlan_id, scan_interval) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$subnet, $mask, $description, $section_id, $vlan_id, $scan_interval]);
        $message = 'Subnet added successfully!';
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

// Handle Edit Subnet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_subnet']) && is_admin()) {
    $sid = (int)$_POST['subnet_id'];
    $subnet = $_POST['subnet'] ?? '';
    $mask = $_POST['mask'] ?? '';
    $description = $_POST['description'] ?? '';
    $vlan_id = (isset($_POST['vlan_id']) && $_POST['vlan_id'] !== '') ? (int)$_POST['vlan_id'] : null;
    $scan_interval = (int)($_POST['scan_interval'] ?? 0);

    try {
        $stmt = $db->prepare("UPDATE subnets SET subnet = ?, mask = ?, description = ?, vlan_id = ?, scan_interval = ? WHERE id = ?");
        $stmt->execute([$subnet, $mask, $description, $vlan_id, $scan_interval, $sid]);
        $message = 'Subnet updated successfully!';
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

// Handle Delete Subnet
if (isset($_GET['delete']) && is_admin()) {
    $sid = (int)$_GET['delete'];
    try {
        $db->beginTransaction();
        // Delete associated IPs first
        $stmt = $db->prepare("DELETE FROM ip_addresses WHERE subnet_id = ?");
        $stmt->execute([$sid]);
        // Delete subnet
        $stmt = $db->prepare("DELETE FROM subnets WHERE id = ?");
        $stmt->execute([$sid]);
        $db->commit();
        header('Location: subnets?msg=deleted');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Error deleting subnet: ' . $e->getMessage();
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $message = 'Subnet deleted successfully!';
}

// Fetch subnets
// Fetch subnets with usage count
$subnets = $db->query("
    SELECT s.*, v.number as vlan_number, COUNT(ip.id) as used_ips 
    FROM subnets s 
    LEFT JOIN vlans v ON s.vlan_id = v.id 
    LEFT JOIN ip_addresses ip ON ip.subnet_id = s.id 
    GROUP BY s.id 
    ORDER BY s.subnet ASC
")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1 style="font-size: 1.5rem;">Subnet Management</h1>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="export?type=subnets" class="btn btn-secondary" style="font-size: 0.875rem;">
            <i data-lucide="download" style="width: 14px;"></i> Export CSV
        </a>
        <button class="btn btn-secondary" style="font-size: 0.875rem;" onclick="window.print()">
            <i data-lucide="printer" style="width: 14px;"></i> Print PDF
        </button>
        <?php if (is_admin()): ?>
        <button class="btn btn-primary" style="font-size: 0.875rem;" onclick="document.getElementById('addModal').style.display='flex'">
            <i data-lucide="plus" style="width: 14px;"></i> Add Subnet
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); border-radius: 8px; margin-bottom: 1.5rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th style="padding: 1rem; color: var(--text-muted);">Subnet</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Description</th>
                    <th style="padding: 1rem; color: var(--text-muted);">VLAN</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Usage</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subnets)): ?>
                    <tr>
                        <td colspan="4" style="padding: 2rem; text-align: center; color: var(--text-muted);">No subnets configured yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subnets as $s): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 1rem; font-weight: 500;"><?php echo $s['subnet']; ?>/<?php echo $s['mask']; ?></td>
                            <td style="padding: 1rem; color: var(--text-muted);"><?php echo $s['description']; ?></td>
                            <td style="padding: 1rem;">
                                <?php echo $s['vlan_number'] ? '<span class="text-primary">VLAN '.$s['vlan_number'].'</span>' : '-'; ?>
                            </td>
                            <td style="padding: 1rem; min-width: 140px;">
                                <?php 
                                    $capacity = pow(2, (32 - (int)$s['mask']));
                                    if ((int)$s['mask'] < 31) $capacity -= 2;
                                    $percent = round(($s['used_ips'] / max(1, $capacity)) * 100, 1);
                                    $bar_color = 'var(--success)';
                                    if ($percent >= 90) $bar_color = 'var(--danger)';
                                    elseif ($percent >= 70) $bar_color = 'var(--warning)';
                                ?>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex-grow: 1; height: 6px; background: rgba(0,0,0,0.05); border-radius: 3px; overflow: hidden;">
                                        <div style="width: <?php echo min(100, $percent); ?>%; height: 100%; background: <?php echo $bar_color; ?>; border-radius: 3px;"></div>
                                    </div>
                                    <span style="font-size: 0.7rem; font-weight: 600;"><?php echo $percent; ?>%</span>
                                </div>
                                <p style="font-size: 0.6rem; color: var(--text-muted); margin-top: 4px;"><?php echo (int)$s['used_ips']; ?> / <?php echo $capacity; ?> IPs</p>
                            </td>
                            <td style="padding: 1rem; display: flex; gap: 0.5rem; align-items: center;">
                                <a href="subnet-details?id=<?php echo $s['id']; ?>" class="btn" style="padding: 6px; background: rgba(59, 130, 246, 0.1); color: var(--primary);" title="View Details">
                                    <i data-lucide="external-link" style="width: 16px;"></i>
                                </a>
                                <?php if (is_admin()): ?>
                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($s)); ?>)" class="btn" style="padding: 6px; background: rgba(16, 185, 129, 0.1); color: var(--success);" title="Edit Subnet">
                                    <i data-lucide="edit" style="width: 16px;"></i>
                                </button>
                                <a href="?delete=<?php echo $s['id']; ?>" class="btn" style="padding: 6px; background: rgba(239, 68, 68, 0.1); color: var(--danger);" onclick="return confirm('Are you sure you want to delete this subnet and ALL its IP records?')" title="Delete">
                                    <i data-lucide="trash-2" style="width: 16px;"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Simple Add Modal (Hidden by default) -->
<div id="addModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add New Subnet</h3>
            <button onclick="document.getElementById('addModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="add_subnet" value="1">
            <div class="input-group">
                <label>Subnet Address (e.g. 192.168.1.0)</label>
                <input type="text" name="subnet" class="input-control" required>
            </div>
            <div class="input-group">
                <label>Mask (CIDR, e.g. 24)</label>
                <input type="number" name="mask" class="input-control" min="0" max="32" required>
            </div>
            <div class="input-group">
                <label>Description</label>
                <input type="text" name="description" class="input-control">
            </div>
            <div class="input-group">
                <label>VLAN (optional)</label>
                <select name="vlan_id" class="input-control" style="appearance: none;">
                    <option value="">No VLAN</option>
                    <?php foreach ($vlans_list as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>">VLAN <?php echo htmlspecialchars((string)$v['number']); ?> — <?php echo htmlspecialchars($v['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem;">Create VLANs first under <strong>VLANs</strong> if the list is empty.</p>
            </div>
            <div class="input-group">
                <label>Auto-Scan Interval</label>
                <select name="scan_interval" class="input-control" style="appearance: none;">
                    <option value="0">Manual Only</option>
                    <option value="30">Every 30 Minutes</option>
                    <option value="60">Every 1 Hour</option>
                    <option value="360">Every 6 Hours</option>
                    <option value="720">Every 12 Hours</option>
                    <option value="1440">Every 24 Hours</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">Save Subnet</button>
        </form>
    </div>
</div>

<!-- Simple Edit Modal (Hidden by default) -->
<div id="editModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit Subnet</h3>
            <button onclick="document.getElementById('editModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="edit_subnet" value="1">
            <input type="hidden" name="subnet_id" id="edit_subnet_id">
            <div class="input-group">
                <label>Subnet Address</label>
                <input type="text" name="subnet" id="edit_subnet" class="input-control" required>
            </div>
            <div class="input-group">
                <label>Mask (CIDR)</label>
                <input type="number" name="mask" id="edit_mask" class="input-control" min="0" max="32" required>
            </div>
            <div class="input-group">
                <label>Description</label>
                <input type="text" name="description" id="edit_description" class="input-control">
            </div>
            <div class="input-group">
                <label>VLAN (optional)</label>
                <select name="vlan_id" id="edit_vlan_id" class="input-control" style="appearance: none;">
                    <option value="">No VLAN</option>
                    <?php foreach ($vlans_list as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>">VLAN <?php echo htmlspecialchars((string)$v['number']); ?> — <?php echo htmlspecialchars($v['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label>Auto-Scan Interval</label>
                <select name="scan_interval" id="edit_scan_interval" class="input-control" style="appearance: none;">
                    <option value="0">Manual Only</option>
                    <option value="30">Every 30 Minutes</option>
                    <option value="60">Every 1 Hour</option>
                    <option value="360">Every 6 Hours</option>
                    <option value="720">Every 12 Hours</option>
                    <option value="1440">Every 24 Hours</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">Update Subnet</button>
        </form>
    </div>
</div>

<script>
function openEditModal(subnet) {
    document.getElementById('edit_subnet_id').value = subnet.id;
    document.getElementById('edit_subnet').value = subnet.subnet;
    document.getElementById('edit_mask').value = subnet.mask;
    document.getElementById('edit_description').value = subnet.description;
    document.getElementById('edit_vlan_id').value = subnet.vlan_id || '';
    document.getElementById('edit_scan_interval').value = subnet.scan_interval || '0';
    
    document.getElementById('editModal').style.display = 'flex';
}
</script>

<?php include 'includes/footer.php'; ?>
