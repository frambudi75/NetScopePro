<?php
/**
 * NetScope Pro - System & Appliance Information
 * Hardware, runtime environment, telemetry engine specifications, and engineering credits.
 */

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/updater.php';
require_once 'includes/version.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
Updater::check(); // Check for updates (cached 24h)

// Query Managed Inventory Metrics
$total_subnets  = (int) $db->query("SELECT COUNT(*) FROM subnets")->fetchColumn();
$total_devices  = (int) $db->query("SELECT COUNT(*) FROM ip_addresses")->fetchColumn();
$total_switches = (int) $db->query("SELECT COUNT(*) FROM switches")->fetchColumn();
$total_users    = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

try {
    $total_netwatch = (int) ($db->query("SELECT COUNT(*) FROM netwatch")->fetchColumn() ?: 0);
} catch (Exception $e) {
    $total_netwatch = 0;
}

// Runtime Environment Telemetry
$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'PHP CLI / Embedded';
$php_version     = PHP_VERSION;
$php_sapi        = php_sapi_name();
$memory_limit    = ini_get('memory_limit') ?: 'N/A';
$max_exec_time   = ini_get('max_execution_time') ?: '0';
$os_family       = PHP_OS_FAMILY;
$os_kernel       = php_uname('s') . ' ' . php_uname('r');
$server_time     = date('Y-m-d H:i:s T');
$server_tz       = date_default_timezone_get();

// Database Telemetry
try {
    $db_version = $db->query("SELECT VERSION()")->fetchColumn() ?: 'Unknown';
} catch (Exception $e) {
    $db_version = 'Unknown';
}

try {
    $db_size = $db->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn() ?: '0.00';
} catch (Exception $e) {
    $db_size = 'N/A';
}

// Subsystem Status Checks
$telegram_active = false;
try {
    $tg_token = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'telegram_bot_token'")->fetchColumn();
    $telegram_active = !empty($tg_token);
} catch (Exception $e) {
    $telegram_active = false;
}

$snmp_engine = extension_loaded('snmp') ? 'PHP SNMP Extension' : 'Net-SNMP CLI / SNMPv2c';

define('APP_AUTHOR', 'Habib Frambudi');
define('APP_AUTHOR_EMAIL', 'habibframbudi@gmail.com');
define('APP_GITHUB', GITHUB_URL);
define('APP_SAWERIA', 'https://saweria.co/Habibframbudi');
define('APP_PAYPAL', 'https://paypal.me/habibframbudi');
define('APP_QRIS_IMG', 'assets/img/qris_dana.png');

$page_title = 'About';
include 'includes/header.php';
?>

