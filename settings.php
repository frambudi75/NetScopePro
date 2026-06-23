<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/notifications.php';
require_once 'includes/updater.php';

session_start();

if (!isset($_SESSION['user_id']) || !is_admin()) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$page_title = 'System Settings';
$message = '';

// Handle manual update check
if (isset($_POST['check_update'])) {
    Updater::check(true);
    $message = 'System update check completed.';
}

// Handle save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $to_save = [
        'telegram_enabled' => isset($_POST['telegram_enabled']) ? '1' : '0',
        'telegram_bot_token' => $_POST['telegram_bot_token'] ?? '',
        'telegram_chat_id' => $_POST['telegram_chat_id'] ?? '',
        'email_enabled' => isset($_POST['email_enabled']) ? '1' : '0',
        'admin_email' => $_POST['admin_email'] ?? '',
        'smtp_host' => $_POST['smtp_host'] ?? 'localhost',
        'smtp_port' => $_POST['smtp_port'] ?? '25',
        'smtp_user' => $_POST['smtp_user'] ?? '',
        'smtp_pass' => $_POST['smtp_pass'] ?? '',
        'mail_from' => $_POST['mail_from'] ?? '',
        'offline_fail_threshold' => $_POST['offline_fail_threshold'] ?? '3',
        'discord_enabled' => isset($_POST['discord_enabled']) ? '1' : '0',
        'discord_webhook_url' => $_POST['discord_webhook_url'] ?? '',
        'slack_enabled' => isset($_POST['slack_enabled']) ? '1' : '0',
        'slack_webhook_url' => $_POST['slack_webhook_url'] ?? '',
        'custom_netwatch_template' => $_POST['custom_netwatch_template'] ?? '',
        'nmap_enabled' => isset($_POST['nmap_enabled']) ? '1' : '0',
        'discovery_aggressive' => isset($_POST['discovery_aggressive']) ? '1' : '0',
        'masscan_enabled' => isset($_POST['masscan_enabled']) ? '1' : '0',
        'masscan_rate' => max(100, (int)($_POST['masscan_rate'] ?? 1000)),
        'retention_port_history' => max(0, (int)($_POST['retention_port_history'] ?? 30)),
        'retention_health_history' => max(0, (int)($_POST['retention_health_history'] ?? 30)),
        'retention_netwatch_history' => max(0, (int)($_POST['retention_netwatch_history'] ?? 30)),
        'retention_audit_logs' => max(0, (int)($_POST['retention_audit_logs'] ?? 90)),
        'retention_auto_cleanup' => isset($_POST['retention_auto_cleanup']) ? '1' : '0'
    ];

    try {
        $db->beginTransaction();
        foreach ($to_save as $key => $value) {
            $stmt = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $stmt->execute([$key, trim($value)]);
        }
        $db->commit();
        $message = 'Settings saved successfully!';
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Error saving settings: ' . $e->getMessage();
    }
}

// Handle Test Telegram
if (isset($_POST['test_telegram'])) {
    if (NotificationHelper::testTelegram()) {
        $message = 'Test Telegram message sent!';
    } else {
        $message = '❌ Failed to send Telegram message. Check your Token and Chat ID.';
    }
}

// Handle Test Email
if (isset($_POST['test_email'])) {
    if (NotificationHelper::testEmail()) {
        $message = 'Test Email sent!';
    } else {
        $message = '❌ Error sending test email. If using SSL (port 465), ensure your server supports it.';
    }
}

// Fetch current settings
$stmt = $db->query("SELECT * FROM settings");
$settings_list = $stmt->fetchAll();
$settings = [];
foreach ($settings_list as $s) {
    $settings[$s['key']] = $s['value'];
}

include 'includes/header.php';
?>

