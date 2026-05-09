<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$page_title = 'Network Toolbox';
include 'includes/header.php';

$output = '';
$target = $_POST['target'] ?? '';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($target)) {
    $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    // Sanitize target (must be IP or domain)
    if (filter_var($target, FILTER_VALIDATE_IP) || preg_match('/^[a-zA-Z0-9\.\-]+$/', $target)) {
        if ($action === 'ping') {
            $cmd = $is_windows ? "ping -n 4 " . escapeshellarg($target) : "ping -c 4 " . escapeshellarg($target);
            $output = shell_exec($cmd);
        } elseif ($action === 'trace') {
            $cmd = $is_windows ? "tracert " . escapeshellarg($target) : "traceroute " . escapeshellarg($target);
            $output = shell_exec($cmd . " 2>&1");
        } elseif ($action === 'oui') {
            // OUI Lookup via MacVendors (Fast)
            $mac = trim($target);
            $url = "https://api.macvendors.com/" . urlencode($mac);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($status == 200 && !empty($result)) {
                $output = "MAC: $mac\nVendor: $result";
            } else {
                $output = "MAC: $mac\nVendor Information Not Found (HTTP $status).";
            }
        }
    } else {
        $output = "Error: Invalid target format.";
    }
}
?>

<div class="grid-side-detail">
    <!-- Tools Sidebar/Selector -->
    <div class="card">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
            <div style="background: rgba(59, 130, 246, 0.1); padding: 8px; border-radius: 50%; color: var(--primary);">
                <i data-lucide="wrench" style="width: 20px;"></i>
            </div>
            <h3 style="font-size: 1.125rem;">Network Tools</h3>
        </div>
        <form action="" method="POST">
            <div class="input-group">
                <label>Target Host (IP / MAC / Domain)</label>
                <input type="text" name="target" value="<?php echo htmlspecialchars($target); ?>" class="input-control" placeholder="e.g. 192.168.1.1" required>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 0.8rem; margin-top: 1.5rem;">
                <?php 
                    $active_action = $_POST['action'] ?? 'ping'; 
                ?>
                <button type="submit" name="action" value="ping" class="btn <?php echo $active_action === 'ping' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'ping' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="radio" style="width: 16px;"></i> Ping Utility
                </button>
                <button type="submit" name="action" value="trace" class="btn <?php echo $active_action === 'trace' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'trace' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="git-merge" style="width: 16px;"></i> Traceroute
                </button>
                <button type="submit" name="action" value="oui" class="btn <?php echo $active_action === 'oui' ? 'btn-primary' : ''; ?>" style="justify-content: flex-start; <?php echo $active_action !== 'oui' ? 'background: var(--surface-light); color: var(--text-muted);' : ''; ?>">
                    <i data-lucide="search" style="width: 16px;"></i> OUI Lookup (MAC)
                </button>
            </div>
        </form>
    </div>

    <!-- Output Area -->
    <div style="min-width: 0;">
        <?php if (!empty($output)): ?>
            <div class="card" style="background: rgba(0,0,0,0.4); border-color: var(--border); min-height: 400px; display: flex; flex-direction: column; overflow: hidden; backdrop-filter: blur(4px);">
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 1rem; margin-bottom: 1rem;">
                    <h3 style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="terminal" style="width: 14px;"></i> Terminal Output
                    </h3>
                    <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo date('H:i:s'); ?></span>
                </div>
                <div class="table-responsive" style="flex: 1;">
                    <pre style="color: #60a5fa; font-family: monospace; font-size: 0.875rem; line-height: 1.6; white-space: pre-wrap; word-break: break-all;"><?php echo htmlspecialchars($output); ?></pre>
                </div>
            </div>
        <?php else: ?>
            <div class="card" style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; border-style: dashed; opacity: 0.5;">
                <div style="background: var(--surface-light); padding: 1.5rem; border-radius: 50%; margin-bottom: 1.5rem;">
                    <i data-lucide="terminal" style="width: 48px; height: 48px; color: var(--text-muted);"></i>
                </div>
                <h3 style="color: var(--text-muted); font-size: 1.25rem;">Ready to Execute</h3>
                <p style="font-size: 0.875rem; color: var(--text-muted); max-width: 300px; text-align: center;">Select a tool and provide a target host from the panel to start diagnostics.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
