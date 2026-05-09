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
            'info' => '#6366f1',    // Indigo
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
     * Send a notification when a new device is discovered.
     */
    public static function notifyNewDevice($ip, $mac, $vendor, $hostname, $subnet_name) {
        $telegram_enabled = Settings::enabled('telegram_enabled');
        $email_enabled = Settings::enabled('email_enabled');

        if (!$telegram_enabled && !$email_enabled) return;

        if ($telegram_enabled) {
            $message = "🚨 *New Device Discovered!*\n\n";
            $message .= "📍 *Subnet:* {$subnet_name}\n";
            $message .= "🌐 *IP:* `{$ip}`\n";
            $message .= "🏷 *Hostname:* " . ($hostname ?: 'Unknown') . "\n";
            $message .= "🔌 *MAC:* `{$mac}`\n";
            $message .= "🏢 *Vendor:* {$vendor}\n";
            $message .= "🕒 *Time:* " . date('Y-m-d H:i:s');
            self::sendTelegram($message);
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
     * Send notification for an IP conflict (MAC address change).
     */
    public static function notifyConflict($ip, $old_mac, $new_mac, $subnet_name) {
        $telegram_enabled = Settings::enabled('telegram_enabled');
        $email_enabled = Settings::enabled('email_enabled');

        if (!$telegram_enabled && !$email_enabled) return;

        if ($telegram_enabled) {
            $message = "⚠️ *IP Conflict Detected!*\n\n";
            $message .= "📍 *Subnet:* {$subnet_name}\n";
            $message .= "🌐 *IP:* `{$ip}`\n";
            $message .= "🛑 *Old MAC:* `{$old_mac}`\n";
            $message .= "🚩 *New MAC:* `{$new_mac}`\n";
            $message .= "🕒 *Time:* " . date('Y-m-d H:i:s');
            self::sendTelegram($message);
        }

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
     * Notify when a subnet is nearly full.
     */
    public static function notifySubnetFull($subnet, $mask, $percent, $used, $total) {
        $telegram_enabled = Settings::enabled('telegram_enabled');
        $email_enabled = Settings::enabled('email_enabled');

        if (!$telegram_enabled && !$email_enabled) return;

        if ($telegram_enabled) {
            $message = "☢️ *Subnet Nearly Full! ({$percent}%)*\n\n";
            $message .= "📍 *Subnet:* {$subnet}/{$mask}\n";
            $message .= "📊 *Usage:* {$used} / {$total} IPs\n";
            $message .= "⚡️ *Notice:* Consider expanding this subnet soon.\n";
            $message .= "🕒 *Time:* " . date('Y-m-d H:i:s');
            self::sendTelegram($message);
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

        $icon = ($status === 'up') ? "✅" : "🚨";
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
            if (self::sendDiscord($markdown_message)) $success = true;
        }

        // Send to Slack
        if ($slack_enabled) {
            if (self::sendSlack($markdown_message)) $success = true;
        }

        // Send to Email
        if ($email_enabled) {
            $type = ($status === 'up') ? 'success' : 'danger';
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
        $message = "🔹 *Test Notification* 🔹\n\n✅ Your Telegram Bot integration for **" . APP_NAME . "** is working correctly!\n\n🕒 *Sent at:* " . date('Y-m-d H:i:s');
        return self::sendTelegram($message);
    }

    public static function testEmail() {
        $subject = "Test Notification - " . APP_NAME;
        $body = "<h2>Test Notification</h2><p>Your email notification settings for <b>" . APP_NAME . "</b> are working correctly!</p><p>Sent at: " . date('Y-m-d H:i:s') . "</p>";
        return self::sendEmail($subject, $body);
    }

    /**
     * Send message via Telegram Bot API
     */
    private static function sendTelegram($text) {
        $token = Settings::get('telegram_bot_token');
        $chat_id = Settings::get('telegram_chat_id');

        if (!$token || !$chat_id) return false;

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'timeout' => 5
            ]
        ];

        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            $error = error_get_last();
            // Try to get more info from the response headers (contains Telegram error body)
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
     * Send message to Discord Webhook
     */
    private static function sendDiscord($text) {
        $url = Settings::get('discord_webhook_url');
        if (empty($url)) return false;

        $data = ['content' => $text];
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data),
                'timeout' => 5
            ]
        ];
        return @file_get_contents($url, false, stream_context_create($options)) !== false;
    }

    /**
     * Send message to Slack Webhook
     */
    private static function sendSlack($text) {
        $url = Settings::get('slack_webhook_url');
        if (empty($url)) return false;

        $data = ['text' => $text];
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data),
                'timeout' => 5
            ]
        ];
        return @file_get_contents($url, false, stream_context_create($options)) !== false;
    }
}