<style>
    .sys-header-box {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 1.25rem;
    }
    .sys-identity {
        display: flex;
        align-items: center;
        gap: 1.25rem;
    }
    .sys-logo-badge {
        width: 52px;
        height: 52px;
        background: var(--surface-light);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--primary);
        flex-shrink: 0;
    }
    .sys-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .sys-subtext {
        color: var(--text-muted);
        font-size: 0.875rem;
        line-height: 1.4;
    }
    .sys-meta-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }
    .sys-tag {
        font-size: 0.75rem;
        font-family: 'JetBrains Mono', monospace;
        padding: 2px 8px;
        background: var(--surface-light);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        color: var(--text-muted);
    }
    .sys-tag.tag-primary {
        background: var(--brand-soft);
        border-color: rgba(88, 166, 255, 0.3);
        color: var(--primary);
        font-weight: 600;
    }
    .sys-tag.tag-success {
        background: var(--success-soft);
        border-color: rgba(63, 185, 80, 0.3);
        color: var(--success);
        font-weight: 600;
    }

    /* Update Callout Box */
    .sys-update-callout {
        background: rgba(88, 166, 255, 0.08);
        border: 1px solid rgba(88, 166, 255, 0.3);
        border-radius: var(--radius);
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    /* Spec Grid & Table */
    .sys-grid-2col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    @media (max-width: 960px) {
        .sys-grid-2col {
            grid-template-columns: 1fr;
        }
    }

    .spec-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8125rem;
    }
    .spec-table tr {
        border-bottom: 1px solid var(--border);
    }
    .spec-table tr:last-child {
        border-bottom: none;
    }
    .spec-table td {
        padding: 0.625rem 0.5rem;
        vertical-align: middle;
    }
    .spec-label {
        color: var(--text-muted);
        width: 38%;
        font-weight: 500;
    }
    .spec-val {
        color: var(--text);
        font-family: 'JetBrains Mono', monospace;
    }

    /* Subsystem Status List */
    .subsystem-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .subsystem-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem;
        background: var(--surface-light);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        gap: 1rem;
    }
    .subsystem-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 0;
    }
    .subsystem-name {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text);
    }
    .subsystem-desc {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 1px;
    }

    /* Inventory KPI Row */
    .inventory-kpi-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .kpi-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    .kpi-icon {
        width: 40px;
        height: 40px;
        border-radius: var(--radius-sm);
        background: var(--surface-light);
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .kpi-num {
        font-size: 1.375rem;
        font-weight: 700;
        color: var(--text);
        font-family: 'JetBrains Mono', monospace;
        line-height: 1.2;
    }
    .kpi-label {
        font-size: 0.75rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 2px;
    }

    /* QRIS Modal */
    .qris-modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        opacity: 0;
        transition: opacity 0.2s ease;
    }
    .qris-modal-backdrop.active {
        display: flex;
        opacity: 1;
    }
    .qris-modal-content {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        width: 100%;
        max-width: 400px;
        padding: 1.5rem;
        position: relative;
        text-align: center;
    }
    .qris-img-wrapper {
        background: #ffffff;
        padding: 12px;
        border-radius: var(--radius);
        display: inline-block;
        margin: 1rem 0;
        border: 1px solid #e2e8f0;
    }
    .qris-img-wrapper img {
        max-width: 240px;
        width: 100%;
        height: auto;
        display: block;
    }

    /* Changelog Timeline */
    .changelog-row {
        display: flex;
        gap: 1.25rem;
        padding: 1rem 0;
        border-bottom: 1px solid var(--border);
    }
    .changelog-row:last-child {
        border-bottom: none;
    }
    @media (max-width: 640px) {
        .changelog-row {
            flex-direction: column;
            gap: 0.5rem;
        }
    }
</style>

<!-- Update Notice Banner (if newer GitHub release exists) -->
<?php if (Updater::isUpdateAvailable()): ?>
<div class="sys-update-callout">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <i data-lucide="info" style="width: 20px; height: 20px; color: var(--primary); flex-shrink: 0;"></i>
        <div>
            <div style="font-size: 0.875rem; font-weight: 600; color: var(--text);">Software Update Available: v<?php echo htmlspecialchars(Updater::getLatestVersion()); ?></div>
            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">A newer release is published on the upstream repository.</div>
        </div>
    </div>
    <div style="display: flex; gap: 0.5rem;">
        <a href="<?php echo APP_GITHUB; ?>/blob/main/CHANGELOG.md" target="_blank" class="btn" style="background: var(--surface-light); border: 1px solid var(--border); font-size: 0.75rem; padding: 6px 12px;">
            Release Notes
        </a>
        <a href="<?php echo Updater::getUpdateUrl(); ?>" target="_blank" class="btn btn-primary" style="font-size: 0.75rem; padding: 6px 12px;">
            <i data-lucide="download" style="width: 14px; height: 14px;"></i> Update Package
        </a>
    </div>
</div>
<?php endif; ?>

<!-- System Identity Box -->
<div class="sys-header-box">
    <div class="sys-identity">
        <div class="sys-logo-badge">
            <i data-lucide="network" style="width: 28px; height: 28px;"></i>
        </div>
        <div>
            <div class="sys-title">
                NetScope Pro
                <span class="sys-tag tag-primary">v<?php echo APP_VERSION; ?></span>
                <span class="sys-tag tag-success">STABLE</span>
            </div>
            <div class="sys-subtext">
                Network Telemetry, IP Address Management (IPAM) & L2 Switching Diagnostics Appliance
            </div>
            <div class="sys-meta-tags">
                <span class="sys-tag">Release: <?php echo APP_RELEASE_DATE; ?></span>
                <span class="sys-tag">Branch: main</span>
                <span class="sys-tag">License: MIT Open Source</span>
                <span class="sys-tag">Timezone: <?php echo htmlspecialchars($server_tz); ?></span>
            </div>
        </div>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="<?php echo APP_GITHUB; ?>" target="_blank" class="btn" style="background: var(--surface-light); border: 1px solid var(--border); font-size: 0.8125rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"/><path d="M9 18c-4.51 2-5-2-7-2"/></svg>
            GitHub Repo
        </a>
        <a href="<?php echo APP_GITHUB; ?>/blob/main/CHANGELOG.md" target="_blank" class="btn" style="background: var(--surface-light); border: 1px solid var(--border); font-size: 0.8125rem;">
            <i data-lucide="file-text" style="width: 15px; height: 15px;"></i> Changelog
        </a>
    </div>
