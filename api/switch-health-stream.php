<?php
/**
 * IPManager Pro - Live Switch Health SSE Stream
 * 
 * Server-Sent Events endpoint that streams live CPU/Memory data
 * directly from SNMP to the browser in real-time.
 * 
 * Usage: GET /api/switch-health-stream.php?id=<switch_id>
 */

require_once '../includes/config.php';
require_once '../includes/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}
session_write_close(); // Release session lock to prevent blocking other requests

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit;
}

// Fetch switch info
$db = get_db_connection();
$stmt = $db->prepare("SELECT * FROM switches WHERE id = ?");
$stmt->execute([$id]);
$switch = $stmt->fetch();

if (!$switch) {
    http_response_code(404);
    exit;
}

// SSE Headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable Nginx buffering if behind proxy

// Disable output buffering
if (ob_get_level()) ob_end_clean();

$ip        = $switch['ip_addr'];
$community = $switch['community'];

if (!extension_loaded('snmp')) {
    // If SNMP not available, just stream DB data
    $send_db_data = true;
}

snmp_set_quick_print(1);
snmp_set_valueretrieval(SNMP_VALUE_PLAIN);

/**
 * Poll CPU and Memory directly via SNMP
 */
function poll_live_health(string $ip, string $community, string $model): array {
    $cpu = 0;
    $mem = 0;

    if ($model === 'MikroTik') {
        // Try MikroTik specific OID first
        $cpu = @snmp2_get($ip, $community, ".1.3.6.1.4.1.14988.1.1.3.11.0");
        
        // RAM Detection
        $total_mem = @snmp2_get($ip, $community, ".1.3.6.1.4.1.14988.1.1.3.8.0");
        $used_mem  = @snmp2_get($ip, $community, ".1.3.6.1.4.1.14988.1.1.3.9.0");

        // Fallback to Generic Host Resources if MikroTik OIDs are empty/zero
        if (!$total_mem || $total_mem == 0) {
            $storage_types = @snmp2_real_walk($ip, $community, ".1.3.6.1.2.1.25.2.3.1.2");
            if ($storage_types) {
                foreach ($storage_types as $oid => $type) {
                    // Check for hrStorageRam ( .1.3.6.1.2.1.25.2.1.2 )
                    if (strpos($type, ".1.3.6.1.2.1.25.2.1.2") !== false) {
                        $parts = explode('.', $oid);
                        $idx = end($parts);
                        $total_mem = @snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.5.$idx");
                        $used_mem  = @snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.6.$idx");
                        
                        // If we got valid-looking numbers, use them
                        if ($total_mem && (int)$total_mem > 0) break;
                    }
                }
            }
        }
        
        // Final fallback: try standard index 65536 directly
        if (!$total_mem || (int)$total_mem == 0) {
            $total_mem = @snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.5.65536");
            $used_mem  = @snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.6.65536");
        }
        if ($total_mem > 0) $mem = round(((int)$used_mem / (int)$total_mem) * 100);

        // CPU Fallback for multicore
        if ($cpu === false || $cpu === "" || (int)$cpu >= 100) {
            $cores = @snmp2_real_walk($ip, $community, ".1.3.6.1.2.1.25.3.3.1.2");
            if ($cores && count($cores) > 0) {
                $cpu_sum = 0; $count = 0;
                foreach ($cores as $val) { 
                    $v = (int)trim(str_replace(['INTEGER: ', '"'], '', $val));
                    $cpu_sum += $v; 
                    $count++; 
                }
                $avg_cpu = $count > 0 ? round($cpu_sum / $count) : 0;
                
                // If specific OID gave 100 but walk gave something else, use walk
                if ((int)$cpu >= 100 && $avg_cpu < 100) $cpu = $avg_cpu;
                elseif ($cpu === false || $cpu === "") $cpu = $avg_cpu;
            }
        }
    } elseif ($model === 'Cisco') {
        $cpu = (int)@snmp2_get($ip, $community, ".1.3.6.1.4.1.9.9.109.1.1.1.1.5.1");

        $mem_used = (int)@snmp2_get($ip, $community, ".1.3.6.1.4.1.9.9.48.1.1.1.5.1");
        $mem_free = (int)@snmp2_get($ip, $community, ".1.3.6.1.4.1.9.9.48.1.1.1.6.1");
        if ($mem_used > 0) $mem = round(($mem_used / ($mem_used + $mem_free)) * 100);

    } elseif ($model === 'Juniper') {
        // Juniper Networks
        $cpu = @snmp2_get($ip, $community, ".1.3.6.1.4.1.2636.3.1.13.1.8.1.1.0"); // jnxOperatingCPU
        $mem = @snmp2_get($ip, $community, ".1.3.6.1.4.1.2636.3.1.13.1.11.1.1.0"); // jnxOperatingBuffer
        if ($cpu === false || (int)$mem == 0) {
            $generic = poll_live_health_generic($ip, $community);
            if ($cpu === false) $cpu = $generic['cpu'];
            if ((int)$mem == 0) $mem = $generic['mem'];
        }
    } elseif ($model === 'HP' || $model === 'Aruba' || $model === 'H3C') {
        // HP / H3C / Aruba
        $cpu = @snmp2_get($ip, $community, ".1.3.6.1.4.1.25506.2.6.1.1.1.1.6.1"); // hh3cEntityExtCpuUsage
        $mem = @snmp2_get($ip, $community, ".1.3.6.1.4.1.25506.2.6.1.1.1.1.8.1"); // hh3cEntityExtMemUsage
        if ($cpu === false || (int)$mem == 0) {
            $generic = poll_live_health_generic($ip, $community);
            if ($cpu === false) $cpu = $generic['cpu'];
            if ((int)$mem == 0) $mem = $generic['mem'];
        }
    } elseif ($model === 'Alcatel-Lucent') {
        // Alcatel-Lucent OmniSwitch (AOS 6/7/8)
        // healthDeviceCpuLatest (1min avg): .1.3.6.1.4.1.6486.800.1.2.1.16.1.1.1.13.0
        $cpu = @snmp2_get($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.16.1.1.1.13.0");
        if ($cpu === false) {
            // Try AOS 8 specific / alternate path
            $cpu = @snmp2_get($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.16.1.1.1.13.0");
        }
        
        // healthDeviceMemoryLatest
        $mem = @snmp2_get($ip, $community, ".1.3.6.1.4.1.6486.800.1.2.1.16.1.1.1.10.0");
        if ($mem === false) {
            $mem = @snmp2_get($ip, $community, ".1.3.6.1.4.1.6486.801.1.2.1.16.1.1.1.10.0");
        }

        // Final fallback for Alcatel (Generic MIB)
        if ($cpu === false || $cpu === "" || $mem === false || (int)$mem == 0) {
            $generic = poll_live_health_generic($ip, $community);
            if ($cpu === false || $cpu === "") $cpu = $generic['cpu'];
            if ($mem === false || (int)$mem == 0) $mem = $generic['mem'];
        }
    } else {
        $generic = poll_live_health_generic($ip, $community);
        $cpu = $generic['cpu'];
        $mem = $generic['mem'];
    }

    return [
        'cpu' => min(100, max(0, $cpu)),
        'mem' => min(100, max(0, $mem)),
    ];
}

/**
 * Generic Fallback Polling (RFC 2790)
 */
function poll_live_health_generic(string $ip, string $community): array {
    $cpu = 0;
    $mem = 0;
    $cores = @snmp2_real_walk($ip, $community, ".1.3.6.1.2.1.25.3.3.1.2");
    if ($cores) {
        $cpu_sum = 0; $count = 0;
        foreach ($cores as $val) { $cpu_sum += (int)$val; $count++; }
        $cpu = $count > 0 ? round($cpu_sum / $count) : 0;
    }
    $total_mem = (int)@snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.5.65536");
    $used_mem  = (int)@snmp2_get($ip, $community, ".1.3.6.1.2.1.25.2.3.1.6.65536");
    if ($total_mem > 0) $mem = round(((int)$used_mem / (int)$total_mem) * 100);

    return ['cpu' => $cpu, 'mem' => $mem];
}

// Stream loop - send a new event every 5 seconds
$iteration = 0;
while (true) {
    if (connection_aborted()) break;

    if (!empty($send_db_data)) {
        // Fallback: read from DB
        $row = $db->prepare("SELECT cpu_usage, memory_usage, last_poll FROM switches WHERE id = ?");
        $row->execute([$id]);
        $data = $row->fetch();
        $payload = [
            'cpu'       => (int)($data['cpu_usage'] ?? 0),
            'mem'       => (int)($data['memory_usage'] ?? 0),
            'last_poll' => $data['last_poll'] ? date('H:i:s', strtotime($data['last_poll'])) : '-',
            'source'    => 'db',
        ];
    } else {
        // Live SNMP with Caching to support multiple concurrent viewers
        $redis = get_redis_connection();
        $cache_key = "switch_health_latest_{$id}";
        $cached_val = ($redis) ? $redis->get($cache_key) : null;
        
        if ($cached_val) {
            $health = json_decode($cached_val, true);
            $source = 'redis_cache';
        } else {
            $health = poll_live_health($ip, $community, $switch['model'] ?? 'Generic');
            // Cache results for 4 seconds (Stream polls every 5s)
            if ($redis) $redis->setex($cache_key, 4, json_encode($health));
            $source = 'live_snmp';
        }

        $payload = [
            'cpu'       => $health['cpu'],
            'mem'       => $health['mem'],
            'last_poll' => date('H:i:s'),
            'source'    => $source,
        ];
    }

    // SSE format: "data: <json>\n\n"
    echo "data: " . json_encode($payload) . "\n\n";
    flush();

    $iteration++;
    sleep(5); // Poll every 5 seconds
}
