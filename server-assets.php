<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/audit.helper.php';
require_once 'includes/asset.helper.php';

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
$message = $_GET['msg'] ?? '';
$error = '';

// Handle Server Asset Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add' || $action === 'edit') {
            $hostname = $_POST['hostname'];
            $ip_address = $_POST['ip_address'];
            $category = $_POST['category'] ?: 'General';
            $username = $_POST['username'];
            $password = $_POST['password'];
            $port = (int)$_POST['port'] ?: 22;
            $installed_apps = $_POST['installed_apps'];
            $missing_apps = $_POST['missing_apps'];
            $notes = $_POST['notes'];
            
            // Encrypt all sensitive fields
            $is_encrypted = 1;
            $password = !empty($password) ? AssetHelper::encrypt($password) : '';
            $username = AssetHelper::encrypt($username);
            $notes = AssetHelper::encrypt($notes);
            $installed_apps = AssetHelper::encrypt($installed_apps);
            $missing_apps = AssetHelper::encrypt($missing_apps);
            
            if ($action === 'add') {
                $stmt = $db->prepare("INSERT INTO server_assets (hostname, ip_address, category, username, password, is_encrypted, port, installed_apps, missing_apps, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$hostname, $ip_address, $category, $username, $password, $is_encrypted, $port, $installed_apps, $missing_apps, $notes]);
                AuditLogHelper::log("add_server_asset", "server_asset", $db->lastInsertId(), "Added server asset $hostname ($ip_address)");
                $message = "Server asset added successfully!";
            } else {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("UPDATE server_assets SET hostname = ?, ip_address = ?, category = ?, username = ?, password = ?, is_encrypted = ?, port = ?, installed_apps = ?, missing_apps = ?, notes = ? WHERE id = ?");
                $stmt->execute([$hostname, $ip_address, $category, $username, $password, $is_encrypted, $port, $installed_apps, $missing_apps, $notes, $id]);
                AuditLogHelper::log("edit_server_asset", "server_asset", $id, "Updated server asset $hostname ($ip_address)");
                $message = "Server asset updated successfully!";
            }
        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            $db->prepare("DELETE FROM server_assets WHERE id = ?")->execute([$id]);
            AuditLogHelper::log("delete_server_asset", "server_asset", $id, "Deleted server asset ID $id");
            $message = "Server asset removed.";
        } elseif ($action === 'import_csv') {
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
                $file = $_FILES['csv_file']['tmp_name'];
                $handle = fopen($file, "r");
                $headers = fgetcsv($handle, 1000, ","); // Skip header
                $imported = 0;
                $updated = 0;

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) < 9) continue;
                    
                    $hostname = $data[1];
                    $ip = $data[2];
                    $category = $data[3];
                    $username = $data[4];
                    $password = $data[5];
                    $is_encrypted = (int)$data[6];
                    $port = (int)$data[7];
                    $installed = $data[10];
                    $missing = $data[11];
                    $notes = $data[12];

                    $check = $db->prepare("SELECT id FROM server_assets WHERE hostname = ? AND ip_address = ?");
                    $check->execute([$hostname, $ip]);
                    $existing_id = $check->fetchColumn();

                    if ($existing_id) {
                        $stmt = $db->prepare("UPDATE server_assets SET category = ?, username = ?, password = ?, is_encrypted = ?, port = ?, installed_apps = ?, missing_apps = ?, notes = ? WHERE id = ?");
                        $stmt->execute([$category, $username, $password, $is_encrypted, $port, $installed, $missing, $notes, $existing_id]);
                        $updated++;
                    } else {
                        $stmt = $db->prepare("INSERT INTO server_assets (hostname, ip_address, category, username, password, is_encrypted, port, installed_apps, missing_apps, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$hostname, $ip, $category, $username, $password, $is_encrypted, $port, $installed, $missing, $notes]);
                        $imported++;
                    }
                }
                fclose($handle);
                $message = "Import completed: $imported added, $updated updated.";
                AuditLogHelper::log("import_server_assets", "server_asset", 0, "Imported assets from CSV: $imported new, $updated updated");
            } else {
                $error = "Failed to upload CSV file.";
            }
        }
    }
}

