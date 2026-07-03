<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/audit.helper.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$message = '';

// Handle Switch Add/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    if (isset($_POST['add_switch'])) {
        $name = $_POST['name'];
        $ip = $_POST['ip_addr'];
        $community = $_POST['community'] ?: 'public';
        $version = $_POST['snmp_version'] ?: '2c';
        $parent_id = (isset($_POST['parent_switch_id']) && $_POST['parent_switch_id'] !== '') ? (int)$_POST['parent_switch_id'] : null;
        
        $stmt = $db->prepare("INSERT INTO switches (name, ip_addr, community, snmp_version, parent_switch_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $ip, $community, $version, $parent_id]);
        AuditLogHelper::log("add_switch", "switch", $db->lastInsertId(), "Added switch $name ($ip)");
        $message = "Switch added successfully!";
    }
    
    if (isset($_POST['delete_switch'])) {
        $id = $_POST['id'];
        $db->prepare("DELETE FROM switches WHERE id = ?")->execute([$id]);
        AuditLogHelper::log("delete_switch", "switch", $id, "Deleted switch ID $id");
        $message = "Switch removed.";
    }
}

$switches = $db->query("SELECT * FROM switches ORDER BY name ASC")->fetchAll();

$page_title = "Switch Management";
include 'includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 style="font-size: 1.5rem;">Managed Switches</h1>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Manage devices for L2 port mapping discovery</p>
    </div>
    <?php if (is_admin()): ?>
    <div style="display: flex; gap: 10px;">
        <button class="btn btn-secondary" onclick="location.href='cron_switch_poll'">
            <i data-lucide="refresh-cw" style="width: 16px;"></i> Sync All Switches
        </button>
        <button class="btn btn-primary" onclick="document.getElementById('addSwitchModal').style.display='flex'">
            <i data-lucide="plus" style="width: 16px;"></i> Add Switch
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="card" style="background: rgba(16, 185, 129, 0.1); color: var(--success); margin-bottom: 1.5rem; padding: 1rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="grid-cards">
    <?php if (empty($switches)): ?>
        <div class="card" style="grid-column: 1/-1; text-align: center; padding: 4rem; opacity: 0.5;">
            <i data-lucide="server" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
            <h3>No managed switches found.</h3>
            <p>Add your network switches to start discover device physical port locations.</p>
        </div>
    <?php endif; ?>
    
    <?php foreach ($switches as $switch): ?>
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
            <div>
                <h3 style="font-size: 1.125rem;"><?php echo htmlspecialchars($switch['name']); ?></h3>
                <code style="color: var(--primary);"><?php echo htmlspecialchars($switch['ip_addr']); ?></code>
            </div>
            <span style="background: rgba(59, 130, 246, 0.1); color: var(--primary); padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">
                SNMP <?php echo strtoupper($switch['snmp_version']); ?>
            </span>
        </div>
        
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.5rem; background: rgba(0,0,0,0.05); padding: 0.75rem; border-radius: 8px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                <span>CPU Usage</span>
                <span style="font-weight: 600; color: var(--text);"><?php echo (int)($switch['cpu_usage'] ?? 0); ?>%</span>
            </div>
            <div style="height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; margin-bottom: 1rem;">
                <div style="width: <?php echo (int)($switch['cpu_usage'] ?? 0); ?>%; height: 100%; background: <?php echo (int)($switch['cpu_usage'] ?? 0) > 80 ? 'var(--danger)' : 'var(--primary)'; ?>;"></div>
            </div>

            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                <span>Memory Usage</span>
                <span style="font-weight: 600; color: var(--text);"><?php echo (int)($switch['memory_usage'] ?? 0); ?>%</span>
            </div>
            <div style="height: 6px; background: var(--border); border-radius: 3px; overflow: hidden;">
                <div style="width: <?php echo (int)($switch['memory_usage'] ?? 0); ?>%; height: 100%; background: <?php echo (int)($switch['memory_usage'] ?? 0) > 80 ? 'var(--warning)' : 'var(--success)'; ?>;"></div>
            </div>
        </div>

        <div style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1.5rem;">
            <p style="margin-bottom: 0.4rem;"><i data-lucide="cpu" style="width: 14px; vertical-align: middle;"></i> Model: <strong><?php echo htmlspecialchars($switch['model'] ?? 'Unknown'); ?></strong></p>
            <p style="margin-bottom: 0.4rem;"><i data-lucide="timer" style="width: 14px; vertical-align: middle;"></i> Uptime: <?php echo htmlspecialchars($switch['uptime'] ?? '-'); ?></p>
            <?php if (isset($switch['total_ports']) && $switch['total_ports'] > 0): ?>
            <p style="margin-bottom: 0.4rem;"><i data-lucide="cable" style="width: 14px; vertical-align: middle;"></i> Ports: <strong style="color: var(--success);"><?php echo (int)$switch['active_ports']; ?></strong> / <?php echo (int)$switch['total_ports']; ?> up</p>
            <?php endif; ?>
            <p style="margin-bottom: 0.4rem;"><i data-lucide="shield" style="width: 14px; vertical-align: middle;"></i> Community: <?php echo htmlspecialchars($switch['community']); ?></p>
            <p><i data-lucide="clock" style="width: 14px; vertical-align: middle;"></i> Last Poll: <?php echo $switch['last_poll'] ? date('d M Y H:i', strtotime($switch['last_poll'])) : 'Never'; ?></p>
        </div>

        <?php if (!empty($switch['system_info'])): ?>
            <div style="font-size: 0.7rem; color: var(--text-muted); background: var(--surface-light); padding: 0.5rem; border-radius: 4px; border-left: 2px solid var(--primary); margin-bottom: 1rem;">
                <?php echo htmlspecialchars(substr($switch['system_info'], 0, 100)) . (strlen($switch['system_info']) > 100 ? '...' : ''); ?>
            </div>
        <?php endif; ?>
        
        <div style="display: flex; gap: 0.5rem; border-top: 1px solid var(--border); padding-top: 1rem;">
            <button class="btn btn-primary" style="flex: 1; font-size: 0.75rem;" onclick="location.href='switch-details?id=<?php echo $switch['id']; ?>'">
                <i data-lucide="eye" style="width: 14px; margin-right: 4px;"></i> Details
            </button>
            <?php if (is_admin()): ?>
            <button class="btn" style="background: var(--surface-light); font-size: 0.75rem;" onclick="location.href='cron_switch_poll?id=<?php echo $switch['id']; ?>'">
                <i data-lucide="refresh-cw" style="width: 14px;"></i> Poll
            </button>
            <form action="" method="POST" onsubmit="return confirm('Remove this switch?');">
                <input type="hidden" name="id" value="<?php echo $switch['id']; ?>">
                <button type="submit" name="delete_switch" class="btn" style="background: var(--surface-light); color: var(--danger); padding: 5px 10px;">
                    <i data-lucide="trash-2" style="width: 14px;"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add Switch Modal -->
