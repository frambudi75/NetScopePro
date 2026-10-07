<?php
/**
 * Unified Notifications Handler (Telegram & Email)
 */

class NotificationHelper {
    /**
     * Handle both global activation ping and local admin welcome
     */
    public static function handleActivation() {
        try {
            // 1. Global Developer Notification (One-time)
            if (!Settings::get('activation_ping_sent')) {
                $subject = "🚀 [ACTIVATION] " . APP_NAME . " Installed - " . $_SERVER['HTTP_HOST'];
                $body = self::getPremiumEmailTemplate('Global Activation', 'Sistem mendeteksi instalasi baru pada server berikut:', [
                    'Host/Domain' => $_SERVER['HTTP_HOST'] ?? 'Localhost',
                    'Server IP' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
                    'PHP Version' => PHP_VERSION,
                    'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'SSL Active' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Yes' : 'No',
                    'App Version' => APP_VERSION
                ]);
                
                if (self::sendEmailWithAttachments($subject, $body, [], DEVELOPER_EMAIL)) {
                    Settings::set('activation_ping_sent', '1');
                }
            }

            // 2. Local Admin Welcome (One-time)
            if (Settings::enabled('email_enabled') && !Settings::get('welcome_email_sent')) {
                $admin_email = Settings::get('admin_email');
                if ($admin_email) {
                    $subject = "🎉 Welcome to " . APP_NAME . "!";
                    $body = self::getPremiumEmailTemplate('Welcome to ' . APP_NAME, 'Instalasi Anda telah berhasil dikonfigurasi. Berikut adalah detail sistem Anda:', [
                        'Host/Domain' => $_SERVER['HTTP_HOST'] ?? 'Localhost',
                        'App URL' => APP_URL,
                        'Version' => APP_VERSION,
                        'Date' => date('d M Y')
                    ]);
                    
                    if (self::sendEmail($subject, $body)) {
                        Settings::set('welcome_email_sent', '1');
                    }
                }
            }
        } catch (Exception $e) {
            // Silently fail to avoid blocking the app
        }
    }

    /**
     * Premium HTML Template for All Notifications
     */
    private static function getPremiumEmailTemplate($title, $lead, $details = [], $type = 'info', $link = '') {
        $colors = [
            'info' => '#58a6ff',    // NOC Blue
            'success' => '#10b981', // Emerald
            'danger' => '#ef4444',  // Red
            'warning' => '#f59e0b'  // Amber
        ];
        
        $primary = $colors[$type] ?? $colors['info'];
        $bg = "#f8fafc";
        
        $body = "<div style='background: {$bg}; padding: 40px; font-family: sans-serif; color: #334155;'>";
        $body .= "<div style='max-width: 600px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;'>";
        $body .= "<div style='background: {$primary}; padding: 30px; text-align: center; color: white;'>";
        $body .= "<h1 style='margin: 0; font-size: 24px;'>{$title}</h1>";
        $body .= "</div>";
        $body .= "<div style='padding: 30px;'>";
        $body .= "<p style='font-size: 16px; line-height: 1.6;'>{$lead}</p>";
        $body .= "<div style='background: #f1f5f9; border-radius: 12px; padding: 20px; margin: 25px 0;'>";
        $body .= "<table style='width: 100%; border-collapse: collapse;'>";
        
        foreach ($details as $label => $val) {
            $body .= "<tr>";
            $body .= "<td style='padding: 8px 0; color: #64748b; font-weight: 500; font-size: 14px;'>{$label}</td>";
            $body .= "<td style='padding: 8px 0; text-align: right; font-weight: 600; color: #1e293b; font-size: 14px;'>{$val}</td>";
            $body .= "</tr>";
        }
        
        $body .= "</table>";
        $body .= "</div>";
        
        if ($link) {
            $full_link = strpos($link, 'http') === 0 ? $link : APP_URL . $link;
            $body .= "<div style='text-align: center; margin-top: 30px;'>";
            $body .= "<a href='{$full_link}' style='display: inline-block; background: {$primary}; color: white; padding: 12px 30px; border-radius: 8px; text-decoration: none; font-weight: 600;'>View Details</a>";
            $body .= "</div>";
        }
        
        $body .= "</div>";
        $body .= "<div style='padding: 20px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; text-transform: uppercase; letter-spacing: 1px;'>";
        $body .= "Automated Notification by " . APP_NAME;
        $body .= "</div>";
        $body .= "</div>";
        $body .= "</div>";
        
        return $body;
    }

    /**
     * Check if an alert event is currently throttled (cooldown) to avoid spamming.
     */
    private static function isThrottled($key, $cooldownSeconds = 900) {
        $tmp_dir = __DIR__ . '/../tmp';
        if (!is_dir($tmp_dir)) {
            @mkdir($tmp_dir, 0755, true);
        }
        $file = $tmp_dir . '/throttle_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.lock';
        if (file_exists($file)) {
            $mtime = @filemtime($file);
            if ($mtime && (time() - $mtime < $cooldownSeconds)) {
                return true;
            }
        }
        @touch($file);
        return false;
    }

