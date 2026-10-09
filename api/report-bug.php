<?php
require_once '../includes/config.php';
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    json_response(['error' => 'Unauthorized'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$db = get_db_connection();
$email = trim($_POST['email'] ?? '');
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$attach_audit = isset($_POST['attach_audit']) ? ($_POST['attach_audit'] === '1' || $_POST['attach_audit'] === 'true') : true;

if (empty($title) || empty($description)) {
    json_response(['error' => 'Judul dan detail kendala wajib diisi.'], 400);
}

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Format email pelapor tidak valid.'], 400);
}

// 1. Handle Screenshot File Upload (Optional)
$saved_file_full_path = null;
$relative_screenshot_path = null;
$screenshot_mime = null;

if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['screenshot']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['screenshot']['error'] === UPLOAD_ERR_FORM_SIZE) {
        json_response(['error' => 'Ukuran file screenshot melebihi batas upload server (' . ini_get('upload_max_filesize') . ').'], 400);
    }
    if ($_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
        json_response(['error' => 'Gagal mengupload screenshot (Error code: ' . $_FILES['screenshot']['error'] . ').'], 400);
    }

    $file = $_FILES['screenshot'];
    $max_size = 10 * 1024 * 1024; // 10 MB
    
    if ($file['size'] > $max_size) {
        json_response(['error' => 'Ukuran file screenshot melebihi batas maksimal 10MB.'], 400);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = [
        'image/png'   => 'png',
        'image/jpeg'  => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/webp'  => 'webp',
        'image/gif'   => 'gif'
    ];

    if (!isset($allowed_mimes[$mime])) {
        json_response(['error' => 'Format screenshot harus berupa file gambar (PNG, JPG, WEBP, atau GIF).'], 400);
    }

    $ext = $allowed_mimes[$mime];
    $clean_name = 'bug_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    // 1. Always save to system temp dir first (always 100% writable by www-data in Linux/Docker)
    $temp_path = sys_get_temp_dir() . '/' . $clean_name;
    if (move_uploaded_file($file['tmp_name'], $temp_path)) {
        $saved_file_full_path = $temp_path;
        $screenshot_mime = $mime;

        // 2. Optionally copy to permanent uploads/bug_reports if possible
        $upload_dir = dirname(__DIR__) . '/uploads/bug_reports';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0777, true);
        }
        $perm_target = $upload_dir . '/' . $clean_name;
        if (@copy($temp_path, $perm_target)) {
            $relative_screenshot_path = 'uploads/bug_reports/' . $clean_name;
        }
    } else {
        json_response(['error' => 'Server gagal memindahkan file temporary screenshot.'], 500);
    }
}

// 2. Auto-capture system context
$system_info = [
    'app_version'     => APP_VERSION,
    'php_version'     => PHP_VERSION,
    'os'              => PHP_OS,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'http_host'       => $_SERVER['HTTP_HOST'] ?? 'localhost',
    'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    'remote_ip'       => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
];
$system_info_json = json_encode($system_info, JSON_UNESCAPED_SLASHES);

