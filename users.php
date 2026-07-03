<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index');
    exit;
}

$db = get_db_connection();
$page_title = 'User Management';

// Handle user addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $role = $_POST['role'];
    
    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $email, $password, $role]);
    } catch (Exception $e) {
        $error = "Error adding user: " . $e->getMessage();
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $user_id = $_POST['user_id'];
    $new_password = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
    
    try {
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$new_password, $user_id]);
        $message = "Password reset successfully.";
    } catch (Exception $e) {
        $error = "Error resetting password: " . $e->getMessage();
    }
}


// Handle user deletion
if (isset($_GET['delete_id']) && $_SESSION['role'] === 'admin') {
    if ($_GET['delete_id'] != $_SESSION['user_id']) {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$_GET['delete_id']]);
        header('Location: users');
        exit;
    }
}

// Fetch users
$users = $db->query("SELECT id, username, email, role, created_at FROM users ORDER BY username ASC")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1 style="font-size: 1.5rem;">User Management</h1>
    <button class="btn btn-primary" onclick="document.getElementById('addUserModal').style.display='flex'">
        <i data-lucide="user-plus"></i> Add User
    </button>
</div>

<?php if (isset($message)): ?>
    <div class="card" style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); margin-bottom: 1.5rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="card" style="padding: 1rem; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); margin-bottom: 1.5rem;">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th style="padding: 1rem; color: var(--text-muted);">Username</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Role</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Created At</th>
                    <th style="padding: 1rem; color: var(--text-muted); text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 1rem;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="background: var(--surface-light); width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: var(--primary);">
                                    <?php echo strtoupper($u['username'][0]); ?>
                                </div>
                                <?php echo htmlspecialchars($u['username']); ?>
                            </div>
                        </td>
                        <td style="padding: 1rem;">
                            <span style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; background: rgba(59, 130, 246, 0.1); color: var(--primary); text-transform: uppercase; font-weight: 600;">
                                <?php echo htmlspecialchars($u['role']); ?>
                            </span>
                        </td>
                        <td style="padding: 1rem; font-size: 0.875rem; color: var(--text-muted);">
                            <?php echo $u['created_at']; ?>
                        </td>
                        <td style="padding: 1rem; text-align: right;">
                            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <button class="btn" style="padding: 6px; background: rgba(59, 130, 246, 0.1); color: var(--primary);" onclick="openResetModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" title="Reset Password">
                                        <i data-lucide="key" style="width: 16px;"></i>
                                    </button>
                                    <a href="?delete_id=<?php echo $u['id']; ?>" class="btn" style="padding: 6px; background: rgba(239, 68, 68, 0.1); color: var(--danger);" onclick="return confirm('Are you sure?')" title="Delete User">
                                        <i data-lucide="trash-2" style="width: 16px;"></i>
                                    </a>
                                <?php else: ?>
                                    <span style="font-size: 0.75rem; color: var(--text-muted);">Current User</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 400px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Add New User</h3>
            <button onclick="document.getElementById('addUserModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="add_user" value="1">
            <div class="input-group">
                <label>Username</label>
                <input type="text" name="username" class="input-control" required autocomplete="off" readonly onfocus="this.removeAttribute('readonly');">
            </div>
            <div class="input-group">
                <label>Email Address</label>
                <input type="email" name="email" class="input-control" required>
            </div>
            <div class="input-group">
                <label>Password</label>
                <input type="password" name="password" class="input-control" required autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly');">
            </div>
            <div class="input-group">
                <label>Role</label>
                <select name="role" class="input-control">
                    <option value="admin">Administrator (Full Access)</option>
                    <option value="viewer">Viewer (Read-only)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;">Create User</button>
        </form>
    </div>
</div>

<!-- Reset Password Modal (added for functionality) -->
<div id="resetModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 1000;">
    <div class="card" style="width: 100%; max-width: 400px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Reset Password: <span id="reset_username"></span></h3>
            <button onclick="document.getElementById('resetModal').style.display='none'" style="background: none; border: none; color: var(--text-muted); cursor: pointer;">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="user_id" id="reset_user_id">
            <div class="input-group">
                <label>New Password</label>
                <input type="password" name="new_password" class="input-control" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 1rem;">Reset Password</button>
        </form>
    </div>
</div>

<script>
function openResetModal(id, username) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_username').innerText = username;
    document.getElementById('resetModal').style.display = 'flex';
}
</script>

<?php include 'includes/footer.php'; ?>