$assets = $db->query("SELECT * FROM server_assets ORDER BY hostname ASC")->fetchAll();
$last_backup = Settings::get('last_server_backup', 0);

$page_title = "Server Assets Management";
include 'includes/header.php';
?>

<div class="page-header">
    <div>
        <div class="breadcrumb">
            <span class="text-muted">Assets</span> / <span>Server Inventory</span>
        </div>
        <h1 style="font-size: 1.5rem; margin-top: 0.5rem;">Server Assets & Access</h1>
    </div>
    <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <label class="btn" style="background: var(--surface-light); cursor: pointer; padding: 6px 12px; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;">
            <input type="checkbox" id="select-all-assets" onchange="toggleAllAssets(this)" style="width: 14px; height: 14px;">
            <span>Select All</span>
        </label>
        <button class="btn btn-secondary" onclick="document.getElementById('importModal').style.display='flex'">
            <i data-lucide="upload" style="width: 14px;"></i> Import
        </button>
        <button class="btn btn-primary" onclick="openAddModal()">
            <i data-lucide="plus" style="width: 16px;"></i> Add Server
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="card" style="background: rgba(16, 185, 129, 0.1); color: var(--success); margin-bottom: 1.5rem; padding: 1rem; display: flex; align-items: center; gap: 10px;">
        <i data-lucide="check-circle" style="width: 18px;"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="card" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); margin-bottom: 1.5rem; padding: 1rem; display: flex; align-items: center; gap: 10px;">
        <i data-lucide="alert-circle" style="width: 18px;"></i>
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="grid-cards">
    <?php if (empty($assets)): ?>
        <div class="card" style="grid-column: 1/-1; text-align: center; padding: 4rem; opacity: 0.5;">
            <i data-lucide="database" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
            <h3>No server assets recorded.</h3>
            <p>Add your servers to keep track of access credentials and installed software.</p>
        </div>
    <?php endif; ?>
    
    <?php foreach ($assets as $asset): 
        if ($asset['is_encrypted']) {
            $disp_user = AssetHelper::decrypt($asset['username']);
            $disp_notes = AssetHelper::decrypt($asset['notes']);
            $disp_installed = AssetHelper::decrypt($asset['installed_apps']);
            $disp_missing = AssetHelper::decrypt($asset['missing_apps']);
        } else {
            $disp_user = $asset['username'];
            $disp_notes = $asset['notes'];
            $disp_installed = $asset['installed_apps'];
            $disp_missing = $asset['missing_apps'];
        }
    ?>
    <div class="card asset-card" data-asset-id="<?php echo $asset['id']; ?>" style="position: relative; display: flex; flex-direction: column; padding: 1.5rem; border: 1px solid var(--border); transition: transform 0.2s, box-shadow 0.2s;">
        <div class="checkbox-wrapper" style="position: absolute; top: -8px; left: -8px; z-index: 10;">
            <input type="checkbox" class="asset-checkbox" value="<?php echo $asset['id']; ?>" onchange="updateBatchBar()" style="width: 20px; height: 20px; cursor: pointer; accent-color: var(--primary);">
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem; gap: 1rem;">
            <h3 style="font-size: 1.25rem; font-weight: 800; color: white; overflow-wrap: break-word; word-break: break-word; margin: 0; line-height: 1.2; flex: 1;">
                <?php echo htmlspecialchars($asset['hostname']); ?>
            </h3>
            <div class="asset-actions" style="display: flex; gap: 0.4rem; flex-shrink: 0;">
                <button class="btn-icon" onclick="refreshStatus(<?php echo $asset['id']; ?>)" id="refresh-btn-<?php echo $asset['id']; ?>" title="Refresh Status">
                    <i data-lucide="refresh-cw" style="width: 14px;"></i>
                </button>
                <button class="btn-icon" onclick='openEditModal(<?php echo json_encode(array_merge($asset, ["disp_user"=>$disp_user, "disp_notes"=>$disp_notes, "disp_installed"=>$disp_installed, "disp_missing"=>$disp_missing])); ?>)' title="Edit Asset">
                    <i data-lucide="edit-3" style="width: 14px;"></i>
                </button>
                <form action="" method="POST" onsubmit="return confirm('Remove this server asset?');" style="display: inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $asset['id']; ?>">
                    <button type="submit" class="btn-icon btn-icon-danger" title="Delete Asset">
                        <i data-lucide="trash-2" style="width: 14px;"></i>
                    </button>
                </form>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; row-gap: 8px; margin-bottom: 1.25rem; flex-wrap: wrap;">
            <div id="status-badge-<?php echo $asset['id']; ?>" class="status-indicator <?php echo $asset['status'] === 'ONLINE' ? 'is-online' : ($asset['status'] === 'OFFLINE' ? 'is-offline' : ''); ?>">
                <span class="pulse-dot"></span>
                <span class="status-text"><?php echo $asset['status']; ?></span>
            </div>
            <code style="background: rgba(255,255,255,0.05); padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; color: var(--primary); font-weight: 700;">
                <?php echo htmlspecialchars($asset['ip_address']); ?>:<?php echo $asset['port']; ?>
            </code>
            <span style="background: rgba(99,102,241,0.1); color: var(--primary); font-size: 0.7rem; padding: 2px 10px; border-radius: 20px; font-weight: 700; text-transform: uppercase;">
                <?php echo htmlspecialchars($asset['category'] ?: 'General'); ?>
            </span>
        </div>

        <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 1rem; margin-bottom: 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                <i data-lucide="user" style="width: 14px; color: var(--text-muted);"></i>
                <span style="font-size: 0.9rem; font-weight: 600; color: var(--primary);"><?php echo htmlspecialchars($disp_user); ?></span>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <i data-lucide="key" style="width: 14px; color: var(--text-muted);"></i>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span id="pw-<?php echo $asset['id']; ?>" style="color: var(--text-muted);">••••••••</span>
                    <span id="pw-real-<?php echo $asset['id']; ?>" style="display: none; font-family: monospace; font-weight: 700; color: white;"></span>
                    <button type="button" class="btn-reveal" onclick="togglePassword(<?php echo $asset['id']; ?>)">
                        <i data-lucide="eye" id="eye-icon-<?php echo $asset['id']; ?>" style="width: 14px;"></i>
                    </button>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.25rem;">
            <div class="inventory-box inv-installed">
                <p class="inv-label"><i data-lucide="check-circle" style="width: 12px;"></i> INSTALLED</p>
                <div class="inv-content"><?php echo htmlspecialchars($disp_installed ?: '-'); ?></div>
            </div>
            <div class="inventory-box inv-pending">
                <p class="inv-label"><i data-lucide="circle-dashed" style="width: 12px;"></i> PENDING</p>
                <div class="inv-content"><?php echo htmlspecialchars($disp_missing ?: '-'); ?></div>
            </div>
        </div>

        <div style="margin-top: auto;">
            <p class="inv-label" style="margin-bottom: 0.5rem;">TECHNICAL NOTES</p>
            <div class="inv-content" style="background: rgba(0,0,0,0.2); padding: 0.75rem; border-radius: 8px; font-size: 0.8rem; font-style: italic;">
                <?php echo nl2br(htmlspecialchars($disp_notes ?: 'No technical notes recorded.')); ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modals -->