    /**
     * Reset a throttle lock file so the next alert triggers immediately.
     */
    public static function resetThrottle($key) {
        $tmp_dir = __DIR__ . '/../tmp';
        $file = $tmp_dir . '/throttle_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.lock';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Send a notification when a new device is discovered.
     */
    public static function notifyNewDevice($ip, $mac, $vendor, $hostname, $subnet_name) {
        $telegram_enabled = Settings::enabled('telegram_enabled') && (Settings::get('telegram_notify_new_device', '1') === '1');
        $email_enabled = Settings::enabled('email_enabled');
        $discord_enabled = Settings::enabled('discord_enabled');
        $slack_enabled = Settings::enabled('slack_enabled');

        if (!$telegram_enabled && !$email_enabled && !$discord_enabled && !$slack_enabled) return;

        if ($telegram_enabled) {
            $message = "🚨 <b>New Device Discovered!</b>\n\n";
            $message .= "📍 <b>Subnet:</b> " . htmlspecialchars($subnet_name) . "\n";
            $message .= "🌐 <b>IP:</b> <code>" . htmlspecialchars($ip) . "</code>\n";
            $message .= "🏷 <b>Hostname:</b> " . htmlspecialchars($hostname ?: 'Unknown') . "\n";
            $message .= "🔌 <b>MAC:</b> <code>" . htmlspecialchars($mac) . "</code>\n";
            $message .= "🏢 <b>Vendor:</b> " . htmlspecialchars($vendor ?: 'Generic') . "\n";
            $message .= "🕒 <b>Time:</b> " . date('Y-m-d H:i:s');
            self::sendTelegram($message);
        }

        if ($discord_enabled) {
            $embed = [
                'title' => '🔍 New Device Discovered',
                'description' => "Perangkat baru terdeteksi aktif pada subnet **{$subnet_name}**.",
                'color' => 0x3B82F6, // Blue
                'fields' => [
                    ['name' => '📍 Subnet', 'value' => $subnet_name, 'inline' => true],
                    ['name' => '🌐 IP Address', 'value' => "`{$ip}`", 'inline' => true],
                    ['name' => '🏷 Hostname', 'value' => $hostname ?: 'Unknown', 'inline' => true],
                    ['name' => '🔌 MAC Address', 'value' => "`{$mac}`", 'inline' => true],
                    ['name' => '🏢 Vendor', 'value' => $vendor ?: 'Generic', 'inline' => true],
                    ['name' => '🕒 Waktu', 'value' => date('Y-m-d H:i:s'), 'inline' => true]
                ],
                'footer' => ['text' => APP_NAME . ' Auto-Discovery'],
                'timestamp' => date('c')
            ];
            self::sendDiscord(null, $embed);
        }

        if ($slack_enabled) {
            $markdown = "*[ NEW DEVICE DISCOVERED ]*\n" .
                        "Subnet: {$subnet_name}\n" .
                        "IP: `{$ip}`\n" .
                        "Hostname: " . ($hostname ?: 'Unknown') . "\n" .
                        "MAC: `{$mac}`\n" .
                        "Vendor: " . ($vendor ?: 'Generic') . "\n" .
                        "Time: " . date('Y-m-d H:i:s');
            self::sendSlack($markdown);
        }

        if ($email_enabled) {
            $subject = "🚨 New Device: {$ip} ({$vendor})";
            $body = "<h2>New Device Discovered</h2>";
            $body .= "<ul>";
            $body .= "<li><b>IP:</b> {$ip}</li>";
            $body .= "<li><b>MAC:</b> {$mac}</li>";
            $body .= "<li><b>Hostname:</b> " . ($hostname ?: 'Unknown') . "</li>";
            $body .= "<li><b>Vendor:</b> {$vendor}</li>";
            $body .= "<li><b>Subnet:</b> {$subnet_name}</li>";
            $body .= "</ul>";
            self::sendEmail($subject, $body);
        }
    }

    /**
     * Send notification for an IP conflict (MAC address change or flapping).
     */
    public static function notifyConflict($ip, $old_mac, $new_mac, $subnet_name) {
        // Cooldown per IP to prevent spamming
        if (self::isThrottled('conflict_' . $ip, 900)) return;

        $telegram_enabled = Settings::enabled('telegram_enabled') && (Settings::get('telegram_notify_conflict', '1') === '1');
        $email_enabled = Settings::enabled('email_enabled');
        $discord_enabled = Settings::enabled('discord_enabled');
        $slack_enabled = Settings::enabled('slack_enabled');

        if (!$telegram_enabled && !$email_enabled && !$discord_enabled && !$slack_enabled) return;

        $time = date('Y-m-d H:i:s');
        $safe_ip = htmlspecialchars($ip);
        $safe_subnet = htmlspecialchars($subnet_name);
        $safe_old = htmlspecialchars($old_mac);
        $safe_new = htmlspecialchars($new_mac);

        if ($telegram_enabled) {
            $message = "⚠️ <b>IP Conflict Detected!</b>\n\n";
            $message .= "📍 <b>Subnet:</b> {$safe_subnet}\n";
            $message .= "🌐 <b>Target IP:</b> <code>{$safe_ip}</code>\n";
            $message .= "🛑 <b>Initial MAC:</b> <code>{$safe_old}</code>\n";
            $message .= "🚩 <b>Conflicting MAC:</b> <code>{$safe_new}</code>\n";
            $message .= "🕒 <b>Time:</b> {$time}\n\n";
            $message .= "<i>Gunakan Network Tools (IP Conflict Prober) untuk diagnosis mendalam.</i>";
            self::sendTelegram($message);
        }

        $markdown = "**[ IP CONFLICT DETECTED ]**\n";
        $markdown .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬\n";
        $markdown .= "🌐 **IP:** `{$ip}`\n";
        $markdown .= "📍 **Subnet:** {$subnet_name}\n";
        $markdown .= "🛑 **Initial MAC:** `{$old_mac}`\n";
        $markdown .= "🚩 **New MAC:** `{$new_mac}`\n";
        $markdown .= "🕒 **Waktu:** {$time}\n";
        $markdown .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬";

        if ($discord_enabled) {
            $embed = [
                'title' => '⚠️ IP Conflict Alert (ARP Clashing)',
                'description' => "Terdeteksi bentrok alamat IP / pergantian MAC cepat pada subnet **{$subnet_name}**.",
                'color' => 0xF59E0B, // Amber
                'fields' => [
                    ['name' => '🌐 Target IP', 'value' => "`{$ip}`", 'inline' => true],
                    ['name' => '📍 Subnet', 'value' => $subnet_name, 'inline' => true],
                    ['name' => '🛑 Initial MAC', 'value' => "`{$old_mac}`", 'inline' => true],
                    ['name' => '🚩 Conflicting MAC', 'value' => "`{$new_mac}`", 'inline' => true],
                    ['name' => '🕒 Waktu', 'value' => $time, 'inline' => false]
                ],
                'footer' => ['text' => APP_NAME . ' ARP Conflict Sentinel'],
                'timestamp' => date('c')
            ];
            self::sendDiscord(null, $embed);
        }
        if ($slack_enabled) self::sendSlack($markdown);

        if ($email_enabled) {
            $subject = "⚠️ IP Conflict: {$ip}";
            $body = "<h2>IP Conflict Detected</h2>";
            $body .= "<p>An IP conflict has been detected on subnet: <b>{$subnet_name}</b></p>";
            $body .= "<ul>";
            $body .= "<li><b>IP Address:</b> {$ip}</li>";
            $body .= "<li><b>Old MAC:</b> {$old_mac}</li>";
            $body .= "<li><b>New MAC:</b> {$new_mac}</li>";
            $body .= "</ul>";
            self::sendEmail($subject, $body);
        }
    }

    /**
     * Send notification for L2 Switching Loop or STP Port Blocking.
     * @param bool $force When true (new loop or changed condition), bypasses reminder throttle
     */
    public static function notifySwitchLoop($switch_name, $switch_ip, $loop_details, $blocked_ports = [], $force = false) {
        // If not forced (meaning it's an ongoing, unchanged condition), throttle reminder to once every 6 hours (21600s)
        if (!$force && self::isThrottled('loop_remind_' . $switch_ip, 21600)) return;
        if ($force) {
            // Touch reminder lock so periodic reminder doesn't fire immediately right after
            self::isThrottled('loop_remind_' . $switch_ip, 21600);
        }

        $telegram_enabled = Settings::enabled('telegram_enabled') && (Settings::get('telegram_notify_loop', '1') === '1');
        $email_enabled = Settings::enabled('email_enabled');
        $discord_enabled = Settings::enabled('discord_enabled');
        $slack_enabled = Settings::enabled('slack_enabled');

        if (!$telegram_enabled && !$email_enabled && !$discord_enabled && !$slack_enabled) return;

        $time = date('Y-m-d H:i:s');
        $safe_name = htmlspecialchars($switch_name);
        $safe_ip = htmlspecialchars($switch_ip);
        $safe_details = htmlspecialchars($loop_details);

        if ($telegram_enabled) {
            $message = "🚨 <b>CRITICAL: L2 Switching Loop Alert!</b>\n\n";
            $message .= "🏢 <b>Switch:</b> {$safe_name}\n";
            $message .= "🌐 <b>IP Address:</b> <code>{$safe_ip}</code>\n";
            $message .= "⚠️ <b>Event:</b> {$safe_details}\n";
            if (!empty($blocked_ports)) {
                $message .= "🚫 <b>Blocked Port(s):</b> <code>" . htmlspecialchars(implode(', ', $blocked_ports)) . "</code>\n";
            }
            $message .= "🕒 <b>Time:</b> {$time}\n\n";
            $message .= "<i>Tindakan: Periksa kabel loop fisik atau port downstream switch yang bersangkutan.</i>";
            self::sendTelegram($message);
        }

        $markdown = "**[ L2 SWITCHING LOOP ALERT ]**\n";
        $markdown .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬\n";
        $markdown .= "🏢 **Switch:** {$switch_name} (`{$switch_ip}`)\n";
        $markdown .= "⚠️ **Detail:** {$loop_details}\n";
        if (!empty($blocked_ports)) {
            $markdown .= "🚫 **Blocked Ports:** `" . implode(', ', $blocked_ports) . "`\n";
        }
        $markdown .= "🕒 **Waktu:** {$time}\n";
        $markdown .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬";

        if ($discord_enabled) {
            $embed = [
                'title' => '🚨 CRITICAL: L2 Switching Loop Alert!',
                'description' => "Terdeteksi badai switching loop atau port blocking pada switch infrastruktur.",
                'color' => 0xEF4444, // Red
                'fields' => [
                    ['name' => '🏢 Switch', 'value' => $switch_name, 'inline' => true],
                    ['name' => '🌐 IP Address', 'value' => "`{$switch_ip}`", 'inline' => true],
                    ['name' => '⚠️ Event Detail', 'value' => $loop_details, 'inline' => false]
                ],
                'footer' => ['text' => APP_NAME . ' Loop Detective'],
                'timestamp' => date('c')
            ];
            if (!empty($blocked_ports)) {
                $embed['fields'][] = [
                    'name' => '🚫 Blocked Port(s)',
                    'value' => '`' . implode(', ', $blocked_ports) . '`',
                    'inline' => true
                ];
            }
            $embed['fields'][] = ['name' => '🕒 Waktu', 'value' => $time, 'inline' => true];
            self::sendDiscord(null, $embed);
        }
        if ($slack_enabled) self::sendSlack($markdown);

        if ($email_enabled) {
            $subject = "🚨 CRITICAL: L2 Switching Loop on {$switch_name} ({$switch_ip})";
            $body = "<h2>L2 Switching Loop / Port Blocking Alert</h2>";
            $body .= "<p>Switch <b>{$switch_name}</b> ({$switch_ip}) has reported loop conditions:</p>";
            $body .= "<blockquote>{$loop_details}</blockquote>";
            if (!empty($blocked_ports)) {
                $body .= "<p><b>Blocked Ports:</b> " . implode(', ', $blocked_ports) . "</p>";
            }
            self::sendEmail($subject, $body);
        }
    }

    /**
     * Send notification when an L2 Switching Loop or STP Port Blocking condition has cleared.
     */
    public static function notifySwitchLoopResolved($switch_name, $switch_ip, $previous_details = '') {
        // Reset reminder throttle
        self::resetThrottle('loop_remind_' . $switch_ip);

        $telegram_enabled = Settings::enabled('telegram_enabled') && (Settings::get('telegram_notify_loop', '1') === '1');
        $email_enabled = Settings::enabled('email_enabled');
        $discord_enabled = Settings::enabled('discord_enabled');
        $slack_enabled = Settings::enabled('slack_enabled');

        if (!$telegram_enabled && !$email_enabled && !$discord_enabled && !$slack_enabled) return;

        $time = date('Y-m-d H:i:s');
        $safe_name = htmlspecialchars($switch_name);
        $safe_ip = htmlspecialchars($switch_ip);

        if ($telegram_enabled) {
            $message = "✅ <b>RESOLVED: L2 Switching Loop Cleared</b>\n\n";
            $message .= "🏢 <b>Switch:</b> {$safe_name}\n";
            $message .= "🌐 <b>IP Address:</b> <code>{$safe_ip}</code>\n";
            if ($previous_details) {
                $message .= "ℹ️ <b>Sebelumnya:</b> <i>" . htmlspecialchars($previous_details) . "</i>\n";
            }
            $message .= "🕒 <b>Time:</b> {$time}\n\n";
            $message .= "<i>Topologi switch kembali normal dan frame forwarding stabil.</i>";
            self::sendTelegram($message);
        }

        $markdown = "**[ L2 LOOP RESOLVED ]**\n";
        $markdown .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬\n";
        $markdown .= "🏢 **Switch:** {$switch_name} (`{$switch_ip}`)\n";
        $markdown .= "✅ **Status:** Topologi telah kembali normal (Loop cleared).\n";
        if ($previous_details) {
            $markdown .= "ℹ️ **Sebelumnya:** {$previous_details}\n";
        }
        $markdown .= "🕒 **Waktu:** {$time}\n";
        $markdown .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬";

        if ($discord_enabled) {
            $embed = [
                'title' => '✅ RESOLVED: L2 Switching Loop Cleared',
                'description' => "Kondisi loop pada switch telah teratasi dan topologi frame forwarding kembali normal.",
                'color' => 0x10B981, // Emerald Green
                'fields' => [
                    ['name' => '🏢 Switch', 'value' => $switch_name, 'inline' => true],
                    ['name' => '🌐 IP Address', 'value' => "`{$switch_ip}`", 'inline' => true]
                ],
                'footer' => ['text' => APP_NAME . ' Loop Detective'],
                'timestamp' => date('c')
            ];
            if (!empty($previous_details)) {
                $embed['fields'][] = [
                    'name' => 'ℹ️ Riwayat Sebelumnya',
                    'value' => $previous_details,
                    'inline' => false
                ];
            }
            $embed['fields'][] = ['name' => '🕒 Waktu', 'value' => $time, 'inline' => true];
            self::sendDiscord(null, $embed);
        }
        if ($slack_enabled) self::sendSlack($markdown);

        if ($email_enabled) {
            $subject = "✅ RESOLVED: L2 Switching Loop on {$switch_name} ({$switch_ip})";
            $body = "<h2>L2 Switching Loop Resolved</h2>";
            $body .= "<p>Switch <b>{$switch_name}</b> ({$switch_ip}) has returned to normal topology state.</p>";
            if ($previous_details) {
                $body .= "<p><b>Previous Issue:</b> " . htmlspecialchars($previous_details) . "</p>";
            }
            self::sendEmail($subject, $body);
        }
    }

    /**
     * Send notification for critical SFP Optical RX Power drop (Fiber DDM).
     */
    public static function notifySfpOpticalWarning($switch_name, $switch_ip, $port_name, $rx_power, $tx_power = null) {
        // Cooldown per port (30 minutes)
        if (self::isThrottled('sfp_' . $switch_ip . '_' . $port_name, 1800)) return;

        $telegram_enabled = Settings::enabled('telegram_enabled') && (Settings::get('telegram_notify_sfp', '1') === '1');
        $email_enabled = Settings::enabled('email_enabled');
        $discord_enabled = Settings::enabled('discord_enabled');
        $slack_enabled = Settings::enabled('slack_enabled');

        if (!$telegram_enabled && !$email_enabled && !$discord_enabled && !$slack_enabled) return;

        $time = date('Y-m-d H:i:s');
        $safe_name = htmlspecialchars($switch_name);
        $safe_ip = htmlspecialchars($switch_ip);
        $safe_port = htmlspecialchars($port_name);
        $safe_rx = htmlspecialchars($rx_power);
        $safe_tx = $tx_power !== null ? htmlspecialchars($tx_power) : null;

        if ($telegram_enabled) {
            $message = "⚠️ <b>OPTICAL WARNING: SFP Low RX Power</b>\n\n";
            $message .= "🏢 <b>Switch:</b> {$safe_name} (<code>{$safe_ip}</code>)\n";
            $message .= "🔌 <b>Port:</b> <code>{$safe_port}</code>\n";
            $message .= "📉 <b>Optical RX Power:</b> <code>{$safe_rx} dBm</code> (Critical Drop)\n";
            if ($safe_tx) {
                $message .= "📈 <b>Optical TX Power:</b> <code>{$safe_tx} dBm</code>\n";
            }
            $message .= "🕒 <b>Time:</b> {$time}\n\n";
            $message .= "<i>Peringatan: Redaman fiber optik tinggi. Periksa kabel patchcord, konektor kotor, atau bending fisik.</i>";
            self::sendTelegram($message);
        }

        $markdown = "**[ SFP OPTICAL POWER WARNING ]**\n";
        $markdown .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬\n";
        $markdown .= "🏢 **Switch:** {$switch_name} (`{$switch_ip}`)\n";
        $markdown .= "🔌 **Port:** `{$port_name}`\n";
        $markdown .= "📉 **RX Power:** `{$rx_power} dBm`\n";
        if ($tx_power) $markdown .= "📈 **TX Power:** `{$tx_power} dBm`\n";
        $markdown .= "🕒 **Waktu:** {$time}\n";
        $markdown .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬";

        if ($discord_enabled) {
            $embed = [
                'title' => '📉 SFP Optical Power Warning (DDM)',
                'description' => "Redaman fiber optik pada transceiver SFP turun melewati ambang batas normal.",
                'color' => 0xF59E0B, // Amber
                'fields' => [
                    ['name' => '🏢 Switch', 'value' => $switch_name, 'inline' => true],
                    ['name' => '🌐 IP Address', 'value' => "`{$switch_ip}`", 'inline' => true],
                    ['name' => '🔌 Port', 'value' => "`{$port_name}`", 'inline' => true],
                    ['name' => '📉 RX Optical Power', 'value' => "`{$rx_power} dBm` (Critical Low)", 'inline' => true]
                ],
                'footer' => ['text' => APP_NAME . ' Optical DDM Telemetry'],
                'timestamp' => date('c')
            ];
            if ($tx_power !== null) {
                $embed['fields'][] = ['name' => '📈 TX Optical Power', 'value' => "`{$tx_power} dBm`", 'inline' => true];
            }
            $embed['fields'][] = ['name' => '🕒 Waktu', 'value' => $time, 'inline' => true];
            self::sendDiscord(null, $embed);
        }
        if ($slack_enabled) self::sendSlack($markdown);

        if ($email_enabled) {
            $subject = "⚠️ Optical Warning: Port {$port_name} on {$switch_name} RX {$rx_power} dBm";
            $body = "<h2>SFP Optical Power Degradation</h2>";
            $body .= "<p>Switch <b>{$switch_name}</b> port <b>{$port_name}</b> optical RX is critically low:</p>";
            $body .= "<ul><li>RX Power: {$rx_power} dBm</li>";
            if ($tx_power) $body .= "<li>TX Power: {$tx_power} dBm</li>";
            $body .= "</ul>";
            self::sendEmail($subject, $body);
        }
    }

    /**
     * Notify when a subnet is nearly full.
     */
    public static function notifySubnetFull($subnet, $mask, $percent, $used, $total) {
        if (self::isThrottled('subnet_full_' . $subnet, 3600)) return;

        $telegram_enabled = Settings::enabled('telegram_enabled');
        $email_enabled = Settings::enabled('email_enabled');
        $discord_enabled = Settings::enabled('discord_enabled');
        $slack_enabled = Settings::enabled('slack_enabled');

        if (!$telegram_enabled && !$email_enabled && !$discord_enabled && !$slack_enabled) return;

        if ($telegram_enabled) {
            $message = "☢️ <b>Subnet Nearly Full! (" . (int)$percent . "%)</b>\n\n";
            $message .= "📍 <b>Subnet:</b> <code>" . htmlspecialchars($subnet . '/' . $mask) . "</code>\n";
            $message .= "📊 <b>Usage:</b> {$used} / {$total} IPs\n";
            $message .= "⚡️ <b>Notice:</b> Pertimbangkan memperluas subnet ini segera.\n";
            $message .= "🕒 <b>Time:</b> " . date('Y-m-d H:i:s');
            self::sendTelegram($message);
        }

        if ($discord_enabled) {
            $embed = [
                'title' => '☢️ Subnet Capacity Warning (' . (int)$percent . '%)',
                'description' => "Penggunaan alokasi IP pada subnet ini hampir mencapai kapasitas maksimal.",
                'color' => 0xF59E0B, // Amber
                'fields' => [
                    ['name' => '📍 Subnet', 'value' => "`{$subnet}/{$mask}`", 'inline' => true],
                    ['name' => '📊 Alokasi IP', 'value' => "{$used} / {$total} ({$percent}%)", 'inline' => true],
                    ['name' => '🕒 Waktu', 'value' => date('Y-m-d H:i:s'), 'inline' => true]
                ],
                'footer' => ['text' => APP_NAME . ' IPAM Capacity Guard'],
                'timestamp' => date('c')
            ];
            self::sendDiscord(null, $embed);
        }

        if ($slack_enabled) {
            $markdown = "*[ SUBNET CAPACITY ALERT ]*\n" .
                        "Subnet: `{$subnet}/{$mask}`\n" .
                        "Usage: {$used}/{$total} ({$percent}%)\n" .
                        "Time: " . date('Y-m-d H:i:s');
            self::sendSlack($markdown);
        }

        if ($email_enabled) {
            $subject = "☢️ CAPACITY ALERT: Subnet {$subnet}/{$mask} is {$percent}% full";
            $body = "<h2>Subnet Capacity Alert</h2>";
            $body .= "<p>Subnet <b>{$subnet}/{$mask}</b> has reached its usage threshold.</p>";
            $body .= "<ul>";
            $body .= "<li><b>Current Usage:</b> {$percent}%</li>";
            $body .= "<li><b>Used IPs:</b> {$used}</li>";
            $body .= "<li><b>Total Capacity:</b> {$total}</li>";
            $body .= "</ul>";
            $body .= "<p>Take action to prevent IP exhaustion.</p>";
            self::sendEmail($subject, $body);
        }
    }

    /**
     * Notify when a Netwatch target changes status.
     */
    public static function notifyNetwatch($name, $host, $status, $duration = null, $latency = null) {
        $telegram_enabled = Settings::enabled('telegram_enabled');
        $email_enabled = Settings::enabled('email_enabled');
        $discord_enabled = Settings::enabled('discord_enabled');
        $slack_enabled = Settings::enabled('slack_enabled');

        if (!$telegram_enabled && !$email_enabled && !$discord_enabled && !$slack_enabled) return;

        $icon = ($status === 'up') ? "✅" : (($status === 'intermittent') ? "⚠️" : "🚨");
        $state_text = strtoupper($status);
        $time = date('Y-m-d H:i:s');

        // Escape variables for HTML safety
        $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safe_host = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');

        // 1. TELEGRAM & EMAIL MESSAGE (Always Clean HTML)
        $html_message = "{$icon} <b>Netwatch Alert: {$state_text}</b>\n\n";
        $html_message .= "🖥 <b>Device:</b> {$safe_name}\n";
        $html_message .= "🌐 <b>Host:</b> <code>{$safe_host}</code>\n";
        $html_message .= "📊 <b>Status:</b> <b>{$state_text}</b>\n";
        if ($latency) $html_message .= "⚡ <b>Latency:</b> <code>{$latency}ms</code>\n";
        if ($status === 'up' && !empty($duration)) {
            $html_message .= "⏱ <b>Downtime:</b> <code>{$duration}</code>\n";
        }
        $html_message .= "\n🕒 <b>Time:</b> " . $time;

        // 2. DISCORD & SLACK MESSAGE (Custom or Built-in Markdown)
        $template = Settings::get('custom_netwatch_template');
        if (empty($template)) {
            // Default built-in Discord modern style
            $template = "**[ NETWATCH MONITORING ALERT ]**\n";
            $template .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬\n";
            $template .= "🖥 **Perangkat:** {name}\n";
            $template .= "🌐 **Host / IP:** `{host}`\n";
            $template .= "📊 **Status:** **{status}**\n";
            $template .= "⚡ **Latency:** `{latency}`\n";
            $template .= "⏱ **Durasi Down:** `{duration}`\n";
            $template .= "🕒 **Waktu:** {time}\n";
            $template .= "▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬";
        }

        $placeholders = [
            '{name}' => $name,
            '{host}' => $host,
            '{status}' => $state_text,
            '{time}' => $time,
            '{duration}' => $duration ? $duration : '-',
            '{latency}' => $latency ? $latency . 'ms' : '-'
        ];
        $markdown_message = str_replace(array_keys($placeholders), array_values($placeholders), $template);

        $success = false;
        
        // Send to Telegram
        if ($telegram_enabled) {
            if (self::sendTelegram($html_message)) $success = true;
        }

        // Send to Discord
        if ($discord_enabled) {
            $color = ($status === 'up') ? 0x10B981 : (($status === 'intermittent') ? 0xF59E0B : 0xEF4444);
            $embed = [
                'title' => "{$icon} Netwatch Alert: {$name} is {$state_text}",
                'description' => "Status target monitoring telah berubah menjadi **{$state_text}**.",
                'color' => $color,
                'fields' => [
                    ['name' => '🖥 Perangkat', 'value' => $name, 'inline' => true],
                    ['name' => '🌐 Host / IP', 'value' => "`{$host}`", 'inline' => true],
                    ['name' => '📊 Status', 'value' => "**{$state_text}**", 'inline' => true],
                    ['name' => '⚡ Latency', 'value' => $latency ? "`{$latency}ms`" : ($status === 'up' ? '`0ms`' : '`Timeout`'), 'inline' => true]
                ],
                'footer' => ['text' => APP_NAME . ' Netwatch Sentinel'],
                'timestamp' => date('c')
            ];
            if ($status === 'up' && !empty($duration)) {
                $embed['fields'][] = ['name' => '⏱ Downtime', 'value' => "`{$duration}`", 'inline' => true];
            }
            $embed['fields'][] = ['name' => '🕒 Waktu', 'value' => $time, 'inline' => true];

            $custom_template = Settings::get('custom_netwatch_template');
            $custom_text = !empty($custom_template) ? $markdown_message : null;

            if (self::sendDiscord($custom_text, $embed)) $success = true;
        }

        // Send to Slack
        if ($slack_enabled) {
            if (self::sendSlack($markdown_message)) $success = true;
        }

        // Send to Email
        if ($email_enabled) {
            $type = ($status === 'up') ? 'success' : (($status === 'intermittent') ? 'warning' : 'danger');
            $subject = "{$icon} Netwatch Alert: {$name} is {$state_text}";
            
            $details = [
                'Device Name' => $name,
                'Host/IP' => $host,
                'Current Status' => $state_text,
                'Check Time' => $time
            ];

            if ($latency) $details['Latency'] = $latency . 'ms';
            if ($status === 'up' && !empty($duration)) {
                $details['Downtime Duration'] = $duration;
            }

            $lead = "Monitoring system telah mendeteksi perubahan status pada perangkat <b>{$name}</b>.";
            $body = self::getPremiumEmailTemplate("Netwatch: {$state_text}", $lead, $details, $type, '/netwatch');
            
            if (self::sendEmail($subject, $body)) $success = true;
        }
        
        return $success || (!$telegram_enabled && !$email_enabled && !$discord_enabled && !$slack_enabled);
    }

    public static function testTelegram() {
        $server_host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'Localhost');
        $message = "🔹 <b>" . APP_NAME . " • Test Notification</b> 🔹\n\n";
        $message .= "✅ <b>Integration Status: CONNECTED</b>\n";
        $message .= "Telegram Bot alert dispatcher beroperasi dengan normal.\n\n";
        $message .= "🖥 <b>Server:</b> <code>" . htmlspecialchars($server_host) . "</code>\n";
        $message .= "📦 <b>Version:</b> <code>v" . APP_VERSION . "</code>\n";
        $message .= "🔔 <b>Monitored Events:</b>\n";
        $message .= "  • 🔄 L2 Switching Loop & STP Blocking\n";
        $message .= "  • 🚨 Netwatch Host Down/Recovery\n";
        $message .= "  • ⚠️ Enterprise IP Conflict\n";
        $message .= "  • 📉 SFP Optical Fiber DDM\n";
        $message .= "  • ⚡ New Device Discovery\n\n";
        $message .= "🕒 <b>Timestamp:</b> " . date('Y-m-d H:i:s');
        return self::sendTelegram($message);
    }