</div>

<!-- Managed Inventory Metric Strip -->
<div class="inventory-kpi-row">
    <div class="kpi-card">
        <div class="kpi-icon" style="color: var(--primary);">
            <i data-lucide="layers" style="width: 20px; height: 20px;"></i>
        </div>
        <div>
            <div class="kpi-num"><?php echo number_format($total_subnets); ?></div>
            <div class="kpi-label">Subnets</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon" style="color: var(--success);">
            <i data-lucide="monitor" style="width: 20px; height: 20px;"></i>
        </div>
        <div>
            <div class="kpi-num"><?php echo number_format($total_devices); ?></div>
            <div class="kpi-label">Monitored IPs</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon" style="color: var(--warning);">
            <i data-lucide="server" style="width: 20px; height: 20px;"></i>
        </div>
        <div>
            <div class="kpi-num"><?php echo number_format($total_switches); ?></div>
            <div class="kpi-label">Managed Switches</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon" style="color: #60a5fa;">
            <i data-lucide="eye" style="width: 20px; height: 20px;"></i>
        </div>
        <div>
            <div class="kpi-num"><?php echo number_format($total_netwatch); ?></div>
            <div class="kpi-label">Netwatch Targets</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon" style="color: var(--text-muted);">
            <i data-lucide="users" style="width: 20px; height: 20px;"></i>
        </div>
        <div>
            <div class="kpi-num"><?php echo number_format($total_users); ?></div>
            <div class="kpi-label">Operator Accounts</div>
        </div>
    </div>
</div>

