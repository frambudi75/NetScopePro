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

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Search query
$search = $_GET['search'] ?? '';

$where_clause = "";
$params = [];
if (!empty($search)) {
    $where_clause = " WHERE details LIKE ? OR action LIKE ? ";
    $params = ["%$search%", "%$search%"];
}

$stmt = $db->prepare("SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id $where_clause ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$total_logs = $db->query("SELECT COUNT(*) FROM audit_logs $where_clause")->fetchColumn();
$total_pages = ceil($total_logs / $limit);

$page_title = 'System Audit Logs';
include 'includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 style="font-size: 1.5rem;">System Audit Logs</h1>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Track all changes and administrative actions</p>
    </div>
    <form action="" method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; width: 100%; max-width: 400px; justify-content: flex-end;">
        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search logs..." class="input-control" style="flex: 1; min-width: 200px;">
        <button type="submit" class="btn btn-primary"><i data-lucide="search" style="width: 14px;"></i> Search</button>
    </form>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border); background: rgba(255,255,255,0.02);">
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem; width: 180px;">Timestamp</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem; width: 120px;">User</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem; width: 140px;">Action</th>
                    <th style="padding: 1rem; color: var(--text-muted); font-size: 0.875rem;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="4" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                        No audit logs found.
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                <tr style="border-bottom: 1px solid var(--border); transition: background 0.2s ease;">
                    <td style="padding: 1rem; font-size: 0.875rem; color: var(--text-muted); font-family: monospace; white-space: nowrap;">
                        <?php echo date('d M Y H:i:s', strtotime($log['created_at'])); ?>
                    </td>
                    <td style="padding: 1rem;">
                        <span style="font-size: 0.875rem; font-weight: 500;"><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></span>
                    </td>
                    <td style="padding: 1rem;">
                        <?php 
                            $badge_bg = 'rgba(59, 130, 246, 0.1)';
                            $badge_color = 'var(--primary)';
                            if (strpos($log['action'], 'delete') !== false) { $badge_bg = 'rgba(239, 68, 68, 0.1)'; $badge_color = 'var(--danger)'; }
                            if (strpos($log['action'], 'add') !== false) { $badge_bg = 'rgba(16, 185, 129, 0.1)'; $badge_color = 'var(--success)'; }
                        ?>
                        <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; background: <?php echo $badge_bg; ?>; color: <?php echo $badge_color; ?>; white-space: nowrap;">
                            <?php echo str_replace('_', ' ', $log['action']); ?>
                        </span>
                    </td>
                    <td style="padding: 1rem; font-size: 0.875rem; color: var(--text);">
                        <?php echo htmlspecialchars($log['details']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($total_pages > 1): ?>
<div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 2rem; flex-wrap: wrap;">
    <?php 
    // Show only 7 pages around the current page
    $start = max(1, $page - 3);
    $end = min($total_pages, $page + 3);
    
    if ($start > 1) echo '<span style="padding: 0.5rem;">...</span>';
    
    for ($i = $start; $i <= $end; $i++): 
    ?>
    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" 
       class="btn" 
       style="padding: 0.5rem 1rem; background: <?php echo $page === $i ? 'var(--primary)' : 'var(--surface-light)'; ?>; color: <?php echo $page === $i ? 'white' : 'var(--text)'; ?>;">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
    
    <?php if ($end < $total_pages) echo '<span style="padding: 0.5rem;">...</span>'; ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
