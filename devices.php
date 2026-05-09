<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$page_title = 'Devices';

// Fetch all allocated IPs with their subnet info
$query = "
    SELECT ip.*, s.subnet, s.mask 
    FROM ip_addresses ip
    JOIN subnets s ON ip.subnet_id = s.id
    WHERE ip.state IN ('active', 'reserved', 'dhcp')
    ORDER BY ip.last_seen DESC, ip.ip_addr ASC
";
$devices = $db->query($query)->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1 style="font-size: 1.5rem;">Network Devices</h1>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="export?type=devices" class="btn btn-secondary" style="font-size: 0.875rem;">
            <i data-lucide="download" style="width: 14px;"></i> Export CSV
        </a>
        <button class="btn btn-secondary" style="font-size: 0.875rem;" onclick="window.print()">
            <i data-lucide="printer" style="width: 14px;"></i> Print PDF
        </button>
        <span class="btn btn-secondary" style="font-size: 0.875rem; opacity: 0.7;">
            Total: <?php echo count($devices); ?>
        </span>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th style="padding: 1rem; color: var(--text-muted);">IP Address</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Hostname</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Asset / Owner</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Confidence</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Subnet</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Status</th>
                    <th style="padding: 1rem; color: var(--text-muted);">Last Seen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                    <tr>
                        <td colspan="6" style="padding: 2rem; text-align: center; color: var(--text-muted);">No active devices found in the system.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($devices as $d): ?>
                        <?php
                            $confidence = isset($d['confidence_score']) ? (int)$d['confidence_score'] : 0;
                            $source_labels = [];
                            if (!empty($d['data_sources'])) {
                                foreach (explode(',', $d['data_sources']) as $src) {
                                    $src = strtoupper(trim($src));
                                    if ($src !== '') {
                                        $source_labels[] = $src;
                                    }
                                }
                            }
                            if ($confidence >= 80) {
                                $confidence_color = 'var(--success)';
                            } elseif ($confidence >= 60) {
                                $confidence_color = 'var(--primary)';
                            } elseif ($confidence >= 40) {
                                $confidence_color = 'var(--warning)';
                            } else {
                                $confidence_color = 'var(--text-muted)';
                            }
                        ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 1rem; font-family: monospace; font-weight: 600;"><?php echo $d['ip_addr']; ?></td>
                            <td style="padding: 1rem;"><?php echo $d['hostname'] ?: '<span class="text-muted">No hostname</span>'; ?></td>
                            <td style="padding: 1rem; font-size: 0.875rem;">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <div style="font-weight: 600; color: var(--primary);"><?php echo $d['asset_tag'] ?: '<span style="opacity: 0.3">-</span>'; ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $d['owner'] ?? ''; ?></div>
                                </div>
                            </td>
                            <td style="padding: 1rem; font-size: 0.875rem;">
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <span style="font-weight: 700; color: <?php echo $confidence_color; ?>;"><?php echo $confidence; ?>%</span>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <?php if (!empty($source_labels)): ?>
                                            <?php foreach ($source_labels as $label): ?>
                                                <span style="font-size: 0.65rem; padding: 2px 6px; border-radius: 999px; border: 1px solid rgba(148, 163, 184, 0.3); color: var(--text-muted);"><?php echo htmlspecialchars($label); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 1rem; font-size: 0.875rem; color: var(--text-muted);">
                                <?php echo $d['subnet']; ?>/<?php echo $d['mask']; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <span style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; background: <?php echo $d['state'] == 'active' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)'; ?>; color: <?php echo $d['state'] == 'active' ? 'var(--success)' : 'var(--warning)'; ?>; text-transform: uppercase; font-weight: 600;">
                                    <?php echo $d['state']; ?>
                                </span>
                            </td>
                            <td style="padding: 1rem; font-size: 0.875rem; color: var(--text-muted);">
                                <?php echo $d['last_seen'] ?: 'Never'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