<style>
    .settings-tabs {
        display: flex;
        gap: 5px;
        margin-bottom: 2rem;
        background: var(--surface);
        padding: 5px;
        border-radius: 12px;
        display: inline-flex;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .settings-tabs::-webkit-scrollbar {
        height: 0;
    }
    .tab-btn {
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        color: var(--text-muted);
        transition: all 0.2s;
        white-space: nowrap;
    }
    .tab-btn.active {
        background: var(--primary);
        color: white;
    }
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    .update-card {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    
    @media (max-width: 640px) {
        .settings-footer {
            flex-direction: column;
        }
        .settings-footer .btn {
            width: 100%;
        }
        .grid-settings {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<div class="page-header">
    <h1 style="font-size: 1.5rem;">NetScope Configuration</h1>
</div>

<?php if ($message): ?>
    <div style="padding: 1rem; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); border-radius: 8px; margin-bottom: 1.5rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="settings-tabs">
    <div class="tab-btn active" onclick="showTab(event, 'tab-umum')"><i data-lucide="layout"></i> UMUM</div>
    <div class="tab-btn" onclick="showTab(event, 'tab-notif')"><i data-lucide="send"></i> NOTIFIKASI</div>
    <div class="tab-btn" onclick="showTab(event, 'tab-email')"><i data-lucide="mail"></i> EMAIL</div>
    <div class="tab-btn" onclick="showTab(event, 'tab-database')"><i data-lucide="database"></i> DATABASE</div>
    <div class="tab-btn" onclick="showTab(event, 'tab-system')"><i data-lucide="settings"></i> SISTEM</div>
</div>

<form method="POST" autocomplete="off">
    <!-- UMUM TAB -->
    <div id="tab-umum" class="tab-content active">
        <div class="card" style="max-width: 800px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
                <div style="background: rgba(59, 130, 246, 0.1); padding: 8px; border-radius: 50%; color: var(--primary);">
                    <i data-lucide="search" style="width: 20px;"></i>
                </div>
                <h3>Discovery Settings</h3>
            </div>
            <div class="input-group">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text);">
                    <input type="checkbox" name="nmap_enabled" value="1" <?php echo ($settings['nmap_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>> Enable Nmap OS Detection
                </label>
            </div>
            <div class="input-group">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text);">
                    <input type="checkbox" name="discovery_aggressive" value="1" <?php echo ($settings['discovery_aggressive'] ?? '1') == '1' ? 'checked' : ''; ?>> Aggressive Mode (More Ports)
                </label>
            </div>
            <div class="input-group" style="margin-top: 1.5rem; padding: 1rem; background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.15); border-radius: 8px;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text); font-weight: 600;">
                    <input type="checkbox" name="masscan_enabled" value="1" <?php echo ($settings['masscan_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>> Enable Masscan Fast Discovery
                </label>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem; line-height: 1.5;">
                    ⚡ Menggunakan <strong>masscan</strong> untuk host discovery super cepat (SYN scan stateless).<br>
                    Scan /24 dalam <2 detik vs 10-30 detik dengan metode ARP/ping biasa.<br>
                    <strong style="color: var(--warning);">⚠ Persyaratan:</strong> masscan binary terinstall + Npcap driver + Admin/Root privilege.
                </p>
            </div>
            <div class="input-group" id="masscanRateGroup" style="margin-top: 0.5rem; <?php echo ($settings['masscan_enabled'] ?? '0') == '1' ? '' : 'display:none;'; ?>">
                <label>Masscan Rate (packets/sec)</label>
                <input type="number" name="masscan_rate" class="input-control" value="<?php echo htmlspecialchars($settings['masscan_rate'] ?? '1000'); ?>" min="100" max="100000" step="100">
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem;">Rate pengiriman paket. 1000 = aman untuk jaringan lokal. 10000+ untuk backbone/segment besar. Terlalu tinggi bisa membanjiri switch.</p>
            </div>
            <div class="input-group" style="margin-top: 2rem;">
                <label>Offline Fail Threshold</label>
                <input type="number" name="offline_fail_threshold" class="input-control" value="<?php echo htmlspecialchars($settings['offline_fail_threshold'] ?? '3'); ?>" min="1" max="10">
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem;">Jumlah kegagalan scan berturut-turut sebelum IP ditandai sebagai OFFLINE.</p>
            </div>
        </div>
    </div>

    <!-- NOTIFIKASI TAB -->
    <div id="tab-notif" class="tab-content">
        <div class="card" style="max-width: 800px; border-top: 4px solid #0088cc;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
                <div style="background: rgba(0, 136, 204, 0.1); padding: 8px; border-radius: 50%; color: #0088cc;">
                    <i data-lucide="send" style="width: 20px;"></i>
                </div>
                <h3>Telegram Bot</h3>
            </div>
            <div class="input-group">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text);">
                    <input type="checkbox" name="telegram_enabled" value="1" <?php echo ($settings['telegram_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>> Activate Telegram Alerts
                </label>
            </div>
            <div class="input-group">
                <label>Bot Token</label>
                <input type="text" name="telegram_bot_token" class="input-control" value="<?php echo htmlspecialchars($settings['telegram_bot_token'] ?? ''); ?>" placeholder="123456:ABC-DEF...">
            </div>
            <div class="input-group">
                <label>Chat ID</label>
                <input type="text" name="telegram_chat_id" class="input-control" value="<?php echo htmlspecialchars($settings['telegram_chat_id'] ?? ''); ?>" placeholder="-100123456789">
            </div>
            <button type="submit" name="test_telegram" class="btn btn-secondary" style="width: 100%; margin-top: 1rem; justify-content: center;">
                <i data-lucide="zap"></i> Test Telegram Connection
            </button>
        </div>

        <!-- Webhooks Section -->
        <div class="card" style="max-width: 800px; border-top: 4px solid #5865F2; margin-top: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
                <div style="background: rgba(88, 101, 242, 0.1); padding: 8px; border-radius: 50%; color: #5865F2;">
                    <i data-lucide="webhook" style="width: 20px;"></i>
                </div>
                <h3>Discord & Slack Webhooks</h3>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div>
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text); margin-bottom: 1rem;">
                        <input type="checkbox" name="discord_enabled" value="1" <?php echo ($settings['discord_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>> Discord
                    </label>
                    <input type="text" name="discord_webhook_url" class="input-control" value="<?php echo htmlspecialchars($settings['discord_webhook_url'] ?? ''); ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
                <div>
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text); margin-bottom: 1rem;">
                        <input type="checkbox" name="slack_enabled" value="1" <?php echo ($settings['slack_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>> Slack
                    </label>
                    <input type="text" name="slack_webhook_url" class="input-control" value="<?php echo htmlspecialchars($settings['slack_webhook_url'] ?? ''); ?>" placeholder="https://hooks.slack.com/services/...">
                </div>
            </div>
        </div>

        <!-- Custom Template Section -->
        <div class="card" style="max-width: 800px; border-top: 4px solid var(--warning); margin-top: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem;">
                <div style="background: rgba(245, 158, 11, 0.1); padding: 8px; border-radius: 50%; color: var(--warning);">
                    <i data-lucide="type" style="width: 20px;"></i>
                </div>
                <h3>Netwatch Message Template</h3>
            </div>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Placeholders: <b>{name}</b>, <b>{host}</b>, <b>{status}</b>, <b>{time}</b>, <b>{duration}</b>, <b>{latency}</b>
            </p>
            <textarea name="custom_netwatch_template" class="input-control" style="min-height: 120px; font-family: 'JetBrains Mono', monospace; font-size: 0.875rem;" placeholder="Leave empty for default enterprise template..."><?php echo htmlspecialchars($settings['custom_netwatch_template'] ?? ''); ?></textarea>
        </div>
    </div>

    <!-- EMAIL TAB -->
    <div id="tab-email" class="tab-content">
        <div class="card" style="max-width: 800px; border-top: 4px solid var(--success);">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
                <div style="background: rgba(16, 185, 129, 0.1); padding: 8px; border-radius: 50%; color: var(--success);">
                    <i data-lucide="mail" style="width: 20px;"></i>
                </div>
                <h3>SMTP Email Configuration</h3>
            </div>
            
            <div class="input-group" style="margin-bottom: 2rem;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text);">
                    <input type="checkbox" name="email_enabled" value="1" <?php echo ($settings['email_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>> Activate Email Alerts
                </label>
            </div>

            <div class="input-group">
                <label>FROM EMAIL (PENGIRIM)</label>
                <input type="text" name="mail_from" class="input-control" value="<?php echo htmlspecialchars($settings['mail_from'] ?? ''); ?>" placeholder="sender@yourdomain.com">
            </div>

            <div class="grid-settings" style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                <div class="input-group">
                    <label>SMTP HOST</label>
                    <input type="text" name="smtp_host" class="input-control" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>" placeholder="mail.yourdomain.com">
                </div>
                <div class="input-group">
                    <label>SMTP PORT</label>
                    <input type="text" name="smtp_port" class="input-control" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? ''); ?>" placeholder="465">
                </div>
            </div>

            <div class="grid-settings" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="input-group">
                    <label>SMTP USER (EMAIL)</label>
                    <input type="text" name="smtp_user" class="input-control" value="<?php echo htmlspecialchars($settings['smtp_user'] ?? ''); ?>">
                </div>
                <div class="input-group">
                    <label>SMTP PASSWORD</label>
                    <input type="password" name="smtp_pass" class="input-control" value="<?php echo htmlspecialchars($settings['smtp_pass'] ?? ''); ?>">
                </div>
            </div>

            <div class="input-group">
                <label>RECEIVER EMAIL (ADMIN)</label>
                <input type="email" name="admin_email" class="input-control" value="<?php echo htmlspecialchars($settings['admin_email'] ?? 'admin@example.com'); ?>">
            </div>

            <button type="submit" name="test_email" class="btn btn-secondary" style="width: 100%; margin-top: 1rem; justify-content: center;">
                <i data-lucide="mail-check"></i> Test Email Discovery
            </button>
        </div>
    </div>

    <!-- SISTEM TAB -->
    <div id="tab-system" class="tab-content">
        <div class="card" style="max-width: 800px; border-top: 4px solid var(--primary);">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
                <div style="background: rgba(99, 102, 241, 0.1); padding: 8px; border-radius: 50%; color: var(--primary);">
                    <i data-lucide="info" style="width: 20px;"></i>
                </div>
                <h3>System Information</h3>
            </div>

            <div class="grid-settings" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                <div class="update-card" style="text-align: center; margin-bottom: 0;">
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 1px;">Versi Sekarang</div>
                    <div style="font-size: 2rem; font-weight: 800; color: white;">v<?php echo APP_VERSION; ?></div>
                </div>
                <div class="update-card" style="text-align: center; margin-bottom: 0;">
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 1px;">Versi Terbaru</div>
                    <div style="font-size: 2rem; font-weight: 800; color: var(--primary);">v<?php echo Updater::getLatestVersion(); ?></div>
                </div>
            </div>

            <div style="padding: 1.25rem; background: rgba(255,255,255,0.02); border-radius: 12px; margin-bottom: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.875rem; margin-bottom: 1rem;">
                    <span style="color: var(--text-muted);">Status Update:</span>
                    <?php if (Updater::isUpdateAvailable()): ?>
                        <span style="color: var(--warning); font-weight: 700; display: flex; align-items: center; gap: 5px;">
                            <i data-lucide="alert-triangle" style="width: 14px;"></i> Update Tersedia
                        </span>
                    <?php else: ?>
                        <span style="color: var(--success); font-weight: 700; display: flex; align-items: center; gap: 5px;">
                            <i data-lucide="check-circle" style="width: 14px;"></i> System Up to Date
                        </span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.875rem;">
                    <span style="color: var(--text-muted);">Terakhir Dicek:</span>
                    <span style="color: white; font-weight: 600;">
                        <?php 
                        $lc = Settings::get('last_update_check', 0);
                        echo $lc ? date('d M Y H:i', $lc) : 'Never'; 
                        ?>
                    </span>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 1rem;">
                <?php if (Updater::isUpdateAvailable()): ?>
                    <a href="<?php echo Updater::getUpdateUrl(); ?>" target="_blank" class="btn btn-primary" style="justify-content: center; padding: 1rem;">
                        <i data-lucide="download"></i> Download v<?php echo Updater::getLatestVersion(); ?>
                    </a>
                <?php endif; ?>

                <button type="submit" name="check_update" class="btn btn-secondary" style="justify-content: center;">
                    <i data-lucide="refresh-cw"></i> Cek Update Sekarang
                </button>
            </div>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 1rem; text-align: center;">Update dicek secara otomatis setiap 24 jam.</p>
        </div>
    </div>

    <!-- DATABASE TAB -->
    <div id="tab-database" class="tab-content">
        <?php
        // Gather table statistics
        $db_tables = [
            'switch_port_history' => ['label' => 'Port Traffic History', 'icon' => 'activity', 'color' => '#3b82f6', 'setting' => 'retention_port_history'],
            'switch_health_history' => ['label' => 'Switch Health History', 'icon' => 'heart-pulse', 'color' => '#ef4444', 'setting' => 'retention_health_history'],
            'netwatch_history' => ['label' => 'Netwatch History', 'icon' => 'radar', 'color' => '#10b981', 'setting' => 'retention_netwatch_history'],
            'audit_logs' => ['label' => 'Audit Logs', 'icon' => 'scroll-text', 'color' => '#f59e0b', 'setting' => 'retention_audit_logs'],
        ];
        $table_stats = [];
        foreach ($db_tables as $tbl => $meta) {
            try {
                $row_count = (int)$db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
                $size_info = $db->query("SELECT data_length + index_length as size FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$tbl'")->fetch();
                $size_bytes = $size_info ? (int)$size_info['size'] : 0;
                $table_stats[$tbl] = ['rows' => $row_count, 'size' => $size_bytes];
            } catch (Exception $e) {
                $table_stats[$tbl] = ['rows' => 0, 'size' => 0];
            }
        }
        $total_rows = array_sum(array_column($table_stats, 'rows'));
        $total_size = array_sum(array_column($table_stats, 'size'));
        
        function format_bytes($bytes) {
            if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
            if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
            if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
            return $bytes . ' B';
        }
        
        function health_color($rows) {
            if ($rows < 50000) return '#10b981';
            if ($rows < 500000) return '#f59e0b';
            return '#ef4444';
        }
        ?>

        <!-- Database Overview -->
        <div class="card" style="max-width: 900px; border-top: 4px solid #8b5cf6;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2rem;">
                <div style="background: rgba(139, 92, 246, 0.1); padding: 8px; border-radius: 50%; color: #8b5cf6;">
                    <i data-lucide="database" style="width: 20px;"></i>
                </div>
                <h3>Database Health Overview</h3>
            </div>

            <!-- Summary Cards -->
            <div class="grid-settings" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                <div class="update-card" style="text-align: center; margin-bottom: 0;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 1px;">Total Rows</div>
                    <div style="font-size: 1.75rem; font-weight: 800; color: <?php echo health_color($total_rows); ?>;"><?php echo number_format($total_rows); ?></div>
                </div>
                <div class="update-card" style="text-align: center; margin-bottom: 0;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 1px;">Disk Usage</div>
                    <div style="font-size: 1.75rem; font-weight: 800; color: white;"><?php echo format_bytes($total_size); ?></div>
                </div>
                <div class="update-card" style="text-align: center; margin-bottom: 0;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 1px;">Cleanup Terakhir</div>
                    <div style="font-size: 1.1rem; font-weight: 700; color: var(--text);">
                        <?php 
                        $last_cleanup = (int)Settings::get('last_db_cleanup', 0);
                        echo $last_cleanup ? date('d M Y H:i', $last_cleanup) : 'Belum pernah'; 
                        ?>
                    </div>
                </div>
            </div>

            <!-- Per-Table Stats -->
            <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 2rem;">
                <?php foreach ($db_tables as $tbl => $meta): 
                    $stats = $table_stats[$tbl];
                    $health = health_color($stats['rows']);
                    // Calculate bar width (max at 100K rows = 100%)
                    $bar_pct = min(100, ($stats['rows'] / max(1, $total_rows)) * 100);
                ?>
                <div style="padding: 1rem 1.25rem; background: rgba(255,255,255,0.02); border-radius: 10px; border: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="<?php echo $meta['icon']; ?>" style="width: 16px; color: <?php echo $meta['color']; ?>;"></i>
                            <span style="font-weight: 600; font-size: 0.875rem;"><?php echo $meta['label']; ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo format_bytes($stats['size']); ?></span>
                            <span style="font-weight: 700; font-size: 0.95rem; color: <?php echo $health; ?>;"><?php echo number_format($stats['rows']); ?> rows</span>
                        </div>
                    </div>
                    <div style="height: 4px; background: rgba(255,255,255,0.05); border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; width: <?php echo max(2, $bar_pct); ?>%; background: <?php echo $meta['color']; ?>; border-radius: 4px; transition: width 0.5s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Retention Settings -->
        <div class="card" style="max-width: 900px; border-top: 4px solid var(--primary); margin-top: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem;">
                <div style="background: rgba(88, 166, 255, 0.1); padding: 8px; border-radius: 50%; color: var(--primary);">
                    <i data-lucide="timer" style="width: 20px;"></i>
                </div>
                <h3>Data Retention Policy</h3>
            </div>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.5rem; line-height: 1.6;">
                Tentukan berapa lama data history disimpan. Data yang lebih lama dari batas retensi akan dihapus otomatis. Set <strong>0</strong> untuk menyimpan selamanya.
            </p>

            <div class="input-group" style="margin-bottom: 1.5rem;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; color: var(--text);">
                    <input type="checkbox" name="retention_auto_cleanup" value="1" <?php echo ($settings['retention_auto_cleanup'] ?? '1') == '1' ? 'checked' : ''; ?>>
                    <strong>Auto Cleanup</strong> — Jalankan cleanup otomatis saat cron berjalan
                </label>
            </div>

            <div class="grid-settings" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <?php foreach ($db_tables as $tbl => $meta): ?>
                <div class="input-group">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem;">
                        <i data-lucide="<?php echo $meta['icon']; ?>" style="width: 14px; color: <?php echo $meta['color']; ?>;"></i>
                        <?php echo $meta['label']; ?>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" name="<?php echo $meta['setting']; ?>" class="input-control" 
                            value="<?php echo htmlspecialchars($settings[$meta['setting']] ?? '30'); ?>" 
                            min="0" max="3650" style="max-width: 120px;">
                        <span style="font-size: 0.8rem; color: var(--text-muted);">hari</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="padding: 1rem; background: rgba(59, 130, 246, 0.05); border: 1px solid rgba(59, 130, 246, 0.15); border-radius: 8px; margin-bottom: 1.5rem;">
                <div style="font-size: 0.75rem; color: var(--text-muted); line-height: 1.6;">
                    💡 <strong>Rekomendasi:</strong> Port History & Health History = <strong>30 hari</strong>, Netwatch History = <strong>30 hari</strong>, Audit Logs = <strong>90 hari</strong>
                </div>
            </div>
        </div>

        <!-- Manual Cleanup -->
        <div class="card" style="max-width: 900px; border-top: 4px solid #ef4444; margin-top: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem;">
                <div style="background: rgba(239, 68, 68, 0.1); padding: 8px; border-radius: 50%; color: #ef4444;">
                    <i data-lucide="trash-2" style="width: 20px;"></i>
                </div>
                <h3>Manual Cleanup</h3>
            </div>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Jalankan pembersihan data sekarang berdasarkan retention policy di atas. Simpan pengaturan terlebih dahulu sebelum menjalankan cleanup.
            </p>
            <button type="button" id="btn-cleanup" class="btn btn-secondary" style="width: 100%; justify-content: center; padding: 1rem; border: 1px solid rgba(239, 68, 68, 0.3);" onclick="runCleanup()">
                <i data-lucide="sparkles"></i> Jalankan Cleanup Sekarang
            </button>
            <div id="cleanup-result" style="display: none; margin-top: 1.5rem;"></div>
        </div>
    </div>

    <div class="settings-footer" style="margin-top: 3rem; display: flex; justify-content: flex-end; gap: 1rem;">
        <button type="submit" name="save_settings" class="btn btn-primary" style="padding: 1rem 3rem; justify-content: center;">
            <i data-lucide="check-circle-2"></i> Simpan Semua Pengaturan
        </button>
    </div>
</form>

<script>
    // Toggle masscan rate field visibility
    const masscanCheckbox = document.querySelector('input[name="masscan_enabled"]');
    const masscanRateGroup = document.getElementById('masscanRateGroup');
    if (masscanCheckbox && masscanRateGroup) {
        masscanCheckbox.addEventListener('change', function() {
            masscanRateGroup.style.display = this.checked ? '' : 'none';
        });
    }

    function showTab(event, tabId) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        
        document.getElementById(tabId).classList.add('active');
        if(event) event.currentTarget.classList.add('active');
    }

    function runCleanup() {
        const btn = document.getElementById('btn-cleanup');
        const resultDiv = document.getElementById('cleanup-result');
        
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin"></i> Menjalankan cleanup...';
        if (typeof lucide !== 'undefined') lucide.createIcons();
        resultDiv.style.display = 'none';
        
        fetch('cron_cleanup', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="sparkles"></i> Jalankan Cleanup Sekarang';
            if (typeof lucide !== 'undefined') lucide.createIcons();
            
            resultDiv.style.display = 'block';
            
            if (data.success) {
                let html = '<div style="padding: 1rem; background: rgba(16, 185, 129, 0.08); border: 1px solid var(--success); border-radius: 10px;">';
                html += '<div style="font-weight: 700; color: var(--success); margin-bottom: 1rem; display: flex; align-items: center; gap: 8px;"><i data-lucide="check-circle" style="width: 16px;"></i> Cleanup Selesai</div>';
                html += '<div style="font-size: 0.85rem; color: var(--text); margin-bottom: 1rem;">Total dihapus: <strong>' + data.total_deleted.toLocaleString() + ' rows</strong></div>';
                html += '<div style="display: flex; flex-direction: column; gap: 6px;">';
                for (const [table, info] of Object.entries(data.tables)) {
                    const statusIcon = info.status === 'cleaned' ? '🧹' : (info.status === 'clean' ? '✨' : '⏭');
                    const statusColor = info.status === 'cleaned' ? 'var(--warning)' : 'var(--text-muted)';
                    html += '<div style="display: flex; justify-content: space-between; font-size: 0.8rem; padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.03);">';
                    html += '<span>' + statusIcon + ' ' + table + '</span>';
                    html += '<span style="color: ' + statusColor + ';">';
                    if (info.deleted > 0) {
                        html += '-' + info.deleted.toLocaleString() + ' rows';
                    } else {
                        html += info.status === 'skipped' ? 'disabled' : 'no change';
                    }
                    html += ' (' + info.remaining.toLocaleString() + ' remaining)</span>';
                    html += '</div>';
                }
                html += '</div></div>';
                
                if (data.total_deleted > 0) {
                    html += '<p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.75rem; text-align: center;">Refresh halaman untuk melihat statistik terbaru.</p>';
                }
                
                resultDiv.innerHTML = html;
            } else {
                resultDiv.innerHTML = '<div style="padding: 1rem; background: rgba(239, 68, 68, 0.08); border: 1px solid var(--danger); color: var(--danger); border-radius: 10px;">Cleanup gagal. Silakan cek log server.</div>';
            }
            if (typeof lucide !== 'undefined') lucide.createIcons();
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="sparkles"></i> Jalankan Cleanup Sekarang';
            if (typeof lucide !== 'undefined') lucide.createIcons();
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style="padding: 1rem; background: rgba(239, 68, 68, 0.08); border: 1px solid var(--danger); color: var(--danger); border-radius: 10px;">Network error: ' + err.message + '</div>';
        });
    }
</script>

<style>
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .spin { animation: spin 1s linear infinite; }
</style>

<?php include 'includes/footer.php'; ?>