<div id="assetModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <button onclick="closeModal()" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; color: var(--text-muted); cursor: pointer;">
            <i data-lucide="x"></i>
        </button>
        <h2 id="modalTitle" style="margin-bottom: 1.5rem;">Add Server Asset</h2>
        <form action="" method="POST" autocomplete="off">
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="id" id="assetId" value="">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="input-group"><label>Hostname</label><input type="text" name="hostname" id="f_hostname" class="input-control" required></div>
                <div class="input-group"><label>IP Address</label><input type="text" name="ip_address" id="f_ip_address" class="input-control" required></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="input-group"><label>Category</label><input type="text" name="category" id="f_category" class="input-control" list="cats"></div>
                <div class="input-group"><label>Username</label><input type="text" name="username" id="f_username" class="input-control"></div>
                <div class="input-group"><label>Password</label><input type="text" name="password" id="f_password" class="input-control"></div>
            </div>
            <datalist id="cats"><option value="Production"><option value="Staging"><option value="Development"><option value="Infrastructure"></datalist>
            <div class="input-group"><label>Port</label><input type="number" name="port" id="f_port" class="input-control" value="22"></div>
            <div class="input-group"><label>Installed Apps</label><textarea name="installed_apps" id="f_installed_apps" class="input-control" style="height: 60px;"></textarea></div>
            <div class="input-group"><label>Missing Apps</label><textarea name="missing_apps" id="f_missing_apps" class="input-control" style="height: 60px;"></textarea></div>
            <div class="input-group"><label>Notes</label><textarea name="notes" id="f_notes" class="input-control" style="height: 60px;"></textarea></div>
            <button type="submit" class="btn btn-primary" id="submitBtnText" style="width: 100%; margin-top: 1rem;">Add Server Asset</button>
        </form>
    </div>
