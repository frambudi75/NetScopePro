<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$page_title = 'Netwatch Monitoring';

// Handle Add/Edit/Delete Actions
$message = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') $message = 'Success: Target added successfully!';
    if ($_GET['msg'] === 'updated') $message = 'Success: Target updated successfully!';
    if ($_GET['msg'] === 'deleted') $message = 'Success: Target deleted successfully!';
    if ($_GET['msg'] === 'error') $message = 'Error: ' . ($_SESSION['last_error'] ?? 'An unknown error occurred.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    if (isset($_POST['add_netwatch'])) {
        $name = $_POST['name'] ?? '';
        $host = $_POST['host'] ?? '';
        $interval = (int)($_POST['interval'] ?? 60);
        $threshold = (int)($_POST['threshold'] ?? 3);
        $notify = isset($_POST['notify']) ? 1 : 0;

        try {
            $stmt = $db->prepare("INSERT INTO netwatch (name, host, ping_interval, fail_threshold, notify) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $host, $interval, $threshold, $notify]);
            header('Location: netwatch?msg=added');
            exit;
        } catch (Exception $e) {
            $_SESSION['last_error'] = $e->getMessage();
            header('Location: netwatch?msg=error');
            exit;
        }
    }

    if (isset($_POST['edit_netwatch'])) {
        $id = (int)$_POST['id'];
        $name = $_POST['name'] ?? '';
        $host = $_POST['host'] ?? '';
        $interval = (int)$_POST['interval'] ?? 60;
        $threshold = (int)$_POST['threshold'] ?? 3;
        $notify = isset($_POST['notify']) ? 1 : 0;

        try {
            $stmt = $db->prepare("UPDATE netwatch SET name = ?, host = ?, ping_interval = ?, fail_threshold = ?, notify = ? WHERE id = ?");
            $stmt->execute([$name, $host, $interval, $threshold, $notify, $id]);
            header('Location: netwatch?msg=updated');
            exit;
        } catch (Exception $e) {
            $_SESSION['last_error'] = $e->getMessage();
            header('Location: netwatch?msg=error');
            exit;
        }
    }
}

if (isset($_GET['delete']) && is_admin()) {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM netwatch WHERE id = ?")->execute([$id]);
    header('Location: netwatch?msg=deleted');
    exit;
}

if (isset($_GET['snooze']) && isset($_GET['hours']) && is_admin()) {
    $id = (int)$_GET['snooze'];
    $hours = (int)$_GET['hours'];
    $until = ($hours == 0) ? null : date('Y-m-d H:i:s', strtotime("+$hours hour"));
    $db->prepare("UPDATE netwatch SET maintenance_until = ? WHERE id = ?")->execute([$until, $id]);
    header('Location: netwatch?msg=updated');
    exit;
}

// Fetch Netwatch Targets
$targets = $db->query("SELECT * FROM netwatch ORDER BY id DESC")->fetchAll();

include 'includes/header.php';
?>

