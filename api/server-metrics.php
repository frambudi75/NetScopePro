<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/asset.helper.php';

use phpseclib3\Net\SSH2;

session_start();
if (!isset($_SESSION['user_id'])) {
    json_response(['error' => 'Unauthorized'], 401);
}
if (!is_admin()) {
    json_response(['error' => 'Forbidden'], 403);
}

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
$port = $asset['port'] ?: 22;

try {
    $ssh = new SSH2($host, $port, 5);
    if (!$ssh->login($username, $password)) {
        json_response(['error' => 'SSH Login Failed'], 401);
    }
    
    $command = <<<'EOF'
RAM_PCT=$(free | awk '/Mem:/ {printf "%.1f", $3/$2 * 100}')
CPU_IDLE=$(top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\1/" | awk '{print $1}')
if [ -z "$CPU_IDLE" ]; then
    CPU_PCT=$(cat /proc/loadavg | awk '{print $1}')
else
    CPU_PCT=$(awk "BEGIN {print 100 - $CPU_IDLE}" 2>/dev/null)
    if [ -z "$CPU_PCT" ]; then
        CPU_PCT=0
    fi
fi
DISK_PCT=$(df / | awk 'NR==2 {print $5}' | sed 's/%//')
TEMP=$(cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null || echo 0)

POWER_W=0
if command -v ipmitool >/dev/null 2>&1; then
    PWR=$(sudo -n ipmitool dcmi power reading 2>/dev/null | grep -i "Instantaneous power" | awk '{print $4}')
    if [ ! -z "$PWR" ]; then POWER_W=$PWR; fi
fi
if [ "$POWER_W" = "0" ] && command -v sensors >/dev/null 2>&1; then
    PWR=$(sensors 2>/dev/null | awk '/power[0-9]/ {print $2; exit}' | grep -o '[0-9.]*')
    if [ ! -z "$PWR" ]; then POWER_W=$PWR; fi
fi

UPTIME_STR=$(uptime -p 2>/dev/null | sed 's/^up //' || echo "N/A")

IFACE=$(ip route 2>/dev/null | awk '/default/ {print $5; exit}')
if [ -z "$IFACE" ]; then IFACE="eth0"; fi
RX_BYTES=$(cat /sys/class/net/$IFACE/statistics/rx_bytes 2>/dev/null || echo 0)
TX_BYTES=$(cat /sys/class/net/$IFACE/statistics/tx_bytes 2>/dev/null || echo 0)

echo "$RAM_PCT|$CPU_PCT|$DISK_PCT|$TEMP|$POWER_W|$UPTIME_STR|$RX_BYTES|$TX_BYTES"
EOF;

    $output = trim($ssh->exec($command));
    $parts = explode('|', $output);
    
    $ram = isset($parts[0]) && is_numeric($parts[0]) ? (float)$parts[0] : 0;
    $cpu = isset($parts[1]) && is_numeric($parts[1]) ? (float)$parts[1] : 0;
    $disk = isset($parts[2]) && is_numeric($parts[2]) ? (float)$parts[2] : 0;
    $temp = isset($parts[3]) && is_numeric($parts[3]) ? (int)$parts[3] / 1000 : 0;
    $power = isset($parts[4]) && is_numeric($parts[4]) ? (float)$parts[4] : 0;
    $uptime = isset($parts[5]) ? trim($parts[5]) : 'N/A';
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
    
} catch (\Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}