<!-- Technical Specifications & Subsystems Grid -->
<div class="sys-grid-2col">

    <!-- Runtime Environment Spec Sheet -->
    <div class="card">
        <div style="font-size: 0.9375rem; font-weight: 700; color: var(--text); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border);">
            <i data-lucide="terminal" style="width: 17px; height: 17px; color: var(--primary);"></i>
            Host & Runtime Environment
        </div>
        <table class="spec-table">
            <tbody>
                <tr>
                    <td class="spec-label">Operating System</td>
                    <td class="spec-val"><?php echo htmlspecialchars($os_kernel); ?> (<?php echo htmlspecialchars($os_family); ?>)</td>
                </tr>
                <tr>
                    <td class="spec-label">Web Server</td>
                    <td class="spec-val"><?php echo htmlspecialchars($server_software); ?></td>
                </tr>
                <tr>
                    <td class="spec-label">PHP Runtime</td>
                    <td class="spec-val"><?php echo htmlspecialchars($php_version); ?> (SAPI: <?php echo htmlspecialchars($php_sapi); ?>)</td>
                </tr>
                <tr>
                    <td class="spec-label">PHP Memory Limit</td>
                    <td class="spec-val"><?php echo htmlspecialchars($memory_limit); ?> (Timeout: <?php echo htmlspecialchars($max_exec_time); ?>s)</td>
                </tr>
                <tr>
                    <td class="spec-label">Database Server</td>
                    <td class="spec-val"><?php echo htmlspecialchars($db_version); ?></td>
                </tr>
                <tr>
                    <td class="spec-label">Database Footprint</td>
                    <td class="spec-val"><?php echo htmlspecialchars($db_size); ?> MB</td>
                </tr>
                <tr>
                    <td class="spec-label">SNMP Subsystem</td>
                    <td class="spec-val"><?php echo htmlspecialchars($snmp_engine); ?></td>
                </tr>
                <tr>
                    <td class="spec-label">Server Timestamp</td>
                    <td class="spec-val"><?php echo htmlspecialchars($server_time); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Core Telemetry Engines & Capabilities Matrix -->
    <div class="card">
        <div style="font-size: 0.9375rem; font-weight: 700; color: var(--text); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border);">
            <i data-lucide="activity" style="width: 17px; height: 17px; color: var(--primary);"></i>
            Core Telemetry Subsystems
        </div>
        <div class="subsystem-list">
            <div class="subsystem-item">
                <div class="subsystem-info">
                    <i data-lucide="repeat" style="width: 18px; height: 18px; color: var(--primary); flex-shrink: 0;"></i>
                    <div>
                        <div class="subsystem-name">L2 Loop & STP Thrashing Monitor</div>
                        <div class="subsystem-desc">dot1dStp MIB discovery & Port A &harr; Port B pair flapping analysis</div>
                    </div>
                </div>
                <span class="sys-tag tag-success">ACTIVE</span>
            </div>

            <div class="subsystem-item">
                <div class="subsystem-info">
                    <i data-lucide="alert-triangle" style="width: 18px; height: 18px; color: var(--danger); flex-shrink: 0;"></i>
                    <div>
                        <div class="subsystem-name">IP Conflict & Collision Engine</div>
                        <div class="subsystem-desc">Sequential 3-cycle ARP/MAC stability & multi-OS TTL fingerprinting</div>
                    </div>
                </div>
                <span class="sys-tag tag-success">ACTIVE</span>
            </div>

            <div class="subsystem-item">
                <div class="subsystem-info">
                    <i data-lucide="gauge" style="width: 18px; height: 18px; color: var(--warning); flex-shrink: 0;"></i>
                    <div>
                        <div class="subsystem-name">Switch Poller & SFP DDM Transceiver</div>
                        <div class="subsystem-desc">SNMP 64-bit HC counters, throughput calculation & optical power metrics</div>
                    </div>
                </div>
                <span class="sys-tag tag-success">ACTIVE</span>
            </div>

            <div class="subsystem-item">
                <div class="subsystem-info">
                    <i data-lucide="radio" style="width: 18px; height: 18px; color: #60a5fa; flex-shrink: 0;"></i>
                    <div>
                        <div class="subsystem-name">Netwatch ICMP Ping Daemon</div>
                        <div class="subsystem-desc">High-frequency host availability, micro-latency & state transitions</div>
                    </div>
                </div>
                <span class="sys-tag tag-success">ACTIVE</span>
            </div>

            <div class="subsystem-item">
                <div class="subsystem-info">
                    <i data-lucide="send" style="width: 18px; height: 18px; color: var(--text-muted); flex-shrink: 0;"></i>
                    <div>
                        <div class="subsystem-name">Telegram NOC Alert Dispatcher</div>
                        <div class="subsystem-desc">Real-time incident dispatches with anti-spam cooldown throttling</div>
                    </div>
                </div>
                <?php if ($telegram_active): ?>
                <span class="sys-tag tag-success">CONFIGURED</span>
                <?php else: ?>
                <span class="sys-tag">STANDBY</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- Lower Grid: Maintainer & Changelog -->