</div>

<div id="importModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 450px;">
        <button onclick="document.getElementById('importModal').style.display='none'" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; color: var(--text-muted); cursor: pointer;"><i data-lucide="x"></i></button>
        <h2 style="margin-bottom: 1.5rem;">Import CSV</h2>
        <form action="" method="POST" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="action" value="import_csv">
            <div class="input-group"><label>Select CSV File</label><input type="file" name="csv_file" class="input-control" accept=".csv" required></div>
            <button type="submit" class="btn btn-primary" style="width: 100%; mt-1rem;">Start Import</button>
        </form>
    </div>
</div>

<!-- Batch Action Bar -->
<div id="batch-action-bar" style="display: none; position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%); background: var(--surface); border: 1px solid var(--primary); border-radius: 50px; padding: 0.75rem 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 3000; align-items: center; gap: 1.5rem; animation: slideUp 0.3s ease-out;">
    <div style="display: flex; align-items: center; gap: 10px;">
        <span id="selected-count" style="background: var(--primary); color: white; padding: 2px 10px; border-radius: 20px; font-weight: 800; font-size: 0.8rem;">0</span>
        <span style="font-size: 0.9rem; font-weight: 600;">Selected</span>
    </div>
    <div style="width: 1px; height: 24px; background: var(--border);"></div>
    <button class="btn" style="background: rgba(34, 197, 94, 0.1); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.2); font-size: 0.8rem;" onclick="batchCheckStatus()"><i data-lucide="refresh-cw" style="width: 14px;"></i> Status Check</button>
    <button class="btn" style="background: rgba(99, 102, 241, 0.1); color: var(--primary); border: 1px solid rgba(99,102,241,0.2); font-size: 0.8rem;" onclick="generateAssetsPDF()"><i data-lucide="file-text" style="width: 14px;"></i> Export PDF</button>
    <button class="btn" style="background: none; border: none; color: var(--text-muted); font-size: 0.8rem;" onclick="clearSelection()">Cancel</button>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Add Server Asset';
    document.getElementById('modalAction').value = 'add';
    document.getElementById('submitBtnText').innerText = 'Add Server Asset';
    document.getElementById('assetId').value = '';
    ['f_hostname','f_ip_address','f_category','f_username','f_password','f_installed_apps','f_missing_apps','f_notes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('f_port').value = '22';
    document.getElementById('assetModal').style.display = 'flex';
}

function openEditModal(data) {
    document.getElementById('modalTitle').innerText = 'Edit Server Asset';
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('submitBtnText').innerText = 'Update Server Asset';
    document.getElementById('assetId').value = data.id;
    document.getElementById('f_hostname').value = data.hostname;
    document.getElementById('f_ip_address').value = data.ip_address;
    document.getElementById('f_category').value = data.category || '';
    document.getElementById('f_username').value = data.disp_user || data.username;
    document.getElementById('f_password').value = ''; // Don't show encrypted pass in input for security
    document.getElementById('f_port').value = data.port;
    document.getElementById('f_installed_apps').value = data.disp_installed || data.installed_apps;
    document.getElementById('f_missing_apps').value = data.disp_missing || data.missing_apps;
    document.getElementById('f_notes').value = data.disp_notes || data.notes;
    document.getElementById('assetModal').style.display = 'flex';
}

function closeModal() { document.getElementById('assetModal').style.display = 'none'; }