<div id="addSwitchModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center;">
    <div class="card" style="width: 450px;">
        <button onclick="document.getElementById('addSwitchModal').style.display='none'" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; color: var(--text-muted); cursor: pointer;">
            <i data-lucide="x"></i>
        </button>
        <h2 style="margin-bottom: 1.5rem;">Add Managed Switch</h2>
        <form action="" method="POST" autocomplete="off">
            <div class="input-group">
                <label>Switch Name</label>
                <input type="text" name="name" class="input-control" placeholder="e.g. Core-SW-01" required>
            </div>
            <div class="input-group">
                <label>Management IP</label>
                <input type="text" name="ip_addr" class="input-control" placeholder="10.10.0.1" required>
            </div>
            <div class="input-group">
                <label>SNMP Community</label>
                <input type="text" name="community" class="input-control" placeholder="public" value="public">
            </div>
            <div class="input-group">
                <label>SNMP Version</label>
                <select name="snmp_version" class="input-control">
                    <option value="1">v1</option>
                    <option value="2c" selected>v2c</option>
                    <option value="3">v3 (TBD)</option>
                </select>
            </div>
            <div class="input-group">
                <label>Upstream / Parent Switch (Optional)</label>
                <select name="parent_switch_id" class="input-control">
                    <option value="">-- No Parent (Root Switch) --</option>
                    <?php foreach ($switches as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?> (<?php echo $s['ip_addr']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="add_switch" class="btn btn-primary" style="width: 100%; margin-top: 1rem; padding: 1rem;">
                Add Switch
            </button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
