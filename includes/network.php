<?php
/**
 * IP Network Helpers
 */

function cidr_to_range($cidr) {
    list($subnet, $mask) = explode('/', $cidr);
    $start = ip2long($subnet);
    $total = pow(2, (32 - $mask));
    $end = $start + $total - 1;
    return [$start, $end];
}

function long2ip_safe($long) {
    return long2ip($long);
}

function get_ip_usage_count($db, $subnet_id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM ip_addresses WHERE subnet_id = ?");
    $stmt->execute([$subnet_id]);
    return $stmt->fetchColumn();
}

/**
 * Ping an IP address
 */
function ping_ip($ip, $attempts = 1, $timeout_ms = 200) {
    $ip = normalize_ipv4($ip);
    if (!$ip) {
        return false;
    }

    $attempts = max(1, (int)$attempts);
    $timeout_ms = max(100, (int)$timeout_ms);

    for ($i = 0; $i < $attempts; $i++) {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = "ping -n 1 -w {$timeout_ms} {$ip}";
    } else {
            $timeout_seconds = max(1, (int)ceil($timeout_ms / 1000));
            $cmd = "ping -c 1 -W {$timeout_seconds} {$ip}";
        }

        exec($cmd, $output, $result);
        if ($result === 0) {
            return true;
        }

        if ($i < $attempts - 1) {
            usleep(120000);
        }
    }

    return false;
}

/**
 * Quick TCP port check.
 */
function check_port($ip, $port, $timeout = 0.15, $attempts = 1) {
    $ip = normalize_ipv4($ip);
    $port = (int)$port;
    if (!$ip || $port < 1 || $port > 65535) {
        return false;
    }

    $attempts = max(1, (int)$attempts);
    for ($i = 0; $i < $attempts; $i++) {
        $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if ($socket) {
            fclose($socket);
            return true;
        }

        if ($i < $attempts - 1) {
            usleep(100000);
        }
    }

    return false;
}

/**
 * Validate and normalize IPv4 string.
 */
