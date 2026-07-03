<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/audit.helper.php';

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
$message = '';
$error = '';

// Handle Link Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_link'])) {
        $parent_id = (int)$_POST['parent_switch_id'];
        $target_type = $_POST['target_type'];
        $target_id = (int)$_POST['target_id'];

        if ($target_type == 'switch' && $parent_id == $target_id) {
            $error = "A switch cannot be linked to itself.";
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO topology_links (parent_switch_id, target_type, target_id) VALUES (?, ?, ?)");
                $stmt->execute([$parent_id, $target_type, $target_id]);
                AuditLogHelper::log("add_topo_link", "topology", $db->lastInsertId(), "Linked switch $parent_id to $target_type $target_id");
                $message = "Link added successfully!";
            } catch (Exception $e) {
                $error = "This link already exists.";
            }
        }
    }

    if (isset($_POST['delete_link'])) {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM topology_links WHERE id = ?")->execute([$id]);
        AuditLogHelper::log("delete_topo_link", "topology", $id, "Deleted topology link ID $id");
        $message = "Link removed.";
    }
}

// Fetch Data for selectors
$switches = $db->query("SELECT id, name, ip_addr FROM switches ORDER BY name ASC")->fetchAll();
$subnets = $db->query("SELECT id, subnet, mask, description FROM subnets ORDER BY subnet ASC")->fetchAll();

// Fetch Existing Links
$links = $db->query("
    SELECT tl.*, 
           ps.name as parent_name, ps.ip_addr as parent_ip,
           CASE 
               WHEN tl.target_type = 'switch' THEN ts.name 
               WHEN tl.target_type = 'subnet' THEN CONCAT(sub.subnet, '/', sub.mask)
           END as target_name,
           CASE 
               WHEN tl.target_type = 'switch' THEN ts.ip_addr 
               WHEN tl.target_type = 'subnet' THEN sub.description
           END as target_info
    FROM topology_links tl
    JOIN switches ps ON tl.parent_switch_id = ps.id
    LEFT JOIN switches ts ON tl.target_type = 'switch' AND tl.target_id = ts.id
    LEFT JOIN subnets sub ON tl.target_type = 'subnet' AND tl.target_id = sub.id
    ORDER BY ps.name ASC
")->fetchAll();

$page_title = "Topology Link Manager";
include 'includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 style="font-size: 1.5rem;">Topology Link Manager</h1>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Define physical or logic connections between infrastructure components.</p>
    </div>
    <a href="topology" class="btn btn-secondary">
        <i data-lucide="map" style="width: 16px;"></i> View Map
    </a>
</div>

<?php if ($message): ?>
    <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); border-radius: 8px; margin-bottom: 1.5rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="padding: 1rem; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); border-radius: 8px; margin-bottom: 1.5rem;">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="grid-side-detail" style="grid-template-columns: 400px 1fr;">
    
    <!-- Add Link Form -->
    <div class="card">
        <h2 style="font-size: 1.125rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="plus-circle" style="width: 20px;"></i> Add New Link
        </h2>
        <form action="" method="POST" id="linkForm">
            <div class="input-group">
                <label>Source Switch</label>
                <select name="parent_switch_id" class="input-control" onchange="toggleTargets()" required>
                    <option value="">-- Choose Source --</option>
                    <?php foreach ($switches as $sw): ?>
                        <option value="<?php echo $sw['id']; ?>"><?php echo htmlspecialchars($sw['name']); ?> (<?php echo $sw['ip_addr']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group">
                <label>Target Type</label>
                <select name="target_type" id="targetType" class="input-control" onchange="toggleTargets()" required>
                    <option value="subnet">Subnet (VLAN Group)</option>
                    <option value="switch">Switch (Stack/Bridge)</option>
                </select>
            </div>

            <div class="input-group" id="subnetList">
                <label>Target Subnet</label>
                <select name="target_id_subnet" class="input-control">
                    <?php foreach ($subnets as $sub): ?>
                        <option value="<?php echo $sub['id']; ?>"><?php echo $sub['subnet']; ?>/<?php echo $sub['mask']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="input-group" id="switchList" style="display: none;">
                <label>Target Switch</label>
                <select name="target_id_switch" class="input-control">
                    <?php foreach ($switches as $sw): ?>
                        <option value="<?php echo $sw['id']; ?>"><?php echo htmlspecialchars($sw['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <input type="hidden" name="target_id" id="finalTargetId">

            <button type="submit" name="add_link" class="btn btn-primary" style="width: 100%; margin-top: 1rem; justify-content: center; padding: 1rem;" onclick="prepareSubmit()">
                Save Link
            </button>
        </form>
    </div>

    <!-- Links List -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <h2 style="font-size: 1.125rem; padding: 1.5rem; border-bottom: 1px solid var(--border);">Active Connections</h2>
        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border); background: rgba(255,255,255,0.02);">
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Source</th>
                        <th style="padding: 1rem; color: var(--text-muted); text-align: center; font-size: 0.8rem; width: 60px;">Link</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Destination</th>
                        <th style="padding: 1rem; color: var(--text-muted); text-align: right; font-size: 0.8rem;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($links)): ?>
                        <tr>
                            <td colspan="4" style="padding: 4rem; text-align: center; color: var(--text-muted);">
                                No links defined. Start by adding one from the left panel.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($links as $link): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 1rem;">
                                <div style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($link['parent_name']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;"><?php echo $link['parent_ip']; ?></div>
                            </td>
                            <td style="padding: 1rem; text-align: center;">
                                <i data-lucide="arrow-right-circle" style="width: 18px; color: var(--text-muted); opacity: 0.4;"></i>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($link['target_type'] == 'switch'): ?>
                                        <span style="background: rgba(59, 130, 246, 0.1); color: var(--primary); padding: 2px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: 800;">SW</span>
                                    <?php else: ?>
                                        <span style="background: rgba(16, 185, 129, 0.1); color: var(--success); padding: 2px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: 800;">NET</span>
                                    <?php endif; ?>
                                    <div style="font-weight: 700;"><?php echo htmlspecialchars($link['target_name']); ?></div>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($link['target_info']); ?></div>
                            </td>
                            <td style="padding: 1rem; text-align: right;">
                                <form action="" method="POST" onsubmit="return confirm('Remove this link?');">
                                    <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                                    <button type="submit" name="delete_link" class="btn" style="padding: 6px; color: var(--danger); background: rgba(239,68,68,0.1);">
                                        <i data-lucide="trash-2" style="width: 14px;"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function toggleTargets() {
        const type = document.getElementById('targetType').value;
        document.getElementById('subnetList').style.display = (type === 'subnet' ? 'block' : 'none');
        document.getElementById('switchList').style.display = (type === 'switch' ? 'block' : 'none');
    }
    
    function prepareSubmit() {
        const type = document.getElementById('targetType').value;
        const val = type === 'subnet' 
            ? document.getElementsByName('target_id_subnet')[0].value 
            : document.getElementsByName('target_id_switch')[0].value;
        document.getElementById('finalTargetId').value = val;
    }
</script>

<?php include 'includes/footer.php'; ?>