async function togglePassword(id) {
    const hidden = document.getElementById('pw-' + id), visible = document.getElementById('pw-real-' + id), icon = document.getElementById('eye-icon-' + id);
    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline'; visible.style.display = 'none'; icon.setAttribute('data-lucide', 'eye');
    } else {
        if (visible.innerText === '') {
            visible.innerText = 'Decrypting...';
            try {
                const res = await fetch('api/get-asset-password?id=' + id);
                const data = await res.json();
                visible.innerText = data.password || '-';
            } catch (e) { visible.innerText = 'Error'; }
        }
        hidden.style.display = 'none'; visible.style.display = 'inline'; icon.setAttribute('data-lucide', 'eye-off');
    }
    lucide.createIcons();
}

async function refreshStatus(id) {
    const badge = document.getElementById('status-badge-' + id), btn = document.getElementById('refresh-btn-' + id);
    badge.classList.add('loading'); btn.classList.add('spin-animation');
    try {
        const res = await fetch('api/asset-health?id=' + id);
        const data = await res.json();
        const indicator = document.getElementById('status-badge-' + id);
        indicator.className = 'status-indicator ' + (data.status === 'ONLINE' ? 'is-online' : 'is-offline');
        indicator.querySelector('.status-text').innerText = data.status;
    } catch (e) { console.error(e); } finally { btn.classList.remove('spin-animation'); lucide.createIcons(); }
}

function updateBatchBar() {
    const selected = document.querySelectorAll('.asset-checkbox:checked');
    const bar = document.getElementById('batch-action-bar');
    document.getElementById('selected-count').innerText = selected.length;
    bar.style.display = selected.length > 0 ? 'flex' : 'none';
}

function toggleAllAssets(master) {
    document.querySelectorAll('.asset-checkbox').forEach(cb => cb.checked = master.checked);
    updateBatchBar();
}

function clearSelection() {
    document.querySelectorAll('.asset-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('select-all-assets').checked = false;
    updateBatchBar();
}

async function batchCheckStatus() {
    const selected = document.querySelectorAll('.asset-checkbox:checked');
    for (const cb of selected) { await refreshStatus(cb.value); }
}

function generateAssetsPDF() {
    const selected = document.querySelectorAll('.asset-checkbox:checked');
    const ids = Array.from(selected).map(cb => cb.value);
    document.querySelectorAll('.asset-card').forEach(card => {
        if (!ids.includes(card.getAttribute('data-asset-id'))) card.classList.add('no-print');
    });
    document.body.classList.add('printing-assets');
    window.print();
    setTimeout(() => {
        document.querySelectorAll('.asset-card').forEach(card => card.classList.remove('no-print'));
        document.body.classList.remove('printing-assets');
    }, 1000);
}
</script>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
@keyframes pulse { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(74, 222, 128, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); } }
.spin-animation i { animation: spin 1s linear infinite; }
.asset-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.2); border-color: var(--primary) !important; }
.btn-icon { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); padding: 6px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
.btn-icon:hover { background: var(--primary); color: white; border-color: var(--primary); }
.status-indicator { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.05); padding: 2px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; color: var(--text-muted); border: 1px solid rgba(255,255,255,0.1); }
.status-indicator.is-online { color: #4ade80; background: rgba(74, 222, 128, 0.1); border-color: rgba(74, 222, 128, 0.2); }
.status-indicator.is-offline { color: #f87171; background: rgba(248, 113, 113, 0.1); border-color: rgba(248, 113, 113, 0.2); }
.pulse-dot { width: 8px; height: 8px; background: currentColor; border-radius: 50%; }
.is-online .pulse-dot { animation: pulse 2s infinite; }
.inventory-box { padding: 1rem; border-radius: 12px; min-height: 80px; display: flex; flex-direction: column; gap: 8px; border: 1px solid rgba(255,255,255,0.05); }
.inv-label { font-size: 0.65rem; font-weight: 700; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px; }
.inv-content { font-size: 0.8rem; color: var(--text); white-space: pre-wrap; line-height: 1.4; }
.btn-reveal { background: none; border: none; cursor: pointer; color: var(--primary); padding: 4px; border-radius: 4px; transition: background 0.2s; }
.btn-reveal:hover { background: rgba(99,102,241,0.1); }
@keyframes slideUp { from { transform: translate(-50%, 20px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
@media print { .no-print { display: none !important; } .printing-assets .main-content { margin-left: 0 !important; } }
</style>

<?php include 'includes/footer.php'; ?>