function normalize_ipv4($ip) {
    $normalized = filter_var(trim((string)$ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    return $normalized ?: null;
}

/**
 * Validate and normalize MAC into AA:BB:CC:DD:EE:FF
 */
function normalize_mac($mac) {
    if (!$mac) {
        return null;
    }

    $clean = strtoupper(preg_replace('/[^0-9A-F]/i', '', (string)$mac));
    if (strlen($clean) !== 12) {
        return null;
    }

    if (!preg_match('/^[0-9A-F]{12}$/', $clean)) {
        return null;
    }

    return implode(':', str_split($clean, 2));
}

/**
 * Parse arp -a output into IP => MAC map.
 */
function parse_arp_table($arp_output = null) {
    if ($arp_output === null) {
        exec("arp -a", $arp_output);
    }

    $entries = [];
    foreach ($arp_output as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        // Windows: 192.168.1.1          00-11-22-33-44-55     dynamic
        if (preg_match('/\b((?:\d{1,3}\.){3}\d{1,3})\b\s+([0-9a-fA-F:-]{17})\b/i', $line, $matches)) {
            $ip = normalize_ipv4($matches[1]);
            $mac = normalize_mac($matches[2]);
            if ($ip && $mac) {
                $entries[$ip] = $mac;
                continue;
            }
        }

        // Linux/macOS style: ? (192.168.1.1) at 00:11:22:33:44:55 [ether] on eth0
        if (preg_match('/\(\s*?((?:\d{1,3}\.){3}\d{1,3})\)\s+at\s+([0-9a-fA-F:-]{17})\b/i', $line, $matches)) {
            $ip = normalize_ipv4($matches[1]);
            $mac = normalize_mac($matches[2]);
            if ($ip && $mac) {
                $entries[$ip] = $mac;
            }
        }
    }

    return $entries;
}

/**
 * Refresh ARP cache and return parsed map.
 */
function refresh_arp_map() {
    exec("arp -a", $arp_output);
    return parse_arp_table($arp_output);
}

/**
 * Batch ARP pre-seeder: fire-and-forget pings to fill ARP cache.
 * Launches concurrent ping processes for an IP range, then reads ARP table.
 * This is the KEY performance optimization - instead of pinging IPs one by one,
 * we fire all pings simultaneously and let the OS populate the ARP cache.
 */
function preseed_arp_batch($start_long, $end_long, $timeout_ms = 200) {
    $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $processes = [];
    $batch_size = 32; // Fire 32 pings at a time to avoid process flood
    
    for ($i = $start_long; $i <= $end_long; $i += $batch_size) {
        $batch_end = min($i + $batch_size - 1, $end_long);
        
        for ($j = $i; $j <= $batch_end; $j++) {
            $ip = long2ip($j);
            if ($is_windows) {
                // Fire-and-forget ping on Windows
                $cmd = "ping -n 1 -w {$timeout_ms} {$ip} > NUL 2>&1";
                $processes[] = popen("start /B " . $cmd, "r");
            } else {
                $timeout_s = max(1, (int)ceil($timeout_ms / 1000));
                $cmd = "ping -c 1 -W {$timeout_s} {$ip} > /dev/null 2>&1 &";
                pclose(popen($cmd, "r"));
            }
        }
        
        // Brief pause between batches to prevent network congestion
        usleep(50000); // 50ms
    }
    
    // Close Windows process handles
    foreach ($processes as $proc) {
        if (is_resource($proc)) {
            pclose($proc);
        }
    }
    
    // Wait for ARP cache to populate from the ping responses
    $total_ips = $end_long - $start_long + 1;
    $wait_ms = min(3000, max(500, $total_ips * 8)); // Scale wait: 8ms per IP, max 3s
    usleep($wait_ms * 1000);
    
    // Now read the populated ARP table
    return refresh_arp_map();
}

/**
 * Detect whether nmap exists on scanner host.
 */
function has_nmap_binary() {
    static $checked = false;
    static $available = false;

    if ($checked) {
        return $available;
    }

    $checked = true;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec("where nmap", $out, $code);
    } else {
        exec("command -v nmap", $out, $code);
    }
    $available = ($code === 0);
    return $available;
}

/**
 * Optional nmap host-up fallback (disabled by default).
 */
function nmap_detect_host($ip) {
    $ip = normalize_ipv4($ip);
    if (!$ip || !defined('ENABLE_NMAP_FALLBACK') || !ENABLE_NMAP_FALLBACK || !has_nmap_binary()) {
        return false;
    }

    $target = escapeshellarg($ip);
    // Use -O for OS detection if running as admin (may need sudo/admin privilege)
    // For now, keep it simple with -sV or -O try
    $cmd = "nmap -sn -n --host-timeout 2s {$target}";
    exec($cmd, $output, $code);
    if ($code !== 0 || empty($output)) {
        return false;
    }

    foreach ($output as $line) {
        if (stripos($line, 'Host is up') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Advanced Nmap OS Fingerprinting
 */
function nmap_fingerprint_os($ip) {
    $ip = normalize_ipv4($ip);
    if (!$ip || !has_nmap_binary()) return 'Unknown';

    $target = escapeshellarg($ip);
    // This requires high privilege on Windows/Linux but we try
    $cmd = "nmap -O --osscan-guess --max-os-tries 1 {$target} 2>&1";
    exec($cmd, $output);
    
    $os_guess = 'Unknown';
    foreach ($output as $line) {
        if (preg_match('/OS details: (.+)/', $line, $matches)) {
            $os_guess = $matches[1];
            break;
        }
        if (preg_match('/Aggressive OS guesses: (.+)/', $line, $matches)) {
            $os_guess = explode(',', $matches[1])[0]; // Take first guess
            break;
        }
    }
    return $os_guess;
}

/**
 * FAST host detection optimized for Web UI scanning.
 * Uses short-circuit logic: ARP-first, then ping, then minimal port scan.
 * Avoids redundant ARP refreshes (caller provides pre-seeded map).
 */
function fast_detect_host_signals($ip, &$arp_map) {
    $ip = normalize_ipv4($ip);
    if (!$ip) {
        return ['ping' => false, 'arp' => false, 'port' => false, 'nmap' => false, 'active' => false];
    }

    $signals = [
        'ping' => false,
        'arp' => isset($arp_map[$ip]),
        'port' => false,
        'nmap' => false,
        'active' => false
    ];

    // SHORT-CIRCUIT 1: If ARP cache already has this IP, it's alive.
    // ARP is the most reliable L2 signal - no need to ping.
    if ($signals['arp']) {
        // Quick single ping just to confirm (very fast since ARP is resolved)
        $signals['ping'] = ping_ip($ip, 1, 200);
        $signals['active'] = true;
        return $signals;
    }

    // No ARP hit - do a quick ping
    $signals['ping'] = ping_ip($ip, 1, 250);

    // SHORT-CIRCUIT 2: If ping succeeds, skip port scan
    if ($signals['ping']) {
        // Refresh ARP to get MAC (ping should have populated it)
        $fresh = refresh_arp_map();
        if (!empty($fresh)) {
            $arp_map = array_merge($arp_map, $fresh);
            $signals['arp'] = isset($arp_map[$ip]);
        }
        $signals['active'] = true;
        return $signals;
    }

    // No ping response - try minimal port scan (only top 4 ports)
    $ports = [80, 443, 445, 22];
    foreach ($ports as $port) {
        if (check_port($ip, $port, 0.12, 1)) {
            $signals['port'] = true;
            break;
        }
    }

    if ($signals['port']) {
        // Port open means host is alive
        $fresh = refresh_arp_map();
        if (!empty($fresh)) {
            $arp_map = array_merge($arp_map, $fresh);
            $signals['arp'] = isset($arp_map[$ip]);
        }
        $signals['active'] = true;
        return $signals;
    }

    // Nmap fallback only if enabled and all else failed
    if (defined('ENABLE_NMAP_FALLBACK') && ENABLE_NMAP_FALLBACK && !$signals['ping'] && !$signals['port']) {
        $signals['nmap'] = nmap_detect_host($ip);
        if ($signals['nmap']) {
            $signals['active'] = true;
            return $signals;
        }
    }

    // Determine if remote subnet (relax ARP requirement)
    $is_remote = false;
    try {
        $local_ip = gethostbyname(gethostname());
        $local_parts = explode('.', $local_ip);
        $target_parts = explode('.', $ip);
        if ($local_parts[0] !== $target_parts[0] || $local_parts[1] !== $target_parts[1]) {
            $is_remote = true;
        }
    } catch(Exception $e) {}

    // For remote subnets, ping/port alone is sufficient (no local ARP possible)
    if ($is_remote && ($signals['ping'] || $signals['port'])) {
        $signals['active'] = true;
    }

    return $signals;
}

/**
 * Multi-probe host activity detection to reduce misses.
 * Full version used by cron/background scanner.
 */
function detect_host_signals($ip, &$arp_map) {
    $ip = normalize_ipv4($ip);
    if (!$ip) {
        return ['ping' => false, 'arp' => false, 'port' => false, 'nmap' => false, 'active' => false];
    }

    $signals = [
        'ping' => ping_ip($ip, 2, 300),
        'arp' => isset($arp_map[$ip]),
        'port' => false,
        'nmap' => false,
        'active' => false
    ];

    $ports = [80, 443, 445, 22]; // Top priority ports only for faster scan
    if (defined('DISCOVERY_AGGRESSIVE_MODE') && DISCOVERY_AGGRESSIVE_MODE) {
        $ports = [80, 443, 22, 445, 135, 139, 3389, 53, 161, 8000, 8080, 8443, 554, 37777];
    }
    foreach ($ports as $port) {
        if (check_port($ip, $port, 0.15, 1)) {
            $signals['port'] = true;
            break;
        }
    }

    // Nmap Fallback (if enabled and other signals failed or for extra confirmation)
    if (defined('ENABLE_NMAP_FALLBACK') && ENABLE_NMAP_FALLBACK && !$signals['ping'] && !$signals['port']) {
        $signals['nmap'] = nmap_detect_host($ip);
    }

    // If we got any positive signal but ARP is still missing, refresh ARP once.
    if (($signals['ping'] || $signals['port'] || $signals['nmap']) && !$signals['arp']) {
        $fresh = refresh_arp_map();
        if (!empty($fresh)) {
            $arp_map = $fresh;
            $signals['arp'] = isset($arp_map[$ip]);
        }
    }

    // Final lightweight recheck path for uncertain hosts.
    if (!$signals['ping'] && !$signals['arp'] && !$signals['port'] && !$signals['nmap']) {
        $signals['ping'] = ping_ip($ip, 1, 650);
        if ($signals['ping']) {
            $fresh = refresh_arp_map();
            if (!empty($fresh)) {
                $arp_map = $fresh;
                $signals['arp'] = isset($arp_map[$ip]);
            }
        }
    }

    $is_remote = false;
    try {
        $local_ip = gethostbyname(gethostname());
        $local_parts = explode('.', $local_ip);
        $target_parts = explode('.', $ip);
        // Robust check: if first 3 octets match, it's definitely local (most common /24)
        // If not, and they are in different class A/B private ranges, it's remote.
        if ($local_parts[0] !== $target_parts[0] || $local_parts[1] !== $target_parts[1]) {
            $is_remote = true;
        }
    } catch(Exception $e) {}

    // Final signal consolidation with Ghost IP Prevention.
    $signals['active'] = false;
    if ($signals['nmap']) {
        $signals['active'] = true;
    } elseif ($signals['arp'] && ($signals['ping'] || $signals['port'])) {
        $signals['active'] = true;
    } elseif (!$signals['arp'] && ($signals['ping'] || $signals['port']) && $is_remote) {
        // Allow remote subnet scans to detect hosts via open service port or ping without local ARP.
        $signals['active'] = true;
    }

    return $signals;
}

/**
 * Intensive detection for uncertain hosts (used before marking offline).
 */
function intensive_detect_host($ip, &$arp_map) {
    echo "Running intensive detection for $ip...\n";
    $ip = normalize_ipv4($ip);
    if (!$ip) return ['active' => false];

    // Stage 1: Multiple High-Wait Pings
    if (ping_ip($ip, 4, 800)) {
        return ['active' => true, 'source' => 'intensive_ping'];
    }

    // Stage 2: Scan more common ports
    $ports = [80, 443, 22, 445, 135, 139, 3389, 53, 161, 8000, 8080, 554, 137, 138, 21, 23, 25, 110, 143, 993, 3306, 5432, 6379];
    foreach ($ports as $port) {
        if (check_port($ip, $port, 0.6, 1)) {
            return ['active' => true, 'source' => 'intensive_port'];
        }
    }

    // Stage 3: Nmap Fallback (if available)
    if (has_nmap_binary()) {
        $target = escapeshellarg($ip);
        // Try specialized host-up check
        $cmd = "nmap -sn -PS22,80,443,445 --host-timeout 5s {$target}";
        exec($cmd, $output, $code);
        foreach ($output as $line) {
            if (stripos($line, 'Host is up') !== false) {
                return ['active' => true, 'source' => 'intensive_nmap'];
            }
        }
    }

    // Stage 4: Force ARP refresh after traffic injection
    // Sending a packet to a high port to trigger ARP resolution
    @fsockopen($ip, 49999, $errno, $errstr, 0.1); 
    $fresh_arp = refresh_arp_map();
    if (isset($fresh_arp[$ip])) {
        $arp_map = $fresh_arp;
        return ['active' => true, 'source' => 'intensive_arp'];
    }

    return ['active' => false];
}

/**
 * Resolve hostname using reverse DNS and normalize result.
 */
function resolve_hostname($ip) {
    // DNS can be very slow if it hangs.
    return resolve_hostname_with_retry($ip, 1, 50000); 
}

/**
 * Normalize hostname text to safe DB value.
 */
function normalize_hostname($hostname) {
    $hostname = trim((string)$hostname, " \t\n\r\0\x0B.");
    if ($hostname === '') {
        return '';
    }

    $hostname = strtolower($hostname);
    if (preg_match('/\s/', $hostname)) {
        return '';
    }

    // Keep host label + fqdn safe characters only.
    if (!preg_match('/^[a-z0-9._-]+$/', $hostname)) {
        return '';
    }

    return substr($hostname, 0, 100);
}

/**
 * Resolve hostname with lightweight retry.
 */
function resolve_hostname_with_retry($ip, $attempts = 2, $sleep_microseconds = 120000) {
    $attempts = max(1, (int)$attempts);
    for ($try = 1; $try <= $attempts; $try++) {
        $hostname = @gethostbyaddr($ip);
        if ($hostname && $hostname !== $ip) {
            return normalize_hostname($hostname);
        }

        if ($try < $attempts) {
            usleep(max(0, (int)$sleep_microseconds));
        }
    }

    return '';
}

/**
 * Determine if address is usable host (skip network/broadcast on /30 and larger).
 */
function is_usable_host_long($ip_long, $start_long, $end_long, $mask) {
    $mask = (int)$mask;
    if ($mask <= 30 && ($ip_long === $start_long || $ip_long === $end_long)) {
        return false;
    }
    return true;
}

/**
 * Calculate confidence score and return source labels.
 */
function calculate_discovery_confidence($flags) {
    $weights = [
        'snmp' => 35,
        'arp' => 30,
        'nmap' => 25,
        'ping' => 20,
        'port' => 10,
        'dns' => 5
    ];

    $score = 0;
    $sources = [];
    foreach ($weights as $key => $weight) {
        if (!empty($flags[$key])) {
            $score += $weight;
            $sources[] = $key;
        }
    }

    if ($score < 5) {
        $score = 5;
    } elseif ($score > 100) {
        $score = 100;
    }

    return [
        'score' => $score,
        'sources' => implode(',', $sources)
    ];
}

/**
 * Check if IP is in ARP table (fallback for ICMP-blocking hosts)
 */
function is_ip_in_arp($ip, $arp_output = null) {
    $ip = normalize_ipv4($ip);
    if (!$ip) {
        return false;
    }

    $arp_map = parse_arp_table($arp_output);
    return isset($arp_map[$ip]);
}

/**
 * Get MAC address from ARP table for a given IP
 */
function get_mac_from_arp($ip, $arp_output = null) {
    $ip = normalize_ipv4($ip);
    if (!$ip) {
        return null;
    }

    $arp_map = parse_arp_table($arp_output);
    return $arp_map[$ip] ?? null;
}

/**
 * Get Vendor from MAC address prefix
 */
function get_vendor_by_mac($mac) {
    $mac = normalize_mac($mac);
    if (!$mac) return null;
    $prefix = substr(str_replace(':', '', $mac), 0, 6);
    
    // Expanded local mapping of common vendors
    $vendors = [
        '000C29' => 'VMware', '000569' => 'VMware', '005056' => 'VMware',
        '00249B' => 'Google', 'BCF5AC' => 'Google', '20DFB9' => 'Google',
        '0017F2' => 'Apple', 'D8D1CB' => 'Apple', 'F8E903' => 'Apple',
        'B827EB' => 'Raspberry Pi', 'DCDECA' => 'Raspberry Pi',
        '00155D' => 'Microsoft (Hyper-V)',
        '000A19' => 'Cisco', '000142' => 'Cisco', '000143' => 'Cisco',
        '000C41' => 'Cisco', '000E83' => 'Cisco', '00408C' => 'Cisco',
        'E0ACF1' => 'Cisco', 'CC46D6' => 'Cisco', '64A0E7' => 'Cisco',
        '000E7F' => 'HP', '00110A' => 'HP', '001708' => 'HP',
        '001C23' => 'Dell', '00219B' => 'Dell', '000AC7' => 'Dell', 'B083FE' => 'Dell', '24B657' => 'Dell',
        '000D0B' => 'Tp-Link', '30B5C2' => 'Tp-Link', '34DAB7' => 'Tp-Link', '98DA33' => 'Tp-Link',
        '00156D' => 'Ubiquiti', '24A43C' => 'Ubiquiti', 'F09FC2' => 'Ubiquiti', 'B4FBE4' => 'Ubiquiti',
        '001132' => 'Synology', '001132' => 'Synology',
        '000C29' => 'VMware', '000569' => 'VMware', '005056' => 'VMware',
        '002686' => 'Realtek', 'E470B8' => 'Realtek',
        '0009B0' => 'D-Link', '18622C' => 'D-Link',
        '001D0F' => 'MikroTik', '4C5E0C' => 'MikroTik', '6C3B6B' => 'MikroTik', 'D4CA6D' => 'MikroTik'
    ];

    if (isset($vendors[strtoupper($prefix)])) {
        return $vendors[strtoupper($prefix)];
    }

    // Optional: Try public API (only if you have internet access and it's fast)
    // $vendor = @file_get_contents("https://api.macvendors.com/" . urlencode($mac));
    // return $vendor ? $vendor : 'Unknown';
    
    return 'Generic / Unknown';
}

/**
 * Find the subnet ID that contains a given IP address.
 */
function find_subnet_for_ip($db, $ip) {
    if (!$ip) return null;
    $ip_long = ip2long($ip);
    if ($ip_long === false) return null;

    static $subnets_cache = null;
    if ($subnets_cache === null) {
        $subnets_cache = $db->query("SELECT id, subnet, mask FROM subnets")->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($subnets_cache as $s) {
        list($start, $end) = cidr_to_range($s['subnet'] . '/' . $s['mask']);
        if ($ip_long >= $start && $ip_long <= $end) {
            return $s['id'];
        }
    }
    return null;
}
