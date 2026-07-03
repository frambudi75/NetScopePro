<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

if (!is_admin()) {
    header('Location: index');
    exit;
}

$db = get_db_connection();
$page_title = 'Add New Subnet';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subnet = $_POST['subnet'] ?? '';
    $mask = $_POST['mask'] ?? '';
    $description = $_POST['description'] ?? '';
    $vlan_id = (!empty($_POST['vlan_id'])) ? $_POST['vlan_id'] : null;
    $scan_interval = (int)($_POST['scan_interval'] ?? 0);
    $section_id = 1; // Default section

    if ($subnet && $mask) {
        try {
            $stmt = $db->prepare("INSERT INTO subnets (subnet, mask, description, vlan_id, section_id, scan_interval) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$subnet, $mask, $description, $vlan_id, $section_id, $scan_interval]);
            header('Location: subnets?msg=added');
            exit;
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please provide both subnet and mask.';
    }
}

// Fetch VLANs for dropdown
$vlans = $db->query("SELECT id, number, name FROM vlans ORDER BY number ASC")->fetchAll();

include 'includes/header.php';
?>

<div style="max-width: 600px; margin: 0 auto;">
    <div style="margin-bottom: 2rem;">
        <nav style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1rem;">
            <a href="subnets" style="color: var(--primary); text-decoration: none;">Subnets</a> / Add New
        </nav>
        <h1 style="font-size: 1.75rem; margin: 0;">Add New Subnet</h1>
        <p style="color: var(--text-muted); margin-top: 0.25rem;">Define a new network prefix to be managed by IPManager Pro.</p>
    </div>

    <?php if ($error): ?>
        <div style="padding: 1rem; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); border-radius: 8px; margin-bottom: 1.5rem;">
            <i data-lucide="alert-circle" style="width: 16px; vertical-align: middle; margin-right: 8px;"></i>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="card" style="padding: 2rem;">
        <form method="POST" autocomplete="off">
            <div class="input-group">
                <label>Subnet Address</label>
                <input type="text" name="subnet" class="input-control" placeholder="e.g. 192.168.1.0" required autofocus autocomplete="off" readonly onfocus="this.removeAttribute('readonly');">
                <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">The network base address.</small>
            </div>
            
            <div class="input-group">
                <label>Subnet Mask (CIDR)</label>
                <input type="number" name="mask" class="input-control" min="0" max="32" placeholder="24" required>
                <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">The network prefix length (0-32).</small>
            </div>

            <div class="input-group">
                <label>Assigned VLAN (Optional)</label>
                <select name="vlan_id" class="input-control">
                    <option value="">No VLAN Assignment</option>
                    <?php foreach ($vlans as $v): ?>
                        <option value="<?php echo $v['id']; ?>">VLAN <?php echo $v['number']; ?> - <?php echo htmlspecialchars($v['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label>Description / Name</label>
                <input type="text" name="description" class="input-control" placeholder="e.g. Managed Office Switch">
            </div>

            <div class="input-group">
                <label>Auto-Scan Frequency</label>
                <select name="scan_interval" class="input-control">
                    <option value="0">Manual Polling Only</option>
                    <option value="30">Every 30 Minutes</option>
                    <option value="60" selected>Every 1 Hour (Standard)</option>
                    <option value="360">Every 6 Hours</option>
                    <option value="720">Every 12 Hours</option>
                    <option value="1440">Every 24 Hours</option>
                </select>
                <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">How often the background worker should probe this network.</small>
            </div>

            <div style="margin-top: 3rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="subnets" class="btn" style="flex: 1; min-width: 120px; justify-content: center; background: var(--surface-light); border: 1px solid var(--border);">Cancel</a>
                <button type="submit" class="btn btn-primary" style="flex: 1; min-width: 180px; justify-content: center;">
                    <i data-lucide="plus" style="width: 18px;"></i> Create Subnet
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
