<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$page_title = 'VLANs';

// Handle Add VLAN
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vlan']) && is_admin()) {
    $number = $_POST['number'] ?? '';
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';

    try {
        $stmt = $db->prepare("INSERT INTO vlans (number, name, description) VALUES (?, ?, ?)");
        $stmt->execute([$number, $name, $description]);
        $message = 'VLAN added successfully!';
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

// Handle Edit VLAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_vlan']) && is_admin()) {
    $vid = (int)$_POST['vlan_id'];
    $number = $_POST['number'] ?? '';
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';

    try {
        $stmt = $db->prepare("UPDATE vlans SET number = ?, name = ?, description = ? WHERE id = ?");
        $stmt->execute([$number, $name, $description, $vid]);
        $message = 'VLAN updated successfully!';
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

// Handle Delete VLAN
if (isset($_GET['delete']) && is_admin()) {
    $vid = (int)$_GET['delete'];
    try {
        $stmt = $db->prepare("DELETE FROM vlans WHERE id = ?");
        $stmt->execute([$vid]);
        header('Location: vlans?msg=deleted');
        exit;
    } catch (Exception $e) {
        $message = 'Error deleting VLAN: ' . $e->getMessage();
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $message = 'VLAN deleted successfully!';
}

// Fetch VLANs
$vlans = $db->query("SELECT * FROM vlans ORDER BY number ASC")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1 style="font-size: 1.5rem;">VLAN Management</h1>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="export?type=vlans" class="btn btn-secondary" style="font-size: 0.875rem;">
            <i data-lucide="download" style="width: 14px;"></i> Export CSV
        </a>
        <button class="btn btn-secondary" style="font-size: 0.875rem;" onclick="window.print()">
            <i data-lucide="printer" style="width: 14px;"></i> Print PDF
        </button>
        <?php if (is_admin()): ?>
        <button class="btn btn-primary" style="font-size: 0.875rem;" onclick="document.getElementById('vlanModal').style.display='flex'">
            <i data-lucide="plus" style="width: 14px;"></i> Add VLAN
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="card" style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); margin-bottom: 1.5rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th style="padding: 1rem; color: var(--text-muted); width: 100px;">Number</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Name</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Description</th>
                    <th style="padding: 1rem; color: var(--text-muted); width: 120px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vlans)): ?>
                    <tr>
                        <td colspan="4" style="padding: 2rem; text-align: center; color: var(--text-muted);">No VLANs configured yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vlans as $v): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 1rem; font-weight: 600; color: var(--primary);">#<?php echo $v['number']; ?></td>
                            <td style="padding: 1rem; font-weight: 500;"><?php echo $v['name']; ?></td>
                            <td style="padding: 1rem; color: var(--text-muted);"><?php echo $v['description']; ?></td>
                            <td style="padding: 1rem; text-align: right;">
                                <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                    <?php if (is_admin()): ?>
                                    <button onclick='openVlanEditModal(<?php echo json_encode($v); ?>)' class="btn" style="padding: 6px; background: rgba(16, 185, 129, 0.1); color: var(--success);" title="Edit VLAN">
                                        <i data-lucide="edit" style="width: 16px;"></i>
                                    </button>
                                    <a href="?delete=<?php echo $v['id']; ?>" class="btn" style="padding: 6px; background: rgba(239, 68, 68, 0.1); color: var(--danger);" onclick="return confirm('Are you sure? This will unassign this VLAN from all associated subnets.')" title="Delete">
                                        <i data-lucide="trash-2" style="width: 16px;"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="vlanModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add New VLAN</h3>
            <button onclick="document.getElementById('vlanModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="add_vlan" value="1">
            <div class="input-group">
                <label>VLAN Number (1-4094)</label>
                <input type="number" name="number" class="input-control" min="1" max="4094" required>
            </div>
            <div class="input-group">
                <label>VLAN Name</label>
                <input type="text" name="name" class="input-control" required>
            </div>
            <div class="input-group">
                <label>Description</label>
                <input type="text" name="description" class="input-control">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">Save VLAN</button>
        </form>
    </div>
</div>

<div id="vlanEditModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit VLAN</h3>
            <button onclick="document.getElementById('vlanEditModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="edit_vlan" value="1">
            <input type="hidden" name="vlan_id" id="edit_vlan_id">
            <div class="input-group">
                <label>VLAN Number (1-4094)</label>
                <input type="number" name="number" id="edit_vlan_number" class="input-control" min="1" max="4094" required>
            </div>
            <div class="input-group">
                <label>VLAN Name</label>
                <input type="text" name="name" id="edit_vlan_name" class="input-control" required>
            </div>
            <div class="input-group">
                <label>Description</label>
                <input type="text" name="description" id="edit_vlan_description" class="input-control">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">Update VLAN</button>
        </form>
    </div>
</div>

<script>
function openVlanEditModal(vlan) {
    document.getElementById('edit_vlan_id').value = vlan.id;
    document.getElementById('edit_vlan_number').value = vlan.number;
    document.getElementById('edit_vlan_name').value = vlan.name;
    document.getElementById('edit_vlan_description').value = vlan.description;
    document.getElementById('vlanEditModal').style.display = 'flex';
}
</script>

<?php include 'includes/footer.php'; ?>
