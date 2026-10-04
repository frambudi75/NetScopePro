<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/asset.helper.php';

use phpseclib3\Net\SSH2;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    json_response(['error' => 'Unauthorized'], 401);
}
if (!is_admin()) {
    json_response(['error' => 'Forbidden'], 403);
}

// Release session lock to prevent blocking concurrent browser requests
session_write_close();

$id = $_GET['id'] ?? null;
if (!$id) {
    json_response(['error' => 'Asset ID required'], 400);
}

$db = get_db_connection();
$stmt = $db->prepare("SELECT * FROM server_assets WHERE id = ?");
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) {
    json_response(['error' => 'Asset not found'], 404);
}

$username = $asset['is_encrypted'] ? AssetHelper::decrypt($asset['username']) : $asset['username'];
$password = $asset['is_encrypted'] ? AssetHelper::decrypt($asset['password']) : $asset['password'];
$host = $asset['ip_address'];
$port = (int)$asset['port'] ?: 22;

// Check if SSH library is available
if (!class_exists('phpseclib3\Net\SSH2')) {
    json_response([
        'error' => 'SSH library (phpseclib3) is not installed in vendor directory. Run "composer install" or restart the Docker container.'
    ], 500);
}

try {
    $ssh = new SSH2($host, $port, 5);
    $ssh->setTimeout(6);

    if (!$ssh->login($username, $password)) {
        json_response([
            'error' => "SSH Authentication failed on {$host}:{$port} for user '{$username}'. Please verify credentials in Asset Settings."
        ], 401);
    }
    
    // Command for Linux/Unix systems (Universal: Debian, Ubuntu, CentOS, RHEL, Alpine, BusyBox, OpenWrt)
    $linux_cmd = <<<'EOF'
# 1. RAM Percentage via /proc/meminfo or free
RAM_PCT=$(awk '/MemTotal:/ {t=$2} /MemAvailable:/ {a=$2} /MemFree:/ {f=$2} /Buffers:/ {b=$2} /^Cached:/ {c=$2} END {if(a) {u=t-a} else {u=t-f-b-c}; if(t>0) printf "%.1f", (u/t)*100; else echo "0"}' /proc/meminfo 2>/dev/null)
if [ -z "$RAM_PCT" ]; then
    RAM_PCT=$(free 2>/dev/null | awk '/Mem:/ {if($2>0) printf "%.1f", $3/$2 * 100}')
fi

# 2. CPU Usage Percentage via fast /proc/stat delta (100ms) or loadavg
CPU_PCT=""
if [ -r /proc/stat ]; then
    read -r _ u1 n1 s1 i1 w1 x1 y1 z1 < /proc/stat 2>/dev/null
    t1=$((u1+n1+s1+i1+w1+x1+y1+z1))
    sleep 0.1
    read -r _ u2 n2 s2 i2 w2 x2 y2 z2 < /proc/stat 2>/dev/null
    t2=$((u2+n2+s2+i2+w2+x2+y2+z2))
    dt=$((t2-t1))
    di=$((i2-i1))
    if [ "$dt" -gt 0 ]; then
        CPU_PCT=$(awk "BEGIN {printf \"%.1f\", (1 - $di/$dt)*100}" 2>/dev/null)
    fi
fi
if [ -z "$CPU_PCT" ]; then
    CPU_PCT=$(awk '{print int($1 * 10)}' /proc/loadavg 2>/dev/null || echo "0")
fi

# 3. Disk Percentage via POSIX df -P
DISK_PCT=$(df -P / 2>/dev/null | awk 'NR==2 {gsub(/%/, "", $5); print $5}')

# 4. Temperature via thermal zone or hwmon
TEMP=$(cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null || cat /sys/class/hwmon/hwmon0/temp1_input 2>/dev/null || echo 0)

# 5. Power (instantaneous sensor check only)
POWER_W=0
if command -v sensors >/dev/null 2>&1; then
    PWR=$(sensors 2>/dev/null | awk '/power[0-9]/ {print $2; exit}' | grep -o '[0-9.]*')
    if [ ! -z "$PWR" ]; then POWER_W=$PWR; fi
fi

# 6. Uptime
UPTIME_STR=$(awk '{s=int($1); d=int(s/86400); h=int((s%86400)/3600); m=int((s%3600)/60); if(d>0) printf "%dd ", d; if(h>0) printf "%dh ", h; printf "%dm", m}' /proc/uptime 2>/dev/null)
if [ -z "$UPTIME_STR" ]; then
    UPTIME_STR=$(uptime 2>/dev/null | sed -e 's/.*up //' -e 's/,.*//')
fi

# 7. Network bytes (sum of physical interfaces)
NET_BYTES=$(awk '$1 ~ /^(eth|en|wl|bond|br|wlan)/ {rx+=$2; tx+=$10} END {print (rx+0) "|" (tx+0)}' /proc/net/dev 2>/dev/null)
if [ -z "$NET_BYTES" ]; then
    NET_BYTES="0|0"
fi

echo "NETSCOPE_METRICS|$RAM_PCT|$CPU_PCT|$DISK_PCT|$TEMP|$POWER_W|$UPTIME_STR|$NET_BYTES"
EOF;

    $output = trim((string)$ssh->exec($linux_cmd));

    // Case 1: Standard Linux parsing matched
    if (strpos($output, 'NETSCOPE_METRICS|') !== false) {
        try { $ssh->disconnect(); } catch (\Throwable $t) {}
        $clean_line = substr($output, strpos($output, 'NETSCOPE_METRICS|') + strlen('NETSCOPE_METRICS|'));
        $parts = explode('|', $clean_line);
        
        $ram = isset($parts[0]) && is_numeric($parts[0]) ? (float)$parts[0] : 0;
        $cpu = isset($parts[1]) && is_numeric($parts[1]) ? (float)$parts[1] : 0;
        $disk = isset($parts[2]) && is_numeric($parts[2]) ? (float)$parts[2] : 0;
        
        $raw_temp = isset($parts[3]) && is_numeric($parts[3]) ? (int)$parts[3] : 0;
        $temp = $raw_temp > 1000 ? round($raw_temp / 1000, 1) : $raw_temp;
        
        $power = isset($parts[4]) && is_numeric($parts[4]) ? (float)$parts[4] : 0;
        $uptime = isset($parts[5]) && trim($parts[5]) !== '' ? trim($parts[5]) : 'N/A';
        $rx_bytes = isset($parts[6]) && is_numeric($parts[6]) ? (float)$parts[6] : 0;
        $tx_bytes = isset($parts[7]) && is_numeric($parts[7]) ? (float)$parts[7] : 0;
        
        json_response([
            'ram' => round($ram, 1),
            'cpu' => round($cpu, 1),
            'disk' => round($disk, 1),
            'temp' => round($temp, 1),
            'power' => round($power, 1),
            'uptime' => $uptime,
            'rx_bytes' => $rx_bytes,
            'tx_bytes' => $tx_bytes
        ]);
    }

    // Always disconnect first session before attempting any secondary probe
    try { $ssh->disconnect(); } catch (\Throwable $t) {}

    // Case 2: Target is MikroTik RouterOS (only attempt if target output or asset hints at RouterOS)
    $cat = $asset['category'] ?? '';
    $is_likely_ros = (stripos($cat, 'mikrotik') !== false || stripos($cat, 'routeros') !== false || stripos($output, 'bad command') !== false);
    
    if ($is_likely_ros) {
        try {
            $ros_ssh = new SSH2($host, $port, 5);
            $ros_ssh->setTimeout(5);
            if ($ros_ssh->login($username, $password)) {
                $ros_test = (string)$ros_ssh->exec('/system resource print');
                try { $ros_ssh->disconnect(); } catch (\Throwable $t) {}

                if (strpos($ros_test, 'cpu-load') !== false || strpos($ros_test, 'uptime') !== false) {
                    preg_match('/cpu-load:\s*([0-9]+)%/i', $ros_test, $m_cpu);
                    preg_match('/uptime:\s*([^\r\n]+)/i', $ros_test, $m_uptime);
                    preg_match('/total-memory:\s*([0-9.]+)\s*([KMG]i?B)/i', $ros_test, $m_tot_mem);
                    preg_match('/free-memory:\s*([0-9.]+)\s*([KMG]i?B)/i', $ros_test, $m_free_mem);
                    preg_match('/total-hdd-space:\s*([0-9.]+)\s*([KMG]i?B)/i', $ros_test, $m_tot_hdd);
                    preg_match('/free-hdd-space:\s*([0-9.]+)\s*([KMG]i?B)/i', $ros_test, $m_free_hdd);

                    $cpu = isset($m_cpu[1]) ? (float)$m_cpu[1] : 0;
                    $uptime = isset($m_uptime[1]) ? trim($m_uptime[1]) : 'N/A';

                    $ram = 0;
                    if (!empty($m_tot_mem[1]) && !empty($m_free_mem[1])) {
                        $tot = (float)$m_tot_mem[1];
                        $free = (float)$m_free_mem[1];
                        if ($tot > 0) $ram = round((1 - ($free / $tot)) * 100, 1);
                    }

                    $disk = 0;
                    if (!empty($m_tot_hdd[1]) && !empty($m_free_hdd[1])) {
                        $tot_d = (float)$m_tot_hdd[1];
                        $free_d = (float)$m_free_hdd[1];
                        if ($tot_d > 0) $disk = round((1 - ($free_d / $tot_d)) * 100, 1);
                    }

                    json_response([
                        'ram' => $ram,
                        'cpu' => $cpu,
                        'disk' => $disk,
                        'temp' => 0,
                        'power' => 0,
                        'uptime' => $uptime,
                        'rx_bytes' => 0,
                        'tx_bytes' => 0
                    ]);
                }
            }
        } catch (\Throwable $t) {}
    }

    // Case 3: Output could not be parsed - report honest error rather than fake zeroes
    $err_snippet = substr(trim(strip_tags($output)), 0, 150);
    json_response([
        'error' => 'Connected via SSH, but could not collect telemetry. Target shell response: ' . ($err_snippet ?: 'Empty response')
    ], 502);

} catch (\Throwable $e) {
    if (isset($ssh)) {
        try { $ssh->disconnect(); } catch (\Throwable $t) {}
    }
    $msg = $e->getMessage();
    if (strpos($msg, 'integer was expected') !== false || strpos($msg, 'Connection closed') !== false) {
        $msg = "Connection timed out or dropped by {$host}:{$port}. Check that the SSH daemon is running and allows connections from this container.";
    }
    json_response(['error' => 'SSH Error: ' . $msg], 500);
}