// 3. Store in local DB
try {
    $db->exec("CREATE TABLE IF NOT EXISTS bug_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        screenshot_path VARCHAR(255) DEFAULT NULL,
        system_info TEXT DEFAULT NULL,
        status ENUM('pending', 'resolved') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    
    try { $db->exec("ALTER TABLE bug_reports ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER user_id"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE bug_reports ADD COLUMN screenshot_path VARCHAR(255) DEFAULT NULL AFTER description"); } catch (Exception $e) {}

    $stmt = $db->prepare("INSERT INTO bug_reports (user_id, email, title, description, screenshot_path, system_info) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $email ?: null,
        $title,
        $description,
        $relative_screenshot_path,
        $system_info_json
    ]);
} catch (Exception $e) {
    error_log("[report-bug] DB Error: " . $e->getMessage());
}

// 4. Secure Decryption of Discord Webhook
function get_decrypted_discord_webhook() {
    $ciphertext = 'Iqv/6ePwkJOw+x9HUoKKJl26hjHtnRZkIwVf2qhy68XOQmu17OQLGe4M2Et/s8NjnG5fym7uYhYIuJShNV3Mc3sd30XN8/AcGdPlU9Ojpcwgb6V82X08oascGzKism4gxyuHyBm0G8vrvFG0V1KggggYbdp2hkrOtJ+JgYUOnqN8DZIWi4bSdq53J/eyl05f';
    $salt_key = hash('sha256', 'NetScopePro_Discord_Report_Secret_2026', true);
    $decoded = @base64_decode($ciphertext, true);
    if ($decoded === false) return null;
    $iv_size = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($decoded) <= $iv_size) return null;
    $iv = substr($decoded, 0, $iv_size);
    $encrypted = substr($decoded, $iv_size);
    $decrypted = @openssl_decrypt($encrypted, 'aes-256-cbc', $salt_key, OPENSSL_RAW_DATA, $iv);
    return ($decrypted && filter_var($decrypted, FILTER_VALIDATE_URL)) ? $decrypted : null;
}

// 5. Generate System Diagnostic & Audit Log File
function generate_diagnostic_audit_text($db, $title, $description, $email, $system_info) {
    $out = "======================================================================\n";
    $out .= "       NETSCOPE PRO - SYSTEM DIAGNOSTIC & AUDIT TRACE LOG\n";
    $out .= "======================================================================\n";
    $out .= "Generated At     : " . date('Y-m-d H:i:s T') . "\n";
    $out .= "Application      : " . APP_NAME . " v" . APP_VERSION . "\n";
    $out .= "Reporter User    : " . ($_SESSION['username'] ?? 'admin') . " (ID: " . ($_SESSION['user_id'] ?? '-') . ")\n";
    $out .= "Reporter Email   : " . ($email ?: 'Tidak dicantumkan') . "\n";
    $out .= "Server Host      : " . ($system_info['http_host'] ?? 'localhost') . "\n";
    $out .= "Client IP        : " . ($system_info['remote_ip'] ?? '-') . "\n";
    $out .= "PHP Version      : " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
    $out .= "Server Software  : " . ($system_info['server_software'] ?? '-') . "\n";
    $out .= "Operating System : " . PHP_OS . "\n";
    $out .= "Memory Limit     : " . ini_get('memory_limit') . "\n";
    $out .= "Max Exec Time    : " . ini_get('max_execution_time') . "s\n";
    $out .= "Upload Max Size  : " . ini_get('upload_max_filesize') . " (post_max_size: " . ini_get('post_max_size') . ")\n\n";

    $out .= "----------------------------------------------------------------------\n";
    $out .= "[REPORTED ISSUE DETAILS]\n";
    $out .= "----------------------------------------------------------------------\n";
    $out .= "Judul       : " . $title . "\n";
    $out .= "Deskripsi   : \n" . $description . "\n\n";

    $out .= "----------------------------------------------------------------------\n";
    $out .= "[CORE EXTENSIONS & MODULES CHECK]\n";
    $out .= "----------------------------------------------------------------------\n";
    $exts = ['pdo', 'pdo_mysql', 'snmp', 'curl', 'openssl', 'mbstring', 'json', 'session', 'sockets', 'gd', 'redis'];
    foreach ($exts as $ext) {
        $out .= sprintf("%-18s: %s\n", $ext, extension_loaded($ext) ? 'LOADED' : 'NOT LOADED');
    }
    $out .= "\n";

    $out .= "----------------------------------------------------------------------\n";
    $out .= "[DATABASE INVENTORY QUICK STATS]\n";
    $out .= "----------------------------------------------------------------------\n";
    if ($db) {
        try {
            $sw_count = $db->query("SELECT COUNT(*) FROM switches")->fetchColumn();
            $sub_count = $db->query("SELECT COUNT(*) FROM subnets")->fetchColumn();
            $ip_count = $db->query("SELECT COUNT(*) FROM ip_addresses")->fetchColumn();
            $dev_count = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE state IN ('active', 'reserved', 'dhcp')")->fetchColumn();
            $audit_count = $db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
            $asset_count = 0;
            try { $asset_count = $db->query("SELECT COUNT(*) FROM server_assets")->fetchColumn(); } catch(Exception $e) {}
            
            $out .= "Switches Total   : " . $sw_count . "\n";
            $out .= "Subnets Total    : " . $sub_count . "\n";
            $out .= "IP Addresses     : " . $ip_count . "\n";
            $out .= "Active Devices   : " . $dev_count . "\n";
            $out .= "Server Assets    : " . $asset_count . "\n";
            $out .= "Audit Log Rows   : " . $audit_count . "\n";
        } catch (Exception $e) {
            $out .= "DB Stats Error   : " . $e->getMessage() . "\n";
        }
    } else {
        $out .= "Database connection unavailable\n";
    }
    $out .= "\n";

    $out .= "----------------------------------------------------------------------\n";
    $out .= "[RECENT AUDIT LOGS (Last 30 Administrative & Poller Actions)]\n";
    $out .= "----------------------------------------------------------------------\n";
    if ($db) {
        try {
            $stmt = $db->query("SELECT a.id, a.created_at, a.action, a.target_type, a.target_id, a.details, COALESCE(u.username, 'system') AS user_name 
                                FROM audit_logs a 
                                LEFT JOIN users u ON a.user_id = u.id 
                                ORDER BY a.created_at DESC, a.id DESC 
                                LIMIT 30");
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($logs)) {
                $out .= "Tidak ada data riwayat audit di database.\n";
            } else {
                foreach ($logs as $l) {
                    $tgt = $l['target_type'] ? " [{$l['target_type']}:{$l['target_id']}]" : '';
                    $out .= sprintf("[%s] %-10s | %-16s%s | %s\n", $l['created_at'], $l['user_name'], $l['action'], $tgt, $l['details']);
                }
            }
        } catch (Exception $e) {
            $out .= "Audit logs read error: " . $e->getMessage() . "\n";
        }
    }
    $out .= "\n";

    $out .= "----------------------------------------------------------------------\n";
    $out .= "[RECENT SYSTEM & PHP ERROR LOG (Last 25 Entries)]\n";
    $out .= "----------------------------------------------------------------------\n";
    $error_log_path = ini_get('error_log');
    $read_error = false;
    if ($error_log_path && file_exists($error_log_path) && is_readable($error_log_path)) {
        $lines = @file($error_log_path);
        if ($lines && !empty($lines)) {
            $last_lines = array_slice($lines, -25);
            $out .= implode('', $last_lines) . "\n";
            $read_error = true;
        }
    }
    if (!$read_error) {
        $out .= "PHP error log file tidak dikonfigurasi atau belum berisi entri (" . ($error_log_path ?: 'standard stderr') . ").\n";
    }

    $out .= "======================================================================\n";
    $out .= "END OF DIAGNOSTIC AUDIT LOG\n";
    $out .= "======================================================================\n";
    return $out;
}

// 6. Dispatch Notification to Discord via Webhook
$webhook_url = get_decrypted_discord_webhook();
$discord_sent = false;

if ($webhook_url) {
    $host_label = $_SERVER['HTTP_HOST'] ?? 'NetScope Pro';
    $reporter_name = $_SESSION['username'] ?? 'Administrator';

    $embed = [
        'title'       => '🚨 [BUG REPORT] ' . mb_substr($title, 0, 200),
        'description' => mb_substr($description, 0, 2000),
        'color'       => 15158332, // Red / Alert 0xE74C3C
        'fields'      => [
            [
                'name'   => '👤 Pelapor',
                'value'  => htmlspecialchars($reporter_name),
                'inline' => true
            ],
            [
                'name'   => '✉️ Email',
                'value'  => htmlspecialchars($email ?: 'Tidak dicantumkan'),
                'inline' => true
            ],
            [
                'name'   => '🌐 Host / Domain',
                'value'  => '`' . htmlspecialchars($host_label) . '`',
                'inline' => true
            ],
            [
                'name'   => '📦 Versi NetScope',
                'value'  => 'v' . APP_VERSION,
                'inline' => true
            ],
            [
                'name'   => '💻 Platform Server',
                'value'  => PHP_OS . ' • PHP ' . PHP_VERSION,
                'inline' => true
            ],
            [
                'name'   => '🖥️ Web Server',
                'value'  => htmlspecialchars(substr($_SERVER['SERVER_SOFTWARE'] ?? 'Standard HTTP', 0, 45)),
                'inline' => true
            ]
        ],
        'footer' => [
            'text' => 'NetScope Pro • Automated Bug Reporter'
        ],
        'timestamp' => date('c')
    ];

    if ($attach_audit) {
        $embed['fields'][] = [
            'name'   => '📄 Lampiran Log',
            'value'  => '`audit_diagnostic_log.txt` terlampir',
            'inline' => true
        ];
    }

    // Generate temporary diagnostic text file if enabled
    $temp_log_file = null;
    if ($attach_audit) {
        $temp_log_file = sys_get_temp_dir() . '/audit_diagnostic_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.txt';
        $log_content = generate_diagnostic_audit_text($db, $title, $description, $email, $system_info);
        file_put_contents($temp_log_file, $log_content);
    }

    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $has_screenshot = ($saved_file_full_path && file_exists($saved_file_full_path));
    $has_log_file = ($temp_log_file && file_exists($temp_log_file));

    if ($has_screenshot || $has_log_file) {
        $payload = [
            'content' => '⚠️ **Laporan Kendala Baru Diterima dari `' . $host_label . '`**',
            'embeds'  => [$embed]
        ];

        $post_fields = [];
        $file_idx = 0;

        if ($has_screenshot) {
            $attach_name = basename($saved_file_full_path);
            $embed['image'] = ['url' => 'attachment://' . $attach_name];
            $payload['embeds'] = [$embed]; // Update with attachment URL
            $post_fields['files[' . $file_idx . ']'] = new CURLFile($saved_file_full_path, $screenshot_mime, $attach_name);
            $file_idx++;
        }

        if ($has_log_file) {
            $post_fields['files[' . $file_idx . ']'] = new CURLFile($temp_log_file, 'text/plain', 'audit_diagnostic_log.txt');
            $file_idx++;
        }

        $post_fields['payload_json'] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    } else {
        $payload = [
            'content' => '⚠️ **Laporan Kendala Baru Diterima dari `' . $host_label . '`**',
            'embeds'  => [$embed]
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($temp_log_file && file_exists($temp_log_file)) {
        @unlink($temp_log_file);
    }

    if ($http_code >= 200 && $http_code < 300) {
        $discord_sent = true;
    }
}

json_response([
    'success'      => true,
    'message'      => 'Laporan bug berhasil dikirim ke Discord Developer!',
    'discord_sent' => $discord_sent
]);
