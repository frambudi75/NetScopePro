<?php
/**
 * IPManager Pro - About Page
 * Application info, developer details, and support links.
 */

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/updater.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
Updater::check(); // Check for updates (cached 24h)

// Pull some live stats for display
$total_subnets  = $db->query("SELECT COUNT(*) FROM subnets")->fetchColumn();
$total_devices  = $db->query("SELECT COUNT(*) FROM ip_addresses")->fetchColumn();
$total_switches = $db->query("SELECT COUNT(*) FROM switches")->fetchColumn();
$total_users    = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Netwatch Stats
try {
    $total_netwatch = $db->query("SELECT COUNT(*) FROM netwatch")->fetchColumn() ?: 0;
} catch (Exception $e) {
    $total_netwatch = 0;
}

define('APP_AUTHOR', 'Habib Frambudi');
define('APP_AUTHOR_EMAIL', 'habibframbudi@gmail.com');
define('APP_GITHUB', GITHUB_URL);
define('APP_SAWERIA', 'https://saweria.co/Habibframbudi');
define('APP_PAYPAL', 'https://paypal.me/habibframbudi');

$page_title = 'About';
include 'includes/header.php';
?>

<style>
    .about-main-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .about-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .support-btn-group {
        display: flex;
        gap: 1rem;
        justify-content: center;
        flex-wrap: wrap;
    }
    .changelog-item {
        display: flex;
        gap: 1.25rem;
        padding: 1.25rem 0;
        border-bottom: 1px solid var(--border);
    }
    
    @media (max-width: 900px) {
        .about-main-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 640px) {
        .changelog-item {
            flex-direction: column;
            gap: 0.75rem;
        }
        .changelog-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .changelog-meta span[style*="block"] {
            display: inline !important;
            margin-top: 0 !important;
            margin-left: 10px;
        }
    }
</style>