<div class="sys-grid-2col">

    <!-- Maintainer & Community Support -->
    <div class="card">
        <div style="font-size: 0.9375rem; font-weight: 700; color: var(--text); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border);">
            <i data-lucide="user-check" style="width: 17px; height: 17px; color: var(--primary);"></i>
            Project Maintainer & Support
        </div>

        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <img src="https://github.com/frambudi75.png" 
                 style="width: 52px; height: 52px; border-radius: var(--radius); object-fit: cover; border: 1px solid var(--border); flex-shrink: 0;"
                 alt="Habib Frambudi">
            <div>
                <div style="font-size: 1rem; font-weight: 700; color: var(--text);"><?php echo APP_AUTHOR; ?></div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Lead Developer & Network Systems Engineer</div>
                <div style="font-size: 0.75rem; margin-top: 3px;">
                    <a href="mailto:<?php echo APP_AUTHOR_EMAIL; ?>" style="color: var(--primary); text-decoration: none;"><?php echo APP_AUTHOR_EMAIL; ?></a>
                </div>
            </div>
        </div>

        <div style="font-size: 0.8125rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 1.25rem; padding: 0.75rem; background: var(--surface-light); border: 1px solid var(--border); border-radius: var(--radius-sm);">
            NetScope Pro is open-source software under the MIT License. If it assists your network operations, community contributions directly support hardware lab testing and continued development.
        </div>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="<?php echo APP_SAWERIA; ?>" target="_blank" class="btn" style="background: var(--surface-light); border: 1px solid var(--border); font-size: 0.8125rem;">
                ☕ Saweria (IDR)
            </a>
            <a href="<?php echo APP_PAYPAL; ?>" target="_blank" class="btn" style="background: var(--surface-light); border: 1px solid var(--border); font-size: 0.8125rem;">
                💳 PayPal (USD)
            </a>
            <button type="button" onclick="openQrisModal()" class="btn" style="background: var(--surface-light); border: 1px solid var(--border); font-size: 0.8125rem;">
                <i data-lucide="qr-code" style="width: 14px; height: 14px; color: var(--success);"></i> QRIS / DANA
            </button>
        </div>
    </div>

    <!-- Kernel & Release History -->
    <div class="card">
        <div style="font-size: 0.9375rem; font-weight: 700; color: var(--text); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border);">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="history" style="width: 17px; height: 17px; color: var(--primary);"></i>
                Recent Release Log
            </div>
            <a href="<?php echo APP_GITHUB; ?>/blob/main/CHANGELOG.md" target="_blank" style="font-size: 0.75rem; color: var(--primary); text-decoration: none; font-weight: 500;">
                Full Changelog &rarr;
            </a>
        </div>

        <div>
            <?php foreach (array_slice($versions, 0, 3) as $v): ?>
            <div class="changelog-row">
                <div style="min-width: 100px;">
                    <span class="sys-tag tag-primary">v<?php echo htmlspecialchars($v['ver']); ?></span>
                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px; font-family: 'JetBrains Mono', monospace;">
                        <?php echo date('d M Y', strtotime($v['date'])); ?>
                    </div>
                </div>
                <ul style="margin: 0; padding-left: 1.1rem; flex: 1; color: var(--text-muted); font-size: 0.8125rem; line-height: 1.5;">
                    <?php foreach ($v['changes'] as $c): ?>
                    <li style="margin-bottom: 3px;"><?php echo htmlspecialchars($c); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- QRIS DANA Modal Dialog -->
<div id="qrisModal" class="qris-modal-backdrop" onclick="handleQrisBackdropClick(event)">
    <div class="qris-modal-content">
        <button type="button" onclick="closeQrisModal()" style="position: absolute; top: 1rem; right: 1rem; background: var(--surface-light); border: 1px solid var(--border); color: var(--text-muted); width: 30px; height: 30px; border-radius: var(--radius-sm); cursor: pointer; display: flex; align-items: center; justify-content: center;">
            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
        </button>
        
        <div style="font-size: 1rem; font-weight: 700; color: var(--text); margin-bottom: 0.25rem;">QRIS / DANA Community Support</div>
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.75rem;">Scan using any Indonesian banking or e-wallet application</div>
        
        <div class="qris-img-wrapper">
            <img src="<?php echo APP_QRIS_IMG; ?>" alt="QRIS DANA Habib Frambudi" loading="lazy">
        </div>
        
        <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 0.35rem; margin-bottom: 1rem;">
            <span class="sys-tag tag-success">QRIS Standar</span>
            <span class="sys-tag">DANA</span>
            <span class="sys-tag">GoPay</span>
            <span class="sys-tag">OVO</span>
            <span class="sys-tag">BCA / Mandiri / BRI</span>
        </div>
        
        <div style="display: flex; gap: 0.5rem; justify-content: center;">
            <a href="<?php echo APP_QRIS_IMG; ?>" download="QRIS_DANA_HabibFrambudi.png" class="btn" style="background: var(--surface-light); border: 1px solid var(--border); font-size: 0.75rem; padding: 6px 12px; color: var(--text);">
                <i data-lucide="download" style="width: 14px; height: 14px;"></i> Download QR
            </a>
            <button type="button" onclick="closeQrisModal()" class="btn" style="background: var(--surface); border: 1px solid var(--border); font-size: 0.75rem; padding: 6px 12px; color: var(--text-muted);">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function openQrisModal() {
    const m = document.getElementById('qrisModal');
    if (!m) return;
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('active'), 10);
    if (window.lucide) lucide.createIcons();
}
function closeQrisModal() {
    const m = document.getElementById('qrisModal');
    if (!m) return;
    m.classList.remove('active');
    setTimeout(() => m.style.display = 'none', 200);
}
function handleQrisBackdropClick(e) {
    if (e.target.id === 'qrisModal') {
        closeQrisModal();
    }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeQrisModal();
});
</script>

<?php include 'includes/footer.php'; ?>
