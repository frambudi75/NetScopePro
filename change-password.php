<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $email = $_POST['email'];
        $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->execute([$email, $_SESSION['user_id']]);
        $message = "Profile updated successfully!";
    }

    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if ($user && password_verify($current_password, $user['password'])) {
                $hashed = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed, $_SESSION['user_id']]);
                $message = "Password updated successfully!";
            } else {
                $error = "Current password is incorrect.";
            }
        }
    }
}

// Fetch current user data
$stmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_data = $stmt->fetch();

$page_title = 'Account Settings';
include 'includes/header.php';
?>

<div style="max-width: 650px; margin: 0 auto; width: 100%;">
    <div class="page-header" style="margin-bottom: 2rem;">
        <div>
            <h1 style="font-size: 1.75rem; margin: 0; display: flex; align-items: center; gap: 10px;">
                <i data-lucide="user-cog" style="width: 28px; color: var(--primary);"></i> Account Settings
            </h1>
            <p style="color: var(--text-muted); margin-top: 0.25rem;">Manage your profile identity and security credentials.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); border-radius: 8px; margin-bottom: 1.5rem;">
            <i data-lucide="check-circle" style="width: 16px; margin-right: 8px; vertical-align: middle;"></i>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="padding: 1rem; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); border-radius: 8px; margin-bottom: 1.5rem;">
            <i data-lucide="alert-circle" style="width: 16px; margin-right: 8px; vertical-align: middle;"></i>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Profile Section -->
    <div class="card" style="padding: 2rem; margin-bottom: 2rem;">
        <h3 style="margin-bottom: 1.5rem; font-size: 1.125rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">Identity</h3>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="update_profile" value="1">
            <div class="input-group">
                <label>Access Username</label>
                <input type="text" class="input-control" value="<?php echo htmlspecialchars($user_data['username']); ?>" disabled style="background: var(--surface-light); opacity: 0.7; cursor: not-allowed;">
                <small style="color: var(--text-muted); margin-top: 5px; display: block;">Username cannot be changed for security reasons.</small>
            </div>
            
            <div class="input-group">
                <label>Email Contact</label>
                <input type="email" name="email" class="input-control" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" required placeholder="you@domain.com">
                <small style="color: var(--text-muted); margin-top: 5px; display: block;">Used for automated backup confirmations and alerts.</small>
            </div>

            <div style="margin-top: 2rem;">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">Update Profile</button>
            </div>
        </form>
    </div>

    <!-- Security Section -->
    <div class="card" style="padding: 2rem;">
        <h3 style="margin-bottom: 1.5rem; font-size: 1.125rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">Security Keys</h3>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="update_password" value="1">
            <div class="input-group">
                <label>Current Security Key</label>
                <input type="password" name="current_password" class="input-control" required placeholder="••••••••">
            </div>
            
            <div class="input-group">
                <label>New Security Key</label>
                <input type="password" name="new_password" class="input-control" required placeholder="Min 8 characters">
            </div>

            <div class="input-group">
                <label>Confirm New Key</label>
                <input type="password" name="confirm_password" class="input-control" required placeholder="Repeat new key">
            </div>

            <div style="margin-top: 2.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="index" class="btn" style="flex: 1; min-width: 120px; justify-content: center; background: var(--surface-light); border: 1px solid var(--border);">Back to Home</a>
                <button type="submit" class="btn btn-primary" style="flex: 2; min-width: 200px; justify-content: center;">Authorize Password Update</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