    public static function testDiscord() {
        $server_host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'Localhost');
        $embed = [
            'title' => '🚀 ' . APP_NAME . ' • Discord Webhook Connected!',
            'description' => "Webhook Discord berhasil diintegrasikan dengan sistem **" . APP_NAME . "**. Channel ini siap menerima telemetri dan notifikasi insiden jaringan secara real-time.",
            'color' => 0x10B981, // Emerald Green
            'fields' => [
                ['name' => '🖥 Server Host', 'value' => "`{$server_host}`", 'inline' => true],
                ['name' => '📦 Versi Sistem', 'value' => "`v" . APP_VERSION . "`", 'inline' => true],
                ['name' => '📡 Jalur Dispatcher', 'value' => 'Discord Webhook Direct', 'inline' => true],
                [
                    'name' => '🔔 Sentinels Aktif',
                    'value' => "• 🚨 Netwatch Host Down/Recovery\n• 🔄 L2 Switching Loop & STP Detective\n• ⚠️ Enterprise IP Conflict & ARP Clashing\n• 📉 SFP Optical DDM Degradation\n• 🔍 New Device Discovery",
                    'inline' => false
                ]
            ],
            'footer' => ['text' => APP_NAME . ' NOC Sentinel • Systems Operational'],
            'timestamp' => date('c')
        ];
        return self::sendDiscord(null, $embed);
    }

    public static function testSlack() {
        $server_host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'Localhost');
        $text = "*[ " . APP_NAME . " • Test Notification ]*\n\n" .
                "✅ *Integration Status: CONNECTED*\n" .
                "Slack webhook alert dispatcher beroperasi dengan normal.\n\n" .
                "🖥 *Server:* `{$server_host}`\n" .
                "📦 *Version:* `v" . APP_VERSION . "`\n" .
                "🕒 *Timestamp:* " . date('Y-m-d H:i:s');
        return self::sendSlack($text);
    }

    public static function testEmail() {
        $subject = "Test Notification - " . APP_NAME;
        $body = "<h2>Test Notification</h2><p>Your email notification settings for <b>" . APP_NAME . "</b> are working correctly!</p><p>Sent at: " . date('Y-m-d H:i:s') . "</p>";
        return self::sendEmail($subject, $body);
    }

    /**
     * Send message via Telegram Bot API
     */
    public static function sendTelegram($text) {
        $token = Settings::get('telegram_bot_token');
        $chat_id = Settings::get('telegram_chat_id');

        if (!$token || !$chat_id) return false;

        // Auto-convert common markdown bold/code to HTML if text lacks HTML tags
        if (strpos($text, '<') === false) {
            $text = preg_replace('/\*\*(.*?)\*\*/s', '<b>$1</b>', $text);
            $text = preg_replace('/(?<!\*)\*(?!\*)(.*?)\*/s', '<b>$1</b>', $text);
            $text = preg_replace('/`(.*?)`/s', '<code>$1</code>', $text);
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];

        // Prefer cURL if available
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $res = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($res === false || $http_code >= 400) {
                error_log("Telegram Send Error: HTTP {$http_code} | " . ($err ?: $res));
                return false;
            }
            return true;
        }

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'timeout' => 8
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];

        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            $error = error_get_last();
            $response_headers = $http_response_header ?? [];
            $status_line = $response_headers[0] ?? 'Unknown Status';
            error_log("Telegram Send Error: " . ($error['message'] ?? 'Unknown error') . " | Status: " . $status_line);
            return false;
        }
        return true;
    }

    /**
     * Send email via local mail() function (fallback to system sendmail)
     */
    private static function sendEmail($subject, $message) {
        $to = Settings::get('admin_email');
        if (!$to || !Settings::enabled('email_enabled')) return false;

        $smtp_host = Settings::get('smtp_host');
        $smtp_port = Settings::get('smtp_port');
        $smtp_user = Settings::get('smtp_user');
        $smtp_pass = Settings::get('smtp_pass');
        $from = Settings::get('mail_from', 'notifications@example.com');

        // If no SMTP host is configured, try basic mail()
        if (empty($smtp_host) || $smtp_host == 'localhost') {
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: <{$from}>" . "\r\n";
            return @mail($to, $subject, $message, $headers);
        }

        // Use Manual SMTP Sender for Authenticated/SSL mail
        return self::sendSmtpEmail($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $from, $to, $subject, $message);
    }

    /**
     * Send email with attachments (CSV, TXT)
     */
    public static function sendEmailWithAttachments($subject, $body, $attachments = [], $to = null) {
        if ($to === null) {
            $to = Settings::get('admin_email');
        }
        
        if (!$to || !Settings::enabled('email_enabled')) return false;

        $from = Settings::get('mail_from', 'notifications@example.com');
        $boundary = "PHP-mixed-" . md5(time());
        $newline = "\r\n";

        // Headers
        $headers = "From: " . APP_NAME . " <{$from}>" . $newline;
        $headers .= "MIME-Version: 1.0" . $newline;
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"" . $newline;

        // Message body
        $message = "--{$boundary}" . $newline;
        $message .= "Content-Type: text/html; charset=UTF-8" . $newline;
        $message .= "Content-Transfer-Encoding: 8bit" . $newline . $newline;
        $message .= $body . $newline . $newline;

        // Attachments
        foreach ($attachments as $filename => $content) {
            $message .= "--{$boundary}" . $newline;
            $message .= "Content-Type: application/octet-stream; name=\"{$filename}\"" . $newline;
            $message .= "Content-Description: {$filename}" . $newline;
            $message .= "Content-Disposition: attachment; filename=\"{$filename}\"; size=" . strlen($content) . ";" . $newline;
            $message .= "Content-Transfer-Encoding: base64" . $newline . $newline;
            $message .= chunk_split(base64_encode($content)) . $newline;
        }

        $message .= "--{$boundary}--";

        // Use SMTP if configured
        $smtp_host = Settings::get('smtp_host');
        if (!empty($smtp_host) && $smtp_host !== 'localhost') {
            $smtp_port = Settings::get('smtp_port');
            $smtp_user = Settings::get('smtp_user');
            $smtp_pass = Settings::get('smtp_pass');
            return self::sendSmtpRaw($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $from, $to, $subject, $message, $headers);
        }

        return @mail($to, $subject, $message, $headers);
    }

    /**
     * Basic SMTP sender for standard HTML emails
     */
    private static function sendSmtpEmail($host, $port, $user, $pass, $from, $to, $subject, $message) {
        $headers = "From: " . APP_NAME . " <{$from}>" . "\r\n";
        $headers .= "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";

        return self::sendSmtpRaw($host, $port, $user, $pass, $from, $to, $subject, $message, $headers);
    }

    /**
     * Raw SMTP sender to handle custom headers and multipart body
     */
    private static function sendSmtpRaw($host, $port, $user, $pass, $from, $to, $subject, $message, $extra_headers) {
        $timeout = 10;
        $newline = "\r\n";
        
        $smtp_host = ($port == 465) ? "ssl://{$host}" : $host;
        $socket = @fsockopen($smtp_host, $port, $errno, $errstr, $timeout);
        if (!$socket) return false;

        $response = function($socket) {
            $res = "";
            while ($str = fgets($socket, 515)) {
                $res .= $str;
                if (substr($str, 3, 1) == " ") break;
            }
            return $res;
        };

        $exec = function($socket, $cmd) use ($response, $newline) {
            fputs($socket, $cmd . $newline);
            return $response($socket);
        };

        $response($socket); 
        $server_name = $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';
        $exec($socket, "EHLO " . $server_name);
        
        if (!empty($user) && !empty($pass)) {
            $exec($socket, "AUTH LOGIN");
            $exec($socket, base64_encode($user));
            $exec($socket, base64_encode($pass));
        }

        $exec($socket, "MAIL FROM: <{$from}>");
        $exec($socket, "RCPT TO: <{$to}>");
        $exec($socket, "DATA");

        fputs($socket, "Subject: {$subject}" . $newline);
        fputs($socket, $extra_headers . $newline);
        fputs($socket, $message . $newline . "." . $newline);
        $response($socket);

        $exec($socket, "QUIT");
        fclose($socket);
        return true;
    }
    /**
     * Send message to Discord Webhook (Supports raw text, custom template, or Rich Embeds)
     * @param string|array|null $content Message string or payload array
     * @param array|null $embed Optional embed object or array of embeds
     */
    public static function sendDiscord($content, $embed = null) {
        $url = Settings::get('discord_webhook_url');
        if (empty($url)) return false;

        $payload = [
            'username' => APP_NAME . ' Sentinel'
        ];

        if (is_array($content) && isset($content['embeds'])) {
            $payload = array_merge($payload, $content);
        } elseif (is_array($content) && (isset($content['title']) || isset($content['fields']))) {
            $payload['embeds'] = [$content];
        } else {
            if ($content !== null && $content !== '') {
                $payload['content'] = (string)$content;
            }
            if (!empty($embed)) {
                $payload['embeds'] = (isset($embed[0]) && is_array($embed[0])) ? $embed : [$embed];
            }
        }

        $json_data = json_encode($payload);

        // Prefer cURL for robust networking & SSL
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_data,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $res = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($res === false || $http_code >= 400) {
                error_log("Discord Send Error: HTTP {$http_code} | " . ($err ?: $res));
                return false;
            }
            return true;
        }

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => $json_data,
                'timeout' => 6
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        return @file_get_contents($url, false, stream_context_create($options)) !== false;
    }

    /**
     * Send message to Slack Webhook
     */
    public static function sendSlack($text) {
        $url = Settings::get('slack_webhook_url');
        if (empty($url)) return false;

        $data = ['text' => $text];
        $json_data = json_encode($data);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_data,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $res = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($res !== false && $http_code < 400);
        }

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => $json_data,
                'timeout' => 6
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        return @file_get_contents($url, false, stream_context_create($options)) !== false;
    }
}