<!-- Hero Section -->
<div style="text-align: center; padding: 2rem 1rem 3rem; position: relative; overflow: hidden;">
    <div style="position: absolute; inset: 0; background: radial-gradient(ellipse at 50% 0%, rgba(99,102,241,0.15) 0%, transparent 70%); pointer-events: none;"></div>
    
    <?php if (Updater::isUpdateAvailable()): ?>
    <div style="max-width: 650px; margin: 0 auto 2.5rem; background: rgba(99,102,241,0.05); border: 1px solid rgba(99,102,241,0.3); border-radius: 16px; padding: 1.5rem; display: flex; align-items: center; gap: 1.5rem; text-align: left; animation: slideIn 0.5s ease-out; flex-wrap: wrap;">
        <div style="background: var(--primary); color: white; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="cloud-download"></i>
        </div>
        <div style="flex: 1; min-width: 200px;">
            <div style="font-weight: 700; color: white;">v<?php echo Updater::getLatestVersion(); ?> Available</div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">Improved stability and new dashboard widgets are ready.</div>
        </div>
        <a href="<?php echo Updater::getUpdateUrl(); ?>" target="_blank" class="btn btn-primary" style="font-size: 0.75rem; padding: 10px 18px;">
            Update Now
        </a>
    </div>
    <style>@keyframes slideIn { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }</style>
    <?php endif; ?>

    <div style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 80px; background: linear-gradient(135deg, var(--primary), #8b5cf6); border-radius: 24px; margin-bottom: 1.5rem; box-shadow: 0 8px 32px rgba(99,102,241,0.4);">
        <i data-lucide="network" style="width: 40px; height: 40px; color: white;"></i>
    </div>
    <h1 style="font-size: 2.25rem; font-weight: 900; margin-bottom: 0.75rem; letter-spacing: -1px;">IPManager Pro</h1>
    <p style="color: var(--text-muted); font-size: 1rem; margin-bottom: 2rem; max-width: 600px; margin-left: auto; margin-right: auto;">Premium Enterprise IP Address Management for Modern Networks.</p>
    
    <div style="display: inline-flex; gap: 0.75rem; flex-wrap: wrap; justify-content: center;">
        <span style="background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.2); color: var(--primary); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700;">v<?php echo APP_VERSION; ?></span>
        <span style="background: rgba(255,255,255,0.05); border: 1px solid var(--border); color: var(--text-muted); padding: 6px 14px; border-radius: 20px; font-size: 0.75rem;">MIT License</span>
    </div>
</div>

<!-- Live Stats -->
<div class="about-stats-grid">
    <?php
    $stats = [
        ['icon' => 'layers',  'value' => $total_subnets,  'label' => 'Subnets',  'color' => 'var(--primary)'],
        ['icon' => 'monitor', 'value' => $total_devices,  'label' => 'Devices',  'color' => 'var(--success)'],
        ['icon' => 'server',  'value' => $total_switches, 'label' => 'Switches', 'color' => 'var(--warning)'],
        ['icon' => 'eye',     'value' => $total_netwatch, 'label' => 'Netwatch', 'color' => '#f59e0b'],
        ['icon' => 'users',   'value' => $total_users,    'label' => 'Users',    'color' => '#8b5cf6'],
    ];
    foreach ($stats as $s): ?>
    <div class="card" style="text-align: center; padding: 1.5rem;">
        <i data-lucide="<?php echo $s['icon']; ?>" style="width: 24px; height: 24px; color: <?php echo $s['color']; ?>; margin-bottom: 1rem;"></i>
        <div style="font-size: 2rem; font-weight: 800; color: white;"><?php echo number_format((int)$s['value']); ?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo $s['label']; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Main Grid -->
<div class="about-main-grid">

    <!-- Developer Card -->
    <div class="card">
        <h3 style="font-size: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
            <i data-lucide="code-2" style="width: 18px; color: var(--primary);"></i> Credits
        </h3>
        <div style="display: flex; align-items: center; gap: 1.25rem; margin-bottom: 2rem;">
            <img src="https://github.com/frambudi75.png" 
                 style="width: 64px; height: 64px; border-radius: 16px; object-fit: cover; border: 2px solid var(--border); flex-shrink: 0;"
                 alt="Habib Frambudi">
            <div>
                <div style="font-size: 1.125rem; font-weight: 700; color: white;"><?php echo APP_AUTHOR; ?></div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Lead Developer & UI Designer</div>
                <div style="font-size: 0.8rem; margin-top: 4px;">
                    <a href="mailto:<?php echo APP_AUTHOR_EMAIL; ?>" style="color: var(--primary); text-decoration: none;"><?php echo APP_AUTHOR_EMAIL; ?></a>
                </div>
            </div>
        </div>
        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
            <a href="<?php echo APP_GITHUB; ?>" target="_blank" class="btn" style="background: var(--surface-light); justify-content: flex-start; border: 1px solid var(--border);">
                <i data-lucide="github" style="width: 16px;"></i> Repository GitHub
            </a>
        </div>
    </div>

    <!-- Tech Stack Card -->
    <div class="card">
        <h3 style="font-size: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
            <i data-lucide="cpu" style="width: 18px; color: var(--primary);"></i> Technology
        </h3>
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <?php
            $stack = ['PHP 8.2', 'MariaDB 10.11', 'Redis 7', 'Apache 2.4', 'Chart.js 4', 'Mermaid.js', 'Lucide Icons', 'SNMP v2c', 'ICMP Ping', 'SSE', 'Docker'];
            foreach ($stack as $t): ?>
            <span style="padding: 6px 14px; background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 8px; font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">
                <?php echo $t; ?>
            </span>
            <?php endforeach; ?>
        </div>
        <p style="margin-top: 1.5rem; font-size: 0.8rem; color: var(--text-muted); line-height: 1.6;">
            Built with modern standards, prioritizing performance and security. No heavy dependencies or legacy bloat.
        </p>
    </div>
</div>

<!-- Support Section -->
<div class="card" style="margin-bottom: 2rem; background: linear-gradient(135deg, rgba(99,102,241,0.08) 0%, rgba(139,92,246,0.05) 100%); border: 1px solid rgba(99,102,241,0.2); text-align: center; padding: 2.5rem 1.5rem;">
    <div style="font-size: 2rem; margin-bottom: 1rem;">☕</div>
    <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 0.5rem;">Support the Developer</h3>
    <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 2rem; max-width: 500px; margin-left: auto; margin-right: auto; line-height: 1.6;">
        IPManager Pro is open source. If it saves you time, consider buying me a coffee to support future updates.
    </p>
    <div class="support-btn-group">
        <a href="<?php echo APP_SAWERIA; ?>" target="_blank" class="btn btn-primary" style="padding: 12px 24px; font-weight: 700;">☕ Saweria (IDR)</a>
        <a href="<?php echo APP_PAYPAL; ?>" target="_blank" class="btn btn-secondary" style="padding: 12px 24px; font-weight: 700;">💳 PayPal (USD)</a>
    </div>
</div>

<!-- Changelog Preview -->
<div class="card">
    <h3 style="font-size: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
        <i data-lucide="history" style="width: 18px; color: var(--primary);"></i> Recent Updates
    </h3>
    <div style="border-top: 1px solid var(--border);">
    <?php
    require_once 'includes/version.php';
    foreach (array_slice($versions, 0, 3) as $v): ?>
    <div class="changelog-item">
        <div class="changelog-meta" style="min-width: 120px;">
            <span style="display: block; background: rgba(99,102,241,0.1); color: var(--primary); font-weight: 800; font-size: 0.75rem; padding: 4px 10px; border-radius: 6px; width: fit-content;">v<?php echo $v['ver']; ?></span>
            <span style="display: block; font-size: 0.7rem; color: var(--text-muted); margin-top: 6px;"><?php echo date('d M Y', strtotime($v['date'])); ?></span>
        </div>
        <ul style="margin: 0; padding: 0 0 0 1rem; flex: 1; color: var(--text-muted); font-size: 0.875rem;">
            <?php foreach ($v['changes'] as $c): ?>
            <li style="margin-bottom: 4px;"><?php echo htmlspecialchars($c); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
    </div>
    <div style="text-align: center; margin-top: 1.5rem;">
        <a href="<?php echo APP_GITHUB; ?>/blob/main/CHANGELOG.md" target="_blank" style="font-size: 0.8rem; color: var(--primary); text-decoration: none; font-weight: 600;">View full release notes on GitHub →</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