<style>
    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-transform: uppercase;
    }
    .status-up { background: rgba(16, 185, 129, 0.15); color: #10b981; }
    .status-down { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
    .status-unknown { background: rgba(107, 114, 128, 0.15); color: #6b7280; }

    .pulse {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: currentColor;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulse-green 2s infinite;
    }
    .status-down .pulse {
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
        animation: pulse-red 2s infinite;
    }

    @keyframes pulse-green {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    @keyframes pulse-red {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem;">
    <div>
        <h1 style="font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="eye" class="text-primary"></i> 
            Netwatch Monitoring
        </h1>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Real-time host availability tracking and status history.</p>
        <?php
        $last_scan = $db->query("SELECT MAX(last_check) FROM netwatch")->fetchColumn();
        if ($last_scan):
        ?>
        <div style="margin-top: 10px; font-size: 0.75rem; display: flex; align-items: center; gap: 6px; color: var(--text-muted);">
            <span class="pulse" style="width: 6px; height: 6px; background: #10b981;"></span>
            Scanner Active: Last global check at <span style="color: white; font-weight: 600;"><?php echo date('H:i:s', strtotime($last_scan)); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <?php if (is_admin()): ?>
        <button id="scanBtn" class="btn btn-warning" onclick="runScanner()" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2);">
            <i data-lucide="scan" style="width: 14px;"></i> Scan All Now
        </button>
        <?php endif; ?>
        <button class="btn btn-secondary" onclick="location.reload()">
            <i data-lucide="refresh-cw" style="width: 14px;"></i> Refresh
        </button>
        <?php if (is_admin()): ?>
        <button class="btn btn-primary" onclick="document.getElementById('addModal').style.display='flex'">
            <i data-lucide="plus" style="width: 14px;"></i> Add Target
        </button>
        <?php endif; ?>
    </div>
</div>

<script>
function runScanner() {
    const btn = document.getElementById('scanBtn');
    const originalContent = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 14px;"></i> Scanning...';
    lucide.createIcons();

    fetch('cron_netwatch.php')
        .then(response => response.text())
        .then(data => {
            console.log(data);
            if (data.includes('Failed')) {
                alert('Scan selesai, tapi ada GAGAL kirim notifikasi:\n\n' + data);
            }
            location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Scan failed! Check console for details.');
            btn.disabled = false;
            btn.innerHTML = originalContent;
            lucide.createIcons();
        });
}
</script>

<?php if ($message): ?>
    <div style="padding: 1rem; background: <?php echo strpos($message, 'Error') === 0 ? 'rgba(239, 68, 68, 0.1)' : 'rgba(16, 185, 129, 0.1)'; ?>; border: 1px solid <?php echo strpos($message, 'Error') === 0 ? '#ef4444' : 'var(--success)'; ?>; color: <?php echo strpos($message, 'Error') === 0 ? '#ef4444' : 'var(--success)'; ?>; border-radius: 8px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
        <i data-lucide="<?php echo strpos($message, 'Error') === 0 ? 'alert-circle' : 'check-circle'; ?>" style="width: 18px;"></i>
        <?php 
        echo htmlspecialchars($message); 
        if (isset($_SESSION['last_error'])) unset($_SESSION['last_error']);
        ?>
    </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <?php
    $count_up = 0; $count_down = 0; $count_total = count($targets);
    foreach($targets as $t) {
        if($t['status'] == 'up') $count_up++;
        if($t['status'] == 'down') $count_down++;
    }
    ?>
    <div class="card" style="display: flex; align-items: center; gap: 1rem; border-left: 4px solid var(--primary);">
        <div style="background: rgba(59, 130, 246, 0.1); padding: 12px; border-radius: 12px; color: var(--primary);">
            <i data-lucide="activity"></i>
        </div>
        <div>
            <p style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Total Monitored</p>
            <h3 style="font-size: 1.5rem;"><?php echo $count_total; ?></h3>
        </div>
    </div>
    <div class="card" style="display: flex; align-items: center; gap: 1rem; border-left: 4px solid #10b981;">
        <div style="background: rgba(16, 185, 129, 0.1); padding: 12px; border-radius: 12px; color: #10b981;">
            <i data-lucide="check-circle"></i>
        </div>
        <div>
            <p style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Hosts Online</p>
            <h3 style="font-size: 1.5rem;"><?php echo $count_up; ?></h3>
        </div>
    </div>
    <div class="card" style="display: flex; align-items: center; gap: 1rem; border-left: 4px solid #ef4444;">
        <div style="background: rgba(239, 68, 68, 0.1); padding: 12px; border-radius: 12px; color: #ef4444;">
            <i data-lucide="alert-triangle"></i>
        </div>
        <div>
            <p style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Hosts Offline</p>
            <h3 style="font-size: 1.5rem;"><?php echo $count_down; ?></h3>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th style="padding: 1rem; color: var(--text-muted);">Name & Host</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Status</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Check Interval</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Last Up</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Last Down</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($targets)): ?>
                    <tr>
                        <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                            No targets configured. Click "Add Target" to start monitoring.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($targets as $t): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 1rem;">
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($t['name']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($t['host']); ?></div>
                            </td>
                            <td style="padding: 1rem;">
                                <span class="status-badge status-<?php echo $t['status']; ?>">
                                    <span class="pulse"></span> <?php echo $t['status']; ?>
                                </span>
                                <?php if (!empty($t['maintenance_until']) && strtotime($t['maintenance_until']) > time()): ?>
                                    <div style="margin-top: 6px; font-size: 0.65rem; background: rgba(245, 158, 11, 0.1); color: #f59e0b; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">
                                        <i data-lucide="bell-off" style="width: 10px;"></i> SNOOZED
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="font-size: 0.875rem;">Every <?php echo $t['ping_interval']; ?>s</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $t['fail_threshold']; ?> fails threshold</div>
                            </td>
                            <td style="padding: 1rem; font-size: 0.8125rem; color: var(--text-muted);">
                                <?php echo $t['last_up'] ? date('d M, H:i:s', strtotime($t['last_up'])) : '-'; ?>
                            </td>
                            <td style="padding: 1rem; font-size: 0.8125rem; color: var(--text-muted);">
                                <?php echo $t['last_down'] ? date('d M, H:i:s', strtotime($t['last_down'])) : '-'; ?>
                            </td>
                            <td style="padding: 1rem;">
                                    <div style="display: flex; gap: 0.5rem;">
                                        <button class="btn btn-sm" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; padding: 5px;" onclick='openHistoryModal(<?php echo $t['id']; ?>, "<?php echo addslashes($t['name']); ?>")' title="View Latency History">
                                            <i data-lucide="line-chart" style="width: 14px;"></i>
                                        </button>
                                        
                                        <?php if (is_admin()): ?>
                                        <!-- Snooze Dropdown -->
                                        <div style="position: relative;">
                                            <button class="btn btn-sm" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; padding: 5px;" onclick="toggleSnoozeMenu(<?php echo $t['id']; ?>)" title="Snooze Notifications">
                                                <i data-lucide="bell-off" style="width: 14px;"></i>
                                            </button>
                                            <div id="snooze-menu-<?php echo $t['id']; ?>" style="display: none; position: absolute; right: 0; top: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 100; min-width: 120px; overflow: hidden; margin-top: 5px;">
                                                <a href="?snooze=<?php echo $t['id']; ?>&hours=1" style="display: block; padding: 8px 12px; font-size: 0.75rem; text-decoration: none; color: white;">Snooze 1h</a>
                                                <a href="?snooze=<?php echo $t['id']; ?>&hours=6" style="display: block; padding: 8px 12px; font-size: 0.75rem; text-decoration: none; color: white; border-top: 1px solid var(--border);">Snooze 6h</a>
                                                <a href="?snooze=<?php echo $t['id']; ?>&hours=24" style="display: block; padding: 8px 12px; font-size: 0.75rem; text-decoration: none; color: white; border-top: 1px solid var(--border);">Snooze 24h</a>
                                                <?php if (!empty($t['maintenance_until'])): ?>
                                                    <a href="?snooze=<?php echo $t['id']; ?>&hours=0" style="display: block; padding: 8px 12px; font-size: 0.75rem; text-decoration: none; color: var(--danger); border-top: 1px solid var(--border);">Wake Now</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <button class="btn btn-sm" style="background: rgba(59, 130, 246, 0.1); color: var(--primary); padding: 5px;" onclick='openEditModal(<?php echo json_encode($t); ?>)'>
                                            <i data-lucide="edit-3" style="width: 14px;"></i>
                                        </button>
                                        <a href="?delete=<?php echo $t['id']; ?>" class="btn btn-sm" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 5px;" onclick="return confirm('Delete this target?')">
                                            <i data-lucide="trash-2" style="width: 14px;"></i>
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

<!-- Add Modal -->
<div id="addModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add New Netwatch</h3>
            <button onclick="document.getElementById('addModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="add_netwatch" value="1">
            <div class="input-group">
                <label>Target Name</label>
                <input type="text" name="name" class="input-control" placeholder="e.g. Core Switch" required>
            </div>
            <div class="input-group">
                <label>Host (IP or Address)</label>
                <input type="text" name="host" class="input-control" placeholder="e.g. 192.168.1.1" required>
            </div>
            <div class="input-group grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label>Interval (Seconds)</label>
                    <input type="number" name="interval" class="input-control" value="60" required>
                </div>
                <div>
                    <label>Fail Threshold</label>
                    <input type="number" name="threshold" class="input-control" value="3" required>
                </div>
            </div>
            <div class="input-group" style="display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" name="notify" id="notify">
                <label for="notify" style="margin: 0; cursor: pointer;">Enable Telegram/Email Notifications</label>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;">Save Target</button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Edit Netwatch</h3>
            <button onclick="document.getElementById('editModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="edit_netwatch" value="1">
            <input type="hidden" name="id" id="edit_id">
            <div class="input-group">
                <label>Target Name</label>
                <input type="text" name="name" id="edit_name" class="input-control" required>
            </div>
            <div class="input-group">
                <label>Host</label>
                <input type="text" name="host" id="edit_host" class="input-control" required>
            </div>
            <div class="input-group grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label>Interval (s)</label>
                    <input type="number" name="interval" id="edit_interval" class="input-control" required>
                </div>
                <div>
                    <label>Threshold</label>
                    <input type="number" name="threshold" id="edit_threshold" class="input-control" required>
                </div>
            </div>
            <div class="input-group" style="display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" name="notify" id="edit_notify">
                <label for="edit_notify" style="margin: 0; cursor: pointer;">Enable Notifications</label>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">Update Target</button>
        </form>
    </div>
</div>

<!-- History Modal -->
<div id="historyModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 800px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div>
                <h3 id="historyTitle">Latency History</h3>
                <p style="font-size: 0.75rem; color: var(--text-muted);">Historical response time (ms) for this target.</p>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <select id="periodSelect" class="input-control" style="padding: 4px 8px; font-size: 0.75rem; width: auto;" onchange="refreshHistory()">
                    <option value="1h">Last 1 Hour</option>
                    <option value="6h">Last 6 Hours</option>
                    <option value="24h">Last 24 Hours</option>
                </select>
                <button onclick="document.getElementById('historyModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                    <i data-lucide="x"></i>
                </button>
            </div>
        </div>
        <div style="height: 350px;">
            <canvas id="latencyChart"></canvas>
        </div>
    </div>
</div>

<script>
let historyChart = null;
let currentHistoryId = null;

function openHistoryModal(id, name) {
    currentHistoryId = id;
    document.getElementById('historyTitle').innerText = 'Latency: ' + name;
    document.getElementById('historyModal').style.display = 'flex';
    refreshHistory();
}

function refreshHistory() {
    const period = document.getElementById('periodSelect').value;
    fetch(`api/netwatch-history.php?id=${currentHistoryId}&period=${period}`)
        .then(res => res.json())
        .then(data => {
            const ctx = document.getElementById('latencyChart').getContext('2d');
            
            if (historyChart) historyChart.destroy();
            
            historyChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Latency (ms)',
                        data: data.data,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: data.data.length > 50 ? 0 : 3,
                        pointBackgroundColor: data.status.map(s => s === 'down' ? '#ef4444' : '#6366f1')
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: 'rgba(255,255,255,0.5)', callback: v => v + 'ms' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { 
                                color: 'rgba(255,255,255,0.5)',
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 10
                            }
                        }
                    }
                }
            });
            lucide.createIcons();
        });
}

function toggleSnoozeMenu(id) {
    const menus = document.querySelectorAll('[id^="snooze-menu-"]');
    menus.forEach(m => { if(m.id !== 'snooze-menu-'+id) m.style.display = 'none'; });
    
    const menu = document.getElementById('snooze-menu-' + id);
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// Close menus on click outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('[id^="snooze-menu-"]') && !e.target.closest('button[onclick^="toggleSnoozeMenu"]')) {
        document.querySelectorAll('[id^="snooze-menu-"]').forEach(m => m.style.display = 'none');
    }
});

function openEditModal(target) {
    document.getElementById('edit_id').value = target.id;
    document.getElementById('edit_name').value = target.name;
    document.getElementById('edit_host').value = target.host;
    document.getElementById('edit_interval').value = target.ping_interval;
    document.getElementById('edit_threshold').value = target.fail_threshold;
    document.getElementById('edit_notify').checked = target.notify == 1;
    document.getElementById('editModal').style.display = 'flex';
}
</script>

<?php include 'includes/footer.php'; ?>
