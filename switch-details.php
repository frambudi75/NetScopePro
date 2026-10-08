<?php
/**
 * IPManager Pro - Switch Details
 * Displays full hardware info and port-to-IP mapping for a specific switch.
 */

@ini_set('memory_limit', '512M');

require_once 'includes/config.php';
require_once 'includes/db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

$db = get_db_connection();
$id = (int)($_GET['id'] ?? 0);

// Fetch switch data
$stmt = $db->prepare("SELECT * FROM switches WHERE id = ?");
$stmt->execute([$id]);
$switch = $stmt->fetch();

if (!$switch) {
    header('Location: switches');
    exit;
}

// Fetch port mapping joined with IP addresses
$query = "
    SELECT 
        m.mac_addr, 
        m.port_name, 
        m.vlan_id,
        m.vlan_name,
        m.port_status,
        m.stp_state,
        m.port_type,
        m.port_speed,
        m.port_alias,
        m.sfp_vendor,
        m.sfp_part,
        m.sfp_serial,
        m.sfp_rx_power,
        m.sfp_tx_power,
        m.updated_at as last_seen_on_port,
        ip.ip_addr,
        ip.hostname,
        ip.vendor
    FROM switch_port_map m
    LEFT JOIN (
        SELECT mac_addr, MAX(ip_addr) as ip_addr, MAX(hostname) as hostname, MAX(vendor) as vendor
        FROM ip_addresses
        WHERE mac_addr IS NOT NULL AND mac_addr != ''
        GROUP BY mac_addr
    ) ip ON m.mac_addr = ip.mac_addr
    WHERE m.switch_id = ?
    ORDER BY m.port_name ASC, m.mac_addr ASC
";
$stmt = $db->prepare($query);
$stmt->execute([$id]);
$ports = $stmt->fetchAll();

// Fetch all tagged VLANs for this switch, grouped by port_name
$tagged_vlans_per_port = [];
try {
    $tagged_vlans_query = "
        SELECT
            port_name,
            GROUP_CONCAT(CONCAT(vlan_id, ':', IFNULL(vlan_name, '')) ORDER BY vlan_id ASC SEPARATOR ',') AS tagged_vlans_str
        FROM switch_port_vlans
        WHERE switch_id = ?
        GROUP BY port_name
    ";
    $stmt_tagged_vlans = $db->prepare($tagged_vlans_query);
    $stmt_tagged_vlans->execute([$id]);
    foreach ($stmt_tagged_vlans->fetchAll() as $row) {
        $tagged_vlans_per_port[$row['port_name']] = $row['tagged_vlans_str'];
    }
} catch (\Exception $e) {
    $tagged_vlans_per_port = [];
}

// Pre-calculate MAC count and group ports by physical interface name
$port_mac_counts = [];
$grouped_ports = [];
foreach ($ports as $p) {
    $pname = $p['port_name'];
    $is_dummy_mac = empty($p['mac_addr']) || stripos($p['mac_addr'], 'PORT:') !== false || str_starts_with($p['mac_addr'], '00:00:00:') || $p['mac_addr'] === 'FF:FF:FF:FF:FF:FF';
    if (!$is_dummy_mac) {
        $port_mac_counts[$pname] = ($port_mac_counts[$pname] ?? 0) + 1;
    }
    if (!isset($grouped_ports[$pname])) {
        $grouped_ports[$pname] = [
            'port_name'         => $pname,
            'port_status'       => $p['port_status'] ?? null,
            'stp_state'         => $p['stp_state'] ?? null,
            'port_type'         => $p['port_type'] ?? null,
            'port_speed'        => $p['port_speed'] ?? null,
            'port_alias'        => $p['port_alias'] ?? null,
            'sfp_vendor'        => $p['sfp_vendor'] ?? null,
            'sfp_part'          => $p['sfp_part'] ?? null,
            'sfp_serial'        => $p['sfp_serial'] ?? null,
            'sfp_rx_power'      => $p['sfp_rx_power'] ?? null,
            'sfp_tx_power'      => $p['sfp_tx_power'] ?? null,
            'vlan_id'           => $p['vlan_id'] ?? null,
            'vlan_name'         => $p['vlan_name'] ?? null,
            'last_seen_on_port' => $p['last_seen_on_port'] ?? null,
            'devices'           => []
        ];
    } else {
        // Inherit non-null vlan_id, vlan_name, or up status from subsequent device records
        if (empty($grouped_ports[$pname]['vlan_id']) && !empty($p['vlan_id'])) {
            $grouped_ports[$pname]['vlan_id'] = $p['vlan_id'];
            $grouped_ports[$pname]['vlan_name'] = $p['vlan_name'] ?? $grouped_ports[$pname]['vlan_name'];
        }
        if (($grouped_ports[$pname]['port_status'] ?? 'down') !== 'up' && ($p['port_status'] ?? '') === 'up') {
            $grouped_ports[$pname]['port_status'] = 'up';
        }
    }
    if (!$is_dummy_mac) {
        $grouped_ports[$pname]['devices'][] = [
            'mac_addr'          => $p['mac_addr'],
            'ip_addr'           => $p['ip_addr'] ?? null,
            'hostname'          => $p['hostname'] ?? null,
            'vendor'            => $p['vendor'] ?? null,
            'vlan_id'           => $p['vlan_id'] ?? null,
            'vlan_name'         => $p['vlan_name'] ?? null,
            'last_seen_on_port' => $p['last_seen_on_port'] ?? null
        ];
    }
}

// Canonical Port Consolidation:
// If a switch has structured interfaces (e.g. "1/1/22" or "1/22"), and ALSO unmapped fallback
// records like "Port 22" or "22", merge "Port 22" directly into the structured interface and remove "Port 22".
$num_to_canonical = [];
foreach ($grouped_ports as $pname => $port) {
    if (preg_match('/(?:^|[A-Za-z\/\-\s])(\d+)$/', $pname, $m)) {
        $num = (int)$m[1];
        if (strpos($pname, '/') !== false && !isset($num_to_canonical[$num])) {
            $num_to_canonical[$num] = $pname;
        }
    }
}

if (!empty($num_to_canonical)) {
    foreach ($grouped_ports as $pname => $port) {
        if (preg_match('/^(?:Port\s*)?(\d+)$/i', $pname, $m)) {
            $num = (int)$m[1];
            if (isset($num_to_canonical[$num]) && $num_to_canonical[$num] !== $pname) {
                $canonical_name = $num_to_canonical[$num];
                // Merge devices into canonical interface
                foreach ($port['devices'] as $dev) {
                    $grouped_ports[$canonical_name]['devices'][] = $dev;
                }
                // Adopt VLAN ID if canonical lacked one
                if (empty($grouped_ports[$canonical_name]['vlan_id']) && !empty($port['vlan_id'])) {
                    $grouped_ports[$canonical_name]['vlan_id'] = $port['vlan_id'];
                    $grouped_ports[$canonical_name]['vlan_name'] = $port['vlan_name'];
                }
                if (($grouped_ports[$canonical_name]['port_status'] ?? 'down') !== 'up' && ($port['port_status'] ?? '') === 'up') {
                    $grouped_ports[$canonical_name]['port_status'] = 'up';
                }
                // Remove fallback duplicate
                unset($grouped_ports[$pname]);
            }
        }
    }
}

// Ensure every physical port has a valid native VLAN ID (defaulting to VLAN 1)
foreach ($grouped_ports as $pname => &$port) {
    if (empty($port['vlan_id'])) {
        foreach ($port['devices'] as $dev) {
            if (!empty($dev['vlan_id'])) {
                $port['vlan_id'] = $dev['vlan_id'];
                $port['vlan_name'] = $dev['vlan_name'];
                break;
            }
        }
    }
    if (empty($port['vlan_id'])) {
        $port['vlan_id'] = 1;
        $port['vlan_name'] = 'VLAN 1';
    }
}
unset($port);

// Natural sort ports so Port 2 comes before Port 10, GigabitEthernet0/1 comes before GigabitEthernet0/10
uksort($grouped_ports, 'strnatcasecmp');

/**
 * Filter out virtual, SVI, loopback, and internal ports from the physical switch faceplate
 */
function is_physical_faceplate_port($port_name, $port_type = null) {
    $p = trim((string)$port_name);
    if (empty($p)) return false;

    // 1. Exclude dummy or scanner placeholder tags
    if (stripos($p, 'PORT:') !== false || stripos($p, 'dummy') !== false) {
        return false;
    }

    // 2. Exclude virtual/LAG/SVI interface name prefixes across vendors
    // e.g. VLAN 904, Vlan 600, Loopback0, Null0, Tunnel1, CPU, mgmt, Internal, bridge1, LinkAgg, Trk, Po, Port-channel
    if (preg_match('/^(?:vlan|vl|loopback|lo|null|tunnel|tun|tap|bridge|br|cpu|mgmt|management|internal|bcm|stack|docker|veth|linkagg|trk|port-channel|po\d+|lag|bond)/i', $p)) {
        return false;
    }

    // 3. Exclude non-physical port types if reported by SNMP
    if (!empty($port_type)) {
        $pt = strtolower((string)$port_type);
        if (preg_match('/(?:vlan|virtual|loopback|tunnel|other|software|internal|ieee8023adLag)/i', $pt)) {
            return false;
        }
    }

    // 4. Exclude ports with numbers > 52 (e.g. LAG 54, 56, 59, 61, 62 or SVIs > 52)
    // Hardware chassis for standard enterprise switches have max 48 copper + 4 SFP = 52 physical ports.
    if (preg_match('/^(?:Port\s*)?(\d+)$/i', $p, $m)) {
        if ((int)$m[1] > 52) {
            return false;
        }
    }
    if (preg_match('/[\/:](\d+)$/', $p, $m)) {
        if ((int)$m[1] > 52) {
            return false;
        }
    }

    return true;
}

$faceplate_copper = [];
$faceplate_sfp = [];
$faceplate_online_count = 0;
$faceplate_total_count = 0;
$seen_faceplate_labels = [];

foreach ($grouped_ports as $pname => $port) {
    // Only physical front-panel interfaces belong on the hardware chassis faceplate
    if (!is_physical_faceplate_port($pname, $port['port_type'] ?? null)) {
        continue;
    }

    // Extract clean display label across all switch vendors (Alcatel, Cisco, HP/Aruba, Juniper, Huawei, MikroTik, Extreme, Dell)
    $label = (string)$pname;
    if (preg_match('/^([A-Za-z]\d{1,2})$/', $pname, $m)) {
        // HP ProCurve slot letter notation (e.g. A1..A24, B1..B24)
        $label = strtoupper($m[1]);
    } elseif (preg_match('/[\/:](\d+)(?:[^\d]*)$/', $pname, $m)) {
        // Multi-tier slash or colon notation (Alcatel 1/24, Cisco Gi1/0/24, Aruba 1/1/48, Juniper ge-0/0/1, Extreme 1:24)
        $label = $m[1];
    } elseif (preg_match('/(?:ether|ge|fe|fa|gi|te|xe|et|po|trk|port|eth|sfp|combo|ae)[\s\/\-\.]*(\d+)/i', $pname, $m)) {
        // Named interfaces (MikroTik ether3-to-Sw, Cisco Po1, Juniper ae0, Aruba Trk1, sfp1)
        $label = $m[1];
    } elseif (preg_match('/(\d+)(?:[^\d]*)$/', $pname, $m)) {
        // Trailing digits (LAN 5, Port10, eth12)
        $label = $m[1];
    } elseif (preg_match('/(\d+)/', $pname, $m)) {
        // Any digits fallback
        $label = $m[1];
    }
    if (mb_strlen($label) > 4) {
        $label = mb_substr($label, 0, 4);
    }

    // Never render the same physical port number twice on the faceplate
    if (isset($seen_faceplate_labels[$label])) {
        continue;
    }
    $seen_faceplate_labels[$label] = true;

    $faceplate_total_count++;
    $raw_status = $port['port_status'] ?? 'down';
    $status = (!empty($raw_status) && is_string($raw_status)) ? strtolower(trim($raw_status)) : 'down';
    if ($status === 'up') {
        $faceplate_online_count++;
    }

    $port_num = is_numeric($label) ? (int)$label : 0;
    $is_sfp = (!empty($port['sfp_vendor']) && $port['sfp_vendor'] !== '0')
           || !empty($port['sfp_rx_power'])
           || preg_match('/(sfp|fiber|optical|uplink|ten)/i', $pname)
           || ($port_num >= 49 && $port_num <= 52); // Ports 49-52 on 48-port switch are SFP+ bays
    $tagged_str = $tagged_vlans_per_port[$pname] ?? '';

    $port_entry = [
        'id'          => $pname,
        'label'       => $label,
        'name'        => $pname,
        'status'      => $status,
        'stp_state'   => $port['stp_state'] ?? null,
        'port_type'   => $port['port_type'] ?? null,
        'port_speed'  => $port['port_speed'] ?? null,
        'port_alias'  => $port['port_alias'] ?? null,
        'vlan_id'     => $port['vlan_id'] ?? 1,
        'vlan_name'   => $port['vlan_name'] ?? ('VLAN ' . ($port['vlan_id'] ?? 1)),
        'tagged_vlans'=> $tagged_str,
        'sfp_vendor'  => $port['sfp_vendor'] ?? null,
        'sfp_part'    => $port['sfp_part'] ?? null,
        'sfp_serial'  => $port['sfp_serial'] ?? null,
        'sfp_rx_power'=> $port['sfp_rx_power'] ?? null,
        'sfp_tx_power'=> $port['sfp_tx_power'] ?? null,
        'last_seen'   => $port['last_seen_on_port'] ?? null,
        'devices'     => $port['devices'],
        'dev_count'   => count($port['devices']),
        'is_sfp'      => $is_sfp
    ];

    if ($is_sfp) {
        $faceplate_sfp[] = $port_entry;
    } else {
        $faceplate_copper[] = $port_entry;
    }
}

// Numerical sort for faceplate buttons so ports render in order (1..48 and 49..52)
usort($faceplate_copper, function($a, $b) {
    return (int)$a['label'] <=> (int)$b['label'];
});
usort($faceplate_sfp, function($a, $b) {
    return (int)$a['label'] <=> (int)$b['label'];
});

// Split copper into 2 physical rows (e.g. 1..24 top, 25..48 bottom for 48-port switches)
$copper_count = count($faceplate_copper);
$copper_half = ($copper_count === 48) ? 24 : (int)ceil($copper_count / 2);
$copper_row1 = array_slice($faceplate_copper, 0, $copper_half);
$copper_row2 = array_slice($faceplate_copper, $copper_half);

// Split SFP transceivers into 2 physical rows (e.g. 49,50 top; 51,52 bottom for 4 SFP bays)
$sfp_count = count($faceplate_sfp);
$sfp_half = (int)ceil($sfp_count / 2);
$sfp_row1 = array_slice($faceplate_sfp, 0, $sfp_half);
$sfp_row2 = array_slice($faceplate_sfp, $sfp_half);

// Pick initial port to inspect from physical ports (first UP port or first available copper/SFP)
$initial_port_id = null;
foreach ($faceplate_copper as $p) {
    if ($p['status'] === 'up') {
        $initial_port_id = $p['id'];
        break;
    }
}
if ($initial_port_id === null) {
    foreach ($faceplate_sfp as $p) {
        if ($p['status'] === 'up') {
            $initial_port_id = $p['id'];
            break;
        }
    }
}
if ($initial_port_id === null) {
    $initial_port_id = $faceplate_copper[0]['id'] ?? ($faceplate_sfp[0]['id'] ?? null);
}

// JSON payload for high-speed client-side inspector
$faceplate_json_data = [];
foreach ($grouped_ports as $pname => $port) {
    $faceplate_json_data[$pname] = [
        'name'        => $pname,
        'status'      => $port['port_status'] ?? 'down',
        'stp_state'   => $port['stp_state'] ?? null,
        'type'        => $port['port_type'] ?? null,
        'speed'       => $port['port_speed'] ?? null,
        'alias'       => $port['port_alias'] ?? null,
        'vlan_id'     => $port['vlan_id'] ?? 1,
        'vlan_name'   => $port['vlan_name'] ?? ('VLAN ' . ($port['vlan_id'] ?? 1)),
        'tagged_vlans'=> $tagged_vlans_per_port[$pname] ?? '',
        'sfp_vendor'  => $port['sfp_vendor'] ?? null,
        'sfp_part'    => $port['sfp_part'] ?? null,
        'sfp_serial'  => $port['sfp_serial'] ?? null,
        'sfp_rx_power'=> $port['sfp_rx_power'] ?? null,
        'sfp_tx_power'=> $port['sfp_tx_power'] ?? null,
        'last_seen'   => $port['last_seen_on_port'] ?? null,
        'devices'     => $port['devices'],
        'dev_count'   => count($port['devices']),
        'is_sfp'      => (!empty($port['sfp_vendor']) && $port['sfp_vendor'] !== '0') || !empty($port['sfp_rx_power']) || preg_match('/(sfp|fiber|optical|uplink|ten)/i', $pname)
    ];
}

$page_title = "Switch: " . $switch['name'];
include 'includes/header.php';
?>

<style>
.btn-traffic-pill {
    padding: 2px 7px;
    font-size: 0.65rem;
    font-weight: 700;
    background: rgba(56, 189, 248, 0.12);
    color: #38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.3);
    border-radius: 4px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    transition: all 0.2s;
}
.btn-traffic-pill:hover {
    background: rgba(56, 189, 248, 0.25);
    border-color: #38bdf8;
    color: #fff;
}
.btn-device-collapse {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 9px;
    font-size: 0.75rem;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    background: rgba(56, 189, 248, 0.12);
    color: #38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.35);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-device-collapse:hover {
    background: rgba(56, 189, 248, 0.25);
    border-color: #38bdf8;
    color: #ffffff;
    box-shadow: 0 0 10px rgba(56, 189, 248, 0.2);
}
.port-device-drawer {
    animation: drawerFadeIn 0.2s ease;
}
@keyframes drawerFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
.drawer-chevron {
    transition: transform 0.2s ease;
}
.vlan-chip {
    display: inline-flex;
    align-items: center;
    padding: 1px 6px;
    background: rgba(56, 189, 248, 0.12);
    color: #38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.28);
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    cursor: default;
    transition: all 0.15s ease;
}
.vlan-chip:hover {
    background: rgba(56, 189, 248, 0.25);
    border-color: #38bdf8;
    color: #fff;
}
.btn-vlan-more {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 1px 7px;
    background: var(--surface-light, rgba(255, 255, 255, 0.05));
    color: var(--text-muted, #94a3b8);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 4px;
    font-size: 0.68rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-vlan-more:hover {
    color: #fff;
    border-color: #38bdf8;
    background: rgba(56, 189, 248, 0.15);
}
.vlan-popover-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    width: 320px;
    max-width: 90vw;
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    box-shadow: 0 12px 28px -4px rgba(0, 0, 0, 0.75), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
    z-index: 999;
    overflow: hidden;
    animation: vlanPopIn 0.15s ease;
}
@keyframes vlanPopIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
.vlan-popover-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 10px;
    background: rgba(255, 255, 255, 0.04);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 0.72rem;
    color: #f1f5f9;
}
.vlan-popover-close {
    background: transparent;
    border: none;
    color: #94a3b8;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
}
.vlan-popover-close:hover {
    color: #fff;
}
.vlan-popover-body {
    max-height: 220px;
    overflow-y: auto;
    padding: 6px;
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.vlan-popover-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 3px 6px;
    border-radius: 4px;
    font-size: 0.72rem;
    transition: background 0.1s;
}
.vlan-popover-row:hover {
    background: rgba(255, 255, 255, 0.05);
}
.vlan-id-badge {
    display: inline-block;
    padding: 1px 6px;
    background: rgba(56, 189, 248, 0.15);
    color: #38bdf8;
    border-radius: 4px;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.68rem;
    min-width: 44px;
    text-align: center;
    flex-shrink: 0;
}
.vlan-name-text {
    color: #cbd5e1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}

/* Switch Faceplate Visualizer Styles */
.faceplate-tag {
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 1.5px;
    color: #10b981;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}
.faceplate-online-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    background: rgba(16, 185, 129, 0.08);
    border: 1px solid rgba(16, 185, 129, 0.35);
    color: #10b981;
}
.faceplate-view-toggle {
    display: inline-flex;
    background: var(--surface-light);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 3px;
    gap: 3px;
}
.faceplate-toggle-btn {
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.faceplate-toggle-btn:hover {
    color: var(--text);
}
.faceplate-toggle-btn.active {
    background: var(--primary);
    color: #ffffff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.faceplate-chassis {
    background: #080d19;
    border: 1px solid #1e293b;
    border-radius: 10px;
    padding: 14px 18px;
    box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.7), 0 4px 14px rgba(0, 0, 0, 0.25);
    overflow-x: auto;
    overflow-y: hidden;
    max-width: 100%;
    width: 100%;
    box-sizing: border-box;
    scrollbar-width: thin;
    scrollbar-color: #334155 #0b1120;
    -webkit-overflow-scrolling: touch;
}
.faceplate-chassis::-webkit-scrollbar {
    height: 7px;
}
.faceplate-chassis::-webkit-scrollbar-track {
    background: #0b1120;
    border-radius: 4px;
}
.faceplate-chassis::-webkit-scrollbar-thumb {
    background: #334155;
    border-radius: 4px;
}
.faceplate-chassis::-webkit-scrollbar-thumb:hover {
    background: #38bdf8;
}
.faceplate-bezel-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    flex-wrap: wrap;
    gap: 6px;
}
.faceplate-bezel-title {
    color: #38bdf8;
    font-weight: 700;
    letter-spacing: 0.5px;
}
.faceplate-bezel-hint {
    color: #64748b;
    font-size: 0.72rem;
}
.faceplate-matrix {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    min-width: max-content;
    padding: 6px 0 10px 0;
}
.faceplate-bay {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.faceplate-row {
    display: flex;
    gap: 8px;
    align-items: center;
}
.fp-port-btn {
    width: 44px;
    min-width: 44px;
    max-width: 44px;
    height: 42px;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    user-select: none;
    font-family: 'JetBrains Mono', monospace;
    font-weight: 800;
    font-size: 0.88rem;
    transition: all 0.15s ease;
    border: 1px solid transparent;
    padding: 3px 2px;
    position: relative;
    box-sizing: border-box;
    overflow: hidden;
}
.fp-port-btn:hover {
    filter: brightness(1.22);
    transform: translateY(-1px);
}
.fp-port-up {
    background: #059669;
    color: #ffffff;
    border-color: #10b981;
    box-shadow: 0 1px 3px rgba(16, 185, 129, 0.3);
}
.fp-port-down {
    background: #1e293b;
    color: #64748b;
    border-color: #334155;
}
.fp-port-dormant {
    background: #854d0e;
    color: #fef08a;
    border-color: #eab308;
}
.fp-port-blocking {
    background: #991b1b;
    color: #fee2e2;
    border-color: #ef4444;
}
.fp-port-sfp {
    border-style: dashed !important;
}
.fp-port-active {
    background: #d97706 !important;
    color: #ffffff !important;
    border: 2px solid #fbbf24 !important;
    box-shadow: 0 0 16px rgba(245, 158, 11, 0.75) !important;
    transform: scale(1.06);
    z-index: 2;
}
.fp-port-num {
    line-height: 1;
    font-size: 0.88rem;
    font-weight: 800;
    letter-spacing: -0.3px;
}
.fp-port-led {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    margin-bottom: 3px;
}
.fp-port-up .fp-port-led {
    background: #34d399;
    box-shadow: 0 0 4px #34d399;
}
.fp-port-down .fp-port-led {
    background: #334155;
}
.fp-port-active .fp-port-led {
    background: #fef08a;
    box-shadow: 0 0 5px #fef08a;
}
.fp-port-blocking .fp-port-led {
    background: #f87171;
    box-shadow: 0 0 4px #f87171;
}
.faceplate-divider {
    width: 2px;
    height: 82px;
    background: repeating-linear-gradient(to bottom, #334155, #334155 4px, transparent 4px, transparent 8px);
    margin: 0 4px;
}
.port-inspector-panel {
    margin-top: 1.25rem;
    background: var(--surface-light);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 1.25rem;
    animation: inspectorFadeIn 0.2s ease;
}
@keyframes inspectorFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive Grid & Faceplate Layout */
.grid-side-detail {
    display: grid;
    grid-template-columns: 320px minmax(0, 1fr);
    gap: 1.5rem;
    align-items: start;
    min-width: 0;
    max-width: 100%;
}
.grid-side-detail > * {
    min-width: 0;
    max-width: 100%;
}
#switch-ports-main-card {
    min-width: 0;
    max-width: 100%;
    width: 100%;
}
#faceplate-view-container {
    min-width: 0;
    max-width: 100%;
    width: 100%;
}
.inspector-grid {
    display: grid;
    grid-template-columns: minmax(280px, 1fr) minmax(320px, 1.35fr);
    gap: 1.25rem;
    min-width: 0;
    max-width: 100%;
}
.inspector-grid > * {
    min-width: 0;
    max-width: 100%;
}

@media (max-width: 1100px) {
    .grid-side-detail {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 860px) {
    .inspector-grid {
        grid-template-columns: 1fr;
    }
    .faceplate-chassis {
        padding: 10px 12px;
    }
    .fp-port-btn {
        width: 38px;
        min-width: 38px;
        max-width: 38px;
        height: 38px;
    }
    .fp-port-num {
        font-size: 0.78rem;
    }
}
</style>

<div style="margin-bottom: 2rem;">
    <nav style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1rem;">
        <a href="switches" style="color: var(--primary); text-decoration: none;">Switches</a> / <?php echo htmlspecialchars($switch['name']); ?>
    </nav>
    <div class="page-header">
        <div>
            <h1 style="font-size: 1.75rem; margin: 0;"><?php echo htmlspecialchars($switch['name']); ?></h1>
            <p style="color: var(--text-muted); font-family: monospace; font-size: 1.125rem; margin-top: 0.25rem;"><?php echo htmlspecialchars($switch['ip_addr']); ?></p>
        </div>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <?php if (is_admin()): ?>
            <button class="btn btn-secondary" onclick="location.href='cron_switch_poll?id=<?php echo $id; ?>'">
                <i data-lucide="refresh-cw" style="width: 16px;"></i> Force Poll
            </button>
            <?php endif; ?>
            <button class="btn btn-primary" onclick="window.print()">
                <i data-lucide="printer" style="width: 16px;"></i> Export Report
            </button>
        </div>
    </div>
</div>

<div class="grid-side-detail">
    <!-- Hardware Status Sidebar -->
    <div class="card">
        <h3 style="font-size: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
            Hardware Health
            <span id="live-badge" style="font-size: 0.65rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: var(--border); color: var(--text-muted); letter-spacing: 0.5px;">LOADING...</span>
        </h3>
        
        <div style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.875rem;">
                <span>CPU Load</span>
                <span id="cpu-val" style="font-weight: 700;"><?php echo (int)($switch['cpu_usage'] ?? 0); ?>%</span>
            </div>
            <div style="height: 10px; background: var(--border); border-radius: 5px; overflow: hidden;">
                <div id="cpu-bar" style="width: <?php echo (int)($switch['cpu_usage'] ?? 0); ?>%; height: 100%; background: var(--primary); transition: width 0.8s ease;"></div>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.875rem;">
                <span>Memory Usage</span>
                <span id="mem-val" style="font-weight: 700;"><?php echo (int)($switch['memory_usage'] ?? 0); ?>%</span>
            </div>
            <div style="height: 10px; background: var(--border); border-radius: 5px; overflow: hidden;">
                <div id="mem-bar" style="width: <?php echo (int)($switch['memory_usage'] ?? 0); ?>%; height: 100%; background: var(--success); transition: width 0.8s ease;"></div>
            </div>
        </div>

        <?php
            $temp_val = $switch['temperature'] ?? null;
            $temp_pct = $temp_val !== null ? min(100, max(0, (int)$temp_val)) : 0;
            $temp_color = ($temp_val !== null && $temp_val > 65) ? 'var(--danger)' : (($temp_val !== null && $temp_val > 50) ? '#f59e0b' : '#10b981');
        ?>
        <div style="margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.875rem;">
                <span style="display: flex; align-items: center; gap: 4px;">
                    <i data-lucide="thermometer" style="width: 14px; height: 14px; color: <?php echo $temp_color; ?>;"></i>
                    Chassis Temp
                </span>
                <span id="temp-val" style="font-weight: 700;">
                    <?php echo ($temp_val !== null) ? (int)$temp_val . '°C' : '<span style="color:var(--text-muted);font-weight:400;font-size:0.75rem;">Unsupported</span>'; ?>
                </span>
            </div>
            <div style="height: 10px; background: var(--border); border-radius: 5px; overflow: hidden;">
                <div id="temp-bar" style="width: <?php echo $temp_pct; ?>%; height: 100%; background: <?php echo $temp_color; ?>; transition: width 0.8s ease;"></div>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1rem; font-size: 0.875rem;">
            <div style="display: flex; justify-content: space-between;">
                <span style="color:var(--text-muted)">Model:</span>
                <span style="font-weight: 600; text-align: right;"><?php echo htmlspecialchars($switch['model'] ?? 'Unknown'); ?></span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color:var(--text-muted)">Uptime:</span>
                <span style="font-weight: 600; text-align: right;"><?php echo htmlspecialchars($switch['uptime'] ?? '-'); ?></span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color:var(--text-muted)">Temperature:</span>
                <span id="temp-badge" style="font-weight: 600; text-align: right;">
                    <?php if ($temp_val !== null): ?>
                        <span style="color: <?php echo $temp_color; ?>;"><?php echo (int)$temp_val; ?>°C</span>
                    <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 0.78rem;">Unsupported / N/A</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if (isset($switch['total_ports']) && $switch['total_ports'] > 0): ?>
            <div style="display: flex; justify-content: space-between;">
                <span style="color:var(--text-muted)">Ports:</span>
                <span style="font-weight: 600; text-align: right;">
                    <span style="color: var(--success);"><?php echo (int)$switch['active_ports']; ?></span> / <?php echo (int)$switch['total_ports']; ?> up
                </span>
            </div>
            <?php endif; ?>
            <div style="display: flex; justify-content: space-between;">
                <span style="color:var(--text-muted)">Last Updated:</span>
                <span id="last-poll-val" style="font-weight: 600; text-align: right;"><?php echo $switch['last_poll'] ? date('H:i:s, d M', strtotime($switch['last_poll'])) : 'Never'; ?></span>
            </div>
        </div>

        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--text);">L2 Topology &amp; STP</span>
                <?php if (!empty($switch['loop_detected'])): ?>
                    <span style="font-size: 0.65rem; font-weight: 800; background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.4); padding: 2px 6px; border-radius: 4px;">LOOP DETECTED</span>
                <?php else: ?>
                    <span style="font-size: 0.65rem; font-weight: 700; background: rgba(16, 185, 129, 0.15); color: var(--success); padding: 2px 6px; border-radius: 4px;">STABLE</span>
                <?php endif; ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; flex-direction: column; gap: 0.4rem;">
                <div style="display: flex; justify-content: space-between;">
                    <span>STP Protocol:</span>
                    <span style="font-weight: 600; color: var(--text);"><?php echo htmlspecialchars($switch['stp_protocol'] ?: 'None / Unknown'); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>Topology Changes:</span>
                    <span style="font-weight: 600; color: var(--text);"><?php echo (int)($switch['stp_topology_changes'] ?? 0); ?> events</span>
                </div>
                <?php if (!empty($switch['loop_details'])): ?>
                <div style="font-size: 0.7rem; color: #f87171; background: rgba(239,68,68,0.1); border-radius: 4px; padding: 6px; margin-top: 4px; line-height: 1.4;">
                    <?php echo htmlspecialchars($switch['loop_details']); ?>
                </div>
                <?php endif; ?>
                <div style="margin-top: 0.5rem;">
                    <a href="tools?action=loop&target=<?php echo urlencode($switch['ip_addr']); ?>" class="btn btn-secondary" style="width: 100%; font-size: 0.75rem; justify-content: center; padding: 5px 10px;">
                        <i data-lucide="refresh-cw" style="width: 12px;"></i> Run Loop Diagnostic
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($switch['system_info'])): ?>
            <div style="margin-top: 2rem;">
                <h4 style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 1px; margin-bottom: 0.5rem;">System Information</h4>
                <div style="font-size: 0.75rem; color: var(--text-muted); background: var(--surface-light); padding: 1rem; border-radius: 8px; line-height: 1.5; white-space: pre-wrap; word-break: break-all;"><?php echo htmlspecialchars($switch['system_info']); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Switch Front Panel Faceplate & Interface Inventory -->
    <div class="card" id="switch-ports-main-card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; flex-wrap: wrap; gap: 0.75rem;">
            <div>
                <div class="faceplate-tag"><?php echo htmlspecialchars($switch['model'] ? 'MANAGED NETWORK SWITCH' : 'SWITCH FRONT PANEL'); ?></div>
                <h3 style="font-size: 1.25rem; margin: 0; font-weight: 700; color: var(--text);">
                    <?php echo htmlspecialchars($switch['name']); ?>
                    <span style="font-weight: 400; color: var(--text-muted); font-size: 1rem;">· <?php echo htmlspecialchars($switch['model'] ?: 'Generic'); ?></span>
                </h3>
                <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $faceplate_online_count; ?> of <?php echo $faceplate_total_count; ?> ports online • <?php echo count($ports); ?> MAC entries</span>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <span class="faceplate-online-badge">
                    <i data-lucide="check-circle-2" style="width: 13px; height: 13px;"></i>
                    <?php echo $faceplate_online_count; ?>/<?php echo $faceplate_total_count; ?> Online
                </span>
                <div class="faceplate-view-toggle">
                    <button type="button" class="faceplate-toggle-btn active" id="btn-mode-faceplate" onclick="switchViewMode('faceplate')">
                        <i data-lucide="layout-grid" style="width: 14px; height: 14px;"></i> Faceplate Inspector
                    </button>
                    <button type="button" class="faceplate-toggle-btn" id="btn-mode-table" onclick="switchViewMode('table')">
                        <i data-lucide="list" style="width: 14px; height: 14px;"></i> Full Table
                    </button>
                </div>
            </div>
        </div>

        <!-- Mode 1: Interactive Faceplate Visualizer & Port Inspector -->
        <div id="faceplate-view-container">
            <!-- Physical Bezel Container -->
            <div class="faceplate-chassis">
                <div class="faceplate-bezel-bar">
                    <span class="faceplate-bezel-title">
                        <i data-lucide="cpu" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 4px;"></i>
                        Interfaces (1 - <?php echo $faceplate_total_count; ?>)
                    </span>
                    <span class="faceplate-bezel-hint">
                        <i data-lucide="mouse-pointer" style="width: 11px; height: 11px; display: inline-block; vertical-align: middle; margin-right: 2px;"></i>
                        Click port to inspect details &amp; connected hosts
                    </span>
                </div>

                <div class="faceplate-matrix">
                    <?php if (empty($grouped_ports)): ?>
                        <div style="padding: 1.5rem; color: var(--text-muted); font-size: 0.85rem; font-family: monospace;">No ports discovered. Run Force Poll to discover switch interfaces.</div>
                    <?php else: ?>
                        <!-- Copper / Ethernet Bay -->
                        <?php if (!empty($faceplate_copper)): ?>
                            <div class="faceplate-bay">
                                <!-- Row 1 -->
                                <div class="faceplate-row">
                                    <?php foreach ($copper_row1 as $p): ?>
                                        <?php
                                            $raw_st = strtolower((string)($p['status'] ?? 'down'));
                                            $pStatusClass = match($raw_st) {
                                                'up' => (!empty($p['stp_state']) && $p['stp_state'] === 'blocking' ? 'fp-port-blocking' : 'fp-port-up'),
                                                'dormant' => 'fp-port-dormant',
                                                default => 'fp-port-down'
                                            };
                                            $p_title = (string)$p['name'] . ' (' . strtoupper($raw_st) . ')' . (!empty($p['alias']) ? ' - ' . $p['alias'] : '');
                                        ?>
                                        <button type="button" 
                                                class="fp-port-btn <?php echo $pStatusClass; ?>" 
                                                data-port-id="<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="inspectPort('<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                title="<?php echo htmlspecialchars($p_title, ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="fp-port-led"></span>
                                            <span class="fp-port-num"><?php echo htmlspecialchars((string)$p['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <!-- Row 2 -->
                                <div class="faceplate-row">
                                    <?php foreach ($copper_row2 as $p): ?>
                                        <?php
                                            $raw_st = strtolower((string)($p['status'] ?? 'down'));
                                            $pStatusClass = match($raw_st) {
                                                'up' => (!empty($p['stp_state']) && $p['stp_state'] === 'blocking' ? 'fp-port-blocking' : 'fp-port-up'),
                                                'dormant' => 'fp-port-dormant',
                                                default => 'fp-port-down'
                                            };
                                            $p_title = (string)$p['name'] . ' (' . strtoupper($raw_st) . ')' . (!empty($p['alias']) ? ' - ' . $p['alias'] : '');
                                        ?>
                                        <button type="button" 
                                                class="fp-port-btn <?php echo $pStatusClass; ?>" 
                                                data-port-id="<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="inspectPort('<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                title="<?php echo htmlspecialchars($p_title, ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="fp-port-led"></span>
                                            <span class="fp-port-num"><?php echo htmlspecialchars((string)$p['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Uplink / SFP Optical Bay -->
                        <?php if (!empty($faceplate_sfp)): ?>
                            <div class="faceplate-divider" title="Optical / SFP Transceiver Bay"></div>
                            <div class="faceplate-bay">
                                <!-- SFP Row 1 -->
                                <div class="faceplate-row">
                                    <?php foreach ($sfp_row1 as $p): ?>
                                        <?php
                                            $raw_st = strtolower((string)($p['status'] ?? 'down'));
                                            $pStatusClass = match($raw_st) {
                                                'up' => (!empty($p['stp_state']) && $p['stp_state'] === 'blocking' ? 'fp-port-blocking' : 'fp-port-up'),
                                                'dormant' => 'fp-port-dormant',
                                                default => 'fp-port-down'
                                            };
                                            $p_title = 'SFP Optical: ' . (string)$p['name'] . ' (' . strtoupper($raw_st) . ')' . (!empty($p['alias']) ? ' - ' . $p['alias'] : '');
                                        ?>
                                        <button type="button" 
                                                class="fp-port-btn fp-port-sfp <?php echo $pStatusClass; ?>" 
                                                data-port-id="<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="inspectPort('<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                title="<?php echo htmlspecialchars($p_title, ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="fp-port-led"></span>
                                            <span class="fp-port-num"><?php echo htmlspecialchars((string)$p['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <!-- SFP Row 2 -->
                                <div class="faceplate-row">
                                    <?php foreach ($sfp_row2 as $p): ?>
                                        <?php
                                            $raw_st = strtolower((string)($p['status'] ?? 'down'));
                                            $pStatusClass = match($raw_st) {
                                                'up' => (!empty($p['stp_state']) && $p['stp_state'] === 'blocking' ? 'fp-port-blocking' : 'fp-port-up'),
                                                'dormant' => 'fp-port-dormant',
                                                default => 'fp-port-down'
                                            };
                                            $p_title = 'SFP Optical: ' . (string)$p['name'] . ' (' . strtoupper($raw_st) . ')' . (!empty($p['alias']) ? ' - ' . $p['alias'] : '');
                                        ?>
                                        <button type="button" 
                                                class="fp-port-btn fp-port-sfp <?php echo $pStatusClass; ?>" 
                                                data-port-id="<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="inspectPort('<?php echo htmlspecialchars((string)$p['id'], ENT_QUOTES, 'UTF-8'); ?>')"
                                                title="<?php echo htmlspecialchars($p_title, ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="fp-port-led"></span>
                                            <span class="fp-port-num"><?php echo htmlspecialchars((string)$p['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Chassis Legend -->
                <div style="display: flex; gap: 14px; margin-top: 12px; font-size: 0.7rem; color: #94a3b8; align-items: center; flex-wrap: wrap; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 8px;">
                    <span style="display: inline-flex; align-items: center; gap: 4px;">
                        <span style="width: 8px; height: 8px; border-radius: 2px; background: #059669; border: 1px solid #10b981; display: inline-block;"></span> Online / Up
                    </span>
                    <span style="display: inline-flex; align-items: center; gap: 4px;">
                        <span style="width: 8px; height: 8px; border-radius: 2px; background: #1e293b; border: 1px solid #334155; display: inline-block;"></span> Down / Idle
                    </span>
                    <span style="display: inline-flex; align-items: center; gap: 4px;">
                        <span style="width: 8px; height: 8px; border-radius: 2px; background: #d97706; border: 1px solid #fbbf24; display: inline-block;"></span> Selected (Inspecting)
                    </span>
                    <span style="display: inline-flex; align-items: center; gap: 4px;">
                        <span style="width: 8px; height: 8px; border-radius: 2px; background: #991b1b; border: 1px solid #ef4444; display: inline-block;"></span> STP Blocked
                    </span>
                    <?php if (!empty($faceplate_sfp)): ?>
                    <span style="display: inline-flex; align-items: center; gap: 4px;">
                        <span style="width: 8px; height: 8px; border-radius: 2px; border: 1px dashed #38bdf8; display: inline-block;"></span> SFP Cage
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Active Port Inspector Card -->
            <div class="port-inspector-panel" id="port-inspector-panel">
                <!-- Inspector Header Bar -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; flex-wrap: wrap; gap: 0.75rem;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;" id="insp-port-title">
                            <span style="font-size: 1.15rem; font-weight: 700; color: var(--text);">Port Inspector</span>
                        </div>
                        <div id="insp-port-alias" style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px; display: none;"></div>
                        <div style="display: flex; gap: 6px; align-items: center; margin-top: 6px; flex-wrap: wrap;">
                            <span id="insp-status-badge" style="display: none; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; align-items: center; gap: 4px;"></span>
                            <span id="insp-stp-badge" style="display: none; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; align-items: center; gap: 4px;"></span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="selectPort(currentInspectorPort)" style="padding: 5px 12px; font-size: 0.78rem;">
                            <i data-lucide="activity" style="width: 14px; height: 14px;"></i> Live Traffic Graph
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="findPortInTable(currentInspectorPort)" style="padding: 5px 12px; font-size: 0.78rem;">
                            <i data-lucide="list" style="width: 14px; height: 14px;"></i> Find in Full Table
                        </button>
                    </div>
                </div>

                <!-- Inspector 2-Column Grid -->
                <div class="inspector-grid">
                    <!-- Column 1: Interface & VLAN Configuration -->
                    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1.1rem; display: flex; flex-direction: column; gap: 0.85rem;">
                        <h4 style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px;">
                            <i data-lucide="sliders" style="width: 13px; height: 13px; color: var(--primary);"></i>
                            Port Configuration &amp; VLANs
                        </h4>
                        
                        <div style="display: flex; justify-content: space-between; font-size: 0.82rem; border-bottom: 1px solid rgba(255,255,255,0.04); padding-bottom: 0.4rem;">
                            <span style="color: var(--text-muted);">Speed / Duplex:</span>
                            <span id="insp-speed" style="font-weight: 600; color: var(--text);">-</span>
                        </div>

                        <div style="display: flex; justify-content: space-between; font-size: 0.82rem; border-bottom: 1px solid rgba(255,255,255,0.04); padding-bottom: 0.4rem;">
                            <span style="color: var(--text-muted);">Port Type:</span>
                            <span id="insp-type" style="font-weight: 600; color: var(--text);">-</span>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 4px; font-size: 0.82rem; border-bottom: 1px solid rgba(255,255,255,0.04); padding-bottom: 0.5rem;">
                            <span style="color: var(--text-muted);">Native VLAN (Untagged):</span>
                            <div id="insp-native-vlan" style="margin-top: 2px;"></div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 4px; font-size: 0.82rem;">
                            <span style="color: var(--text-muted);">Tagged VLANs:</span>
                            <div id="insp-tagged-vlans"></div>
                        </div>

                        <!-- SFP DOM Section (Shown if SFP module present) -->
                        <div id="insp-sfp-section" style="display: none; margin-top: 0.5rem; padding-top: 0.6rem; border-top: 1px dashed var(--border); font-size: 0.78rem;">
                            <div style="color: var(--warning); font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                                <i data-lucide="zap" style="width: 12px; height: 12px;"></i> SFP Optical Diagnostics
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; color: var(--text-muted);">
                                <div>Vendor: <span id="insp-sfp-vendor" style="color: var(--text); font-weight: 600;">-</span></div>
                                <div>Part: <span id="insp-sfp-part" style="color: var(--text); font-weight: 600;">-</span></div>
                                <div>S/N: <span id="insp-sfp-serial" style="color: var(--text); font-weight: 600;">-</span></div>
                                <div style="grid-column: span 2;">Optical: <span id="insp-sfp-power" style="color: var(--text); font-weight: 600;">-</span></div>
                            </div>
                        </div>
                    </div>

                    <!-- Column 2: Connected Downstream Hosts -->
                    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1.1rem; display: flex; flex-direction: column; gap: 0.85rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h4 style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px;">
                                <i data-lucide="network" style="width: 13px; height: 13px; color: var(--success);"></i>
                                Connected Hosts
                            </h4>
                            <span id="insp-dev-count-badge" style="font-size: 0.7rem; font-weight: 700; background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.3); border-radius: 20px; padding: 2px 8px;">
                                0 Devices
                            </span>
                        </div>

                        <!-- Search filter if multiple downstream devices -->
                        <div id="insp-dev-search-wrap" style="display: none;">
                            <input type="text" placeholder="Filter MAC / IP / Hostname on this port..." class="input-control" onkeyup="filterInspectorDevices(this)" style="padding: 4px 10px; font-size: 0.75rem; width: 100%; border-radius: 4px;">
                        </div>

                        <!-- Dynamic hosts container -->
                        <div id="insp-devices-container"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mode 2: Full Table View -->
        <div id="table-view-container" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; flex-wrap: wrap; gap: 0.75rem;">
                <div>
                    <h4 style="font-size: 0.95rem; margin: 0; font-weight: 700;">Physical Interface Inventory &amp; Connected Hosts</h4>
                    <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo count($grouped_ports); ?> Interfaces • <?php echo count($ports); ?> MAC records</span>
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <input type="text" id="portSearch" placeholder="Filter interface / MAC / IP / VLAN..." class="input-control" style="width: 260px; padding: 6px 12px; font-size: 0.8rem;">
                </div>
            </div>
            <div class="table-responsive">
                <table style="width: 100%; border-collapse: collapse;" id="portTable">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border); text-align: left;">
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Interface</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Status</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">VLAN</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Connected Devices / MAC</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Mapped IP</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem;">Hostname / Vendor</th>
                        <th style="padding: 1rem; color: var(--text-muted); font-size: 0.8rem; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grouped_ports)): ?>
                        <tr>
                            <td colspan="7" style="padding: 2rem; text-align: center; color: var(--text-muted);">No interfaces discovered on this switch yet. Run a poll to start.</td>
                        </tr>
                    <?php else: ?>
                        <?php $p_idx = 0; ?>
                        <?php foreach ($grouped_ports as $pname => $port): ?>
                            <?php
                                $p_idx++;
                                $status = $port['port_status'] ?? null;
                                $statusColor = match($status) {
                                    'up' => 'var(--success)',
                                    'down' => 'var(--danger)',
                                    'dormant' => 'var(--warning)',
                                    default => 'var(--text-muted)'
                                };
                                $statusIcon = match($status) {
                                    'up' => 'circle-check',
                                    'down' => 'circle-x',
                                    'dormant' => 'circle-pause',
                                    default => 'circle-help'
                                };
                                $typeLabel = $port['port_type'] ?? null;
                                $speedLabel = $port['port_speed'] ?? null;
                                $devices = $port['devices'];
                                $dev_count = count($devices);
                                $is_uplink = $dev_count > 3 || preg_match('/(-to-|-sw|uplink|trunk|core|dist|po\d+|bond|ae\d+|sfp)/i', $port['port_name']);
                                $single_dev = ($dev_count === 1) ? $devices[0] : null;
                                $drawer_id = "drawer-" . $p_idx;
                            ?>
                            <tr style="border-bottom: 1px solid var(--border); cursor: pointer;" 
                                class="port-row port-row-main" 
                                data-port-name="<?php echo htmlspecialchars($port['port_name']); ?>" 
                                data-port-alias="<?php echo htmlspecialchars($port['port_alias'] ?? ''); ?>"
                                data-drawer-id="<?php echo $dev_count > 1 ? $drawer_id : ''; ?>"
                                onclick="<?php echo $dev_count > 1 ? "togglePortDrawer('{$drawer_id}')" : "selectPort('" . htmlspecialchars($port['port_name'], ENT_QUOTES) . "')"; ?>">
                                <td style="padding: 1rem; white-space: nowrap;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <i data-lucide="cable" style="width: 14px; color: <?php echo $statusColor; ?>;"></i>
                                        <div>
                                            <div style="font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                <?php echo htmlspecialchars($port['port_name']); ?>
                                                <?php if ($is_uplink): ?>
                                                    <span style="font-size: 0.65rem; background: var(--brand-soft); color: var(--primary); padding: 1px 6px; border-radius: 4px; font-weight: 800; letter-spacing: 0.5px;">UPLINK</span>
                                                <?php endif; ?>
                                                <button type="button" class="btn-traffic-pill" onclick="event.stopPropagation(); selectPort('<?php echo htmlspecialchars($port['port_name'], ENT_QUOTES); ?>')" title="Lihat Live Traffic Bandwidth">
                                                    <i data-lucide="activity" style="width: 10px; height: 10px;"></i> Traffic
                                                </button>
                                            </div>
                                            <div style="font-size: 0.7rem; color: var(--text-muted); display: flex; align-items: center; gap: 6px; margin-top: 2px; flex-wrap: wrap;">
                                                <?php if ($typeLabel && $typeLabel !== 'other'): ?>
                                                    <span><?php echo htmlspecialchars($typeLabel); ?></span>
                                                <?php endif; ?>
                                                <?php if ($speedLabel): ?>
                                                    <span>• <?php echo htmlspecialchars($speedLabel); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($port['port_alias'])): ?>
                                                    <span title="<?php echo htmlspecialchars($port['port_alias']); ?>">• <?php echo htmlspecialchars(substr($port['port_alias'], 0, 25)); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($port['sfp_vendor'])): ?>
                                                    <span style="color: var(--warning); display: inline-flex; align-items: center; gap: 3px;" title="SFP Module Info&#10;Vendor: <?php echo htmlspecialchars($port['sfp_vendor']); ?>&#10;Part: <?php echo htmlspecialchars($port['sfp_part'] ?? 'N/A'); ?>&#10;S/N: <?php echo htmlspecialchars($port['sfp_serial'] ?? 'N/A'); ?>&#10;RX Power: <?php echo htmlspecialchars($port['sfp_rx_power'] ?? 'N/A'); ?>&#10;TX Power: <?php echo htmlspecialchars($port['sfp_tx_power'] ?? 'N/A'); ?>">
                                                        • <i data-lucide="cpu" style="width: 11px; height: 11px;"></i> SFP
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php if ($status): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; background: <?php echo $status === 'up' ? 'rgba(34,197,94,0.1)' : ($status === 'down' ? 'rgba(239,68,68,0.1)' : 'rgba(245,158,11,0.1)'); ?>; color: <?php echo $statusColor; ?>;">
                                            <i data-lucide="<?php echo $statusIcon; ?>" style="width: 12px;"></i>
                                            <?php echo strtoupper($status); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem;">-</span>
                                    <?php endif; ?>
                                    <?php if (!empty($port['stp_state']) && $status === 'up' && $port['stp_state'] !== 'disabled'): ?>
                                        <div style="margin-top: 4px;">
                                            <?php if ($port['stp_state'] === 'blocking'): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: 800; background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.4);" title="STP Loop Prevention: Port is blocked to avoid switching loop">
                                                    <i data-lucide="shield-alert" style="width: 10px; height: 10px;"></i> BLOCKING
                                                </span>
                                            <?php elseif ($port['stp_state'] === 'forwarding'): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: 600; background: rgba(16, 185, 129, 0.1); color: var(--success);" title="STP State: Forwarding">
                                                    STP: FWD
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size: 0.65rem; color: var(--text-muted);" title="STP State">
                                                    STP: <?php echo htmlspecialchars(ucfirst($port['stp_state'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php
                                        $port_has_tagged_vlans = isset($tagged_vlans_per_port[$port['port_name']]) && !empty($tagged_vlans_per_port[$port['port_name']]);
                                    ?>
                                    <?php if ($port['vlan_id']): ?>
                                        <div style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: var(--brand-soft); border-radius: 4px;">
                                            <span style="color: var(--primary); font-size: 0.75rem; font-weight: 800;">ID: <?php echo $port['vlan_id']; ?></span>
                                            <?php if (!empty($port['vlan_name'])): ?>
                                                <span style="color: var(--text-muted); font-size: 0.65rem; border-left: 1px solid rgba(88, 166, 255, 0.3); padding-left: 4px; margin-left: 2px;"><?php echo htmlspecialchars($port['vlan_name']); ?></span>
                                            <?php endif; ?>
                                            <span style="font-size: 0.65rem; color: var(--primary); font-weight: 600;">(Untagged)</span>
                                        </div>
                                    <?php elseif (!$port_has_tagged_vlans): ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem;">-</span>
                                    <?php endif; ?>

                                    <?php if ($port_has_tagged_vlans): ?>
                                        <?php if ($port['vlan_id']): ?>
                                            <div style="height: 5px;"></div>
                                        <?php endif; ?>
                                        <?php
                                            $tagged_vlan_items = explode(',', $tagged_vlans_per_port[$port['port_name']]);
                                            $parsed_tagged = [];
                                            foreach ($tagged_vlan_items as $item) {
                                                if (empty($item)) continue;
                                                $t_parts = explode(':', $item, 2);
                                                $t_vid = trim($t_parts[0] ?? '');
                                                $t_vname = trim($t_parts[1] ?? '');
                                                if ($t_vid !== '') {
                                                    $parsed_tagged[] = ['id' => $t_vid, 'name' => $t_vname];
                                                }
                                            }
                                            $tag_count = count($parsed_tagged);
                                            $vlan_pop_id = 'vlan-pop-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $port['port_name']);
                                            $preview_limit = 4;
                                        ?>
                                        <div class="vlan-popover-container" style="position: relative; font-size: 0.7rem; color: var(--text-muted);">
                                            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 4px;">
                                                <span style="font-weight: 600; color: var(--text-muted); display: inline-flex; align-items: center; gap: 3px;">
                                                    <i data-lucide="tag" style="width: 11px; height: 11px;"></i> Tagged:
                                                </span>
                                                <?php foreach (array_slice($parsed_tagged, 0, $preview_limit) as $t): ?>
                                                    <span class="vlan-chip" title="<?php echo htmlspecialchars($t['name'] ?: 'VLAN ' . $t['id']); ?>">
                                                        <?php echo htmlspecialchars($t['id']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if ($tag_count > $preview_limit): ?>
                                                    <button type="button" class="btn-vlan-more" onclick="event.stopPropagation(); toggleVlanPopover('<?php echo $vlan_pop_id; ?>')">
                                                        +<?php echo ($tag_count - $preview_limit); ?> more <i data-lucide="chevron-down" style="width: 10px; height: 10px;"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($tag_count > $preview_limit): ?>
                                            <div id="<?php echo $vlan_pop_id; ?>" class="vlan-popover-menu" style="display: none;" onclick="event.stopPropagation();">
                                                <div class="vlan-popover-header">
                                                    <span><strong><?php echo $tag_count; ?> Tagged VLANs</strong> (Port <?php echo htmlspecialchars($port['port_name']); ?>)</span>
                                                    <button type="button" class="vlan-popover-close" onclick="toggleVlanPopover('<?php echo $vlan_pop_id; ?>')">&times;</button>
                                                </div>
                                                <div class="vlan-popover-body">
                                                    <?php foreach ($parsed_tagged as $t): ?>
                                                        <div class="vlan-popover-row" title="<?php echo htmlspecialchars($t['name']); ?>">
                                                            <span class="vlan-id-badge">ID: <?php echo htmlspecialchars($t['id']); ?></span>
                                                            <span class="vlan-name-text"><?php echo htmlspecialchars($t['name'] ?: 'VLAN ' . $t['id']); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Connected Devices / MAC Address Column -->
                                <td style="padding: 1rem; font-family: monospace; font-size: 0.85rem; white-space: nowrap;">
                                    <?php if ($dev_count === 0): ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem; font-family: inherit;">No active MAC</span>
                                    <?php elseif ($dev_count === 1): ?>
                                        <span style="color: var(--text); font-weight: 600;"><?php echo htmlspecialchars($single_dev['mac_addr']); ?></span>
                                    <?php else: ?>
                                        <button type="button" class="btn-device-collapse" onclick="event.stopPropagation(); togglePortDrawer('<?php echo $drawer_id; ?>')">
                                            <i data-lucide="layers" style="width: 12px; height: 12px;"></i>
                                            <span><?php echo $dev_count; ?> MACs</span>
                                            <i data-lucide="chevron-down" id="chevron-<?php echo $drawer_id; ?>" class="drawer-chevron" style="width: 12px; height: 12px;"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>

                                <!-- Mapped IP Column -->
                                <td style="padding: 1rem; white-space: nowrap;">
                                    <?php if ($dev_count === 1 && !empty($single_dev['ip_addr'])): ?>
                                        <a href="javascript:void(0)" onclick="openIpIntelligence('<?php echo $single_dev['ip_addr']; ?>')" style="color: var(--text); text-decoration: none; font-weight: 600; border-bottom: 1px dashed var(--primary); cursor: pointer;" title="Open IP Intelligence Dossier">
                                            <?php echo htmlspecialchars($single_dev['ip_addr']); ?>
                                        </a>
                                    <?php elseif ($dev_count === 1): ?>
                                        <span style="opacity: 0.35; font-size: 0.75rem;">Not in IPAM</span>
                                    <?php elseif ($dev_count > 1): ?>
                                        <span style="font-size: 0.75rem; color: var(--primary); font-weight: 600; cursor: pointer;" onclick="event.stopPropagation(); togglePortDrawer('<?php echo $drawer_id; ?>')">
                                            <?php echo $dev_count; ?> Downstream Hosts
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Hostname / Vendor Column -->
                                <td style="padding: 1rem;">
                                    <?php if ($dev_count === 1): ?>
                                        <div style="font-size: 0.85rem; font-weight: 600; color: var(--text);"><?php echo htmlspecialchars($single_dev['hostname'] ?: '-'); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($single_dev['vendor'] ?: ''); ?></div>
                                    <?php elseif ($dev_count > 1): ?>
                                        <span class="badge" style="background: rgba(56, 189, 248, 0.12); color: #38bdf8; font-size: 0.7rem; padding: 2px 6px;">
                                            Multi-Host Trunk Link
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Action / Seen Column -->
                                <td style="padding: 1rem; text-align: right; font-size: 0.75rem; color: var(--text-muted); white-space: nowrap;">
                                    <?php if ($dev_count > 1): ?>
                                        <button class="btn btn-secondary btn-sm" onclick="event.stopPropagation(); togglePortDrawer('<?php echo $drawer_id; ?>')" style="padding: 3px 8px; font-size: 0.75rem; gap: 4px; border-radius: 4px;">
                                            <span>Expand</span> <i data-lucide="chevron-down" style="width: 12px; height: 12px;"></i>
                                        </button>
                                    <?php elseif ($dev_count === 1 && !empty($single_dev['last_seen_on_port'])): ?>
                                        <?php echo date('H:i, d M', strtotime($single_dev['last_seen_on_port'])); ?>
                                    <?php elseif (!empty($port['last_seen_on_port'])): ?>
                                        <?php echo date('H:i, d M', strtotime($port['last_seen_on_port'])); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- Expandable Drawer Row for Multi-Device Ports -->
                            <?php if ($dev_count > 1): ?>
                                <tr id="<?php echo $drawer_id; ?>" class="port-device-drawer" style="display: none; background: rgba(15, 23, 42, 0.75);">
                                    <td colspan="7" style="padding: 0.75rem 1.25rem 1.25rem 1.25rem; border-bottom: 2px solid rgba(56, 189, 248, 0.25);">
                                        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 0.85rem; box-shadow: 0 4px 14px rgba(0,0,0,0.3);">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <i data-lucide="network" style="width: 16px; height: 16px; color: var(--primary);"></i>
                                                    <strong style="font-size: 0.85rem; color: var(--text);">
                                                        Downstream Devices on Port <?php echo htmlspecialchars($port['port_name']); ?> 
                                                        <span style="font-weight: 400; color: var(--text-muted); font-size: 0.75rem;">(<?php echo $dev_count; ?> MAC addresses)</span>
                                                    </strong>
                                                </div>
                                                <div style="display: flex; gap: 8px; align-items: center;">
                                                    <input type="text" 
                                                           placeholder="Filter MAC / IP / VLAN on this port..." 
                                                           class="input-control" 
                                                           onkeyup="filterDrawerTable(this, 'table-<?php echo $drawer_id; ?>')"
                                                           style="padding: 4px 10px; font-size: 0.75rem; width: 240px; border-radius: 4px;">
                                                    <button type="button" 
                                                            class="btn btn-secondary btn-sm" 
                                                            onclick="togglePortDrawer('<?php echo $drawer_id; ?>')" 
                                                            style="padding: 3px 8px; font-size: 0.75rem;">
                                                        Tutup ▲
                                                    </button>
                                                </div>
                                            </div>

                                            <div style="max-height: 320px; overflow: auto; border: 1px solid var(--border); border-radius: 6px;">
                                                <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem;" id="table-<?php echo $drawer_id; ?>">
                                                    <thead>
                                                        <tr style="background: rgba(0,0,0,0.25); position: sticky; top: 0; z-index: 2; border-bottom: 1px solid var(--border); text-align: left;">
                                                            <th style="padding: 6px 12px; color: var(--text-muted); font-size: 0.75rem;">MAC Address</th>
                                                            <th style="padding: 6px 12px; color: var(--text-muted); font-size: 0.75rem;">IP Address</th>
                                                            <th style="padding: 6px 12px; color: var(--text-muted); font-size: 0.75rem;">VLAN</th>
                                                            <th style="padding: 6px 12px; color: var(--text-muted); font-size: 0.75rem;">Hostname</th>
                                                            <th style="padding: 6px 12px; color: var(--text-muted); font-size: 0.75rem;">Vendor</th>
                                                            <th style="padding: 6px 12px; color: var(--text-muted); font-size: 0.75rem; text-align: right;">Last Seen</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php 
                                                        $max_display_devs = 200;
                                                        $displayed_devices = array_slice($devices, 0, $max_display_devs);
                                                        ?>
                                                        <?php foreach ($displayed_devices as $dev): ?>
                                                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                                                <td style="padding: 6px 12px; font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; font-weight: 600; color: var(--text);">
                                                                    <?php echo htmlspecialchars($dev['mac_addr']); ?>
                                                                </td>
                                                                <td style="padding: 6px 12px;">
                                                                    <?php if (!empty($dev['ip_addr'])): ?>
                                                                        <a href="javascript:void(0)" onclick="openIpIntelligence('<?php echo $dev['ip_addr']; ?>')" style="color: var(--primary); text-decoration: none; font-weight: 600; border-bottom: 1px dashed var(--primary); cursor: pointer;">
                                                                            <?php echo htmlspecialchars($dev['ip_addr']); ?>
                                                                        </a>
                                                                    <?php else: ?>
                                                                        <span style="opacity: 0.35; font-size: 0.7rem;">Not in IPAM</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 12px; white-space: nowrap;">
                                                                    <?php if (!empty($dev['vlan_id'])): ?>
                                                                        <span class="vlan-chip" style="font-size: 0.7rem;" title="<?php echo htmlspecialchars($dev['vlan_name'] ?: 'VLAN ' . $dev['vlan_id']); ?>">
                                                                            ID: <?php echo htmlspecialchars($dev['vlan_id']); ?>
                                                                        </span>
                                                                        <?php if (!empty($dev['vlan_name'])): ?>
                                                                            <span style="font-size: 0.7rem; color: var(--text-muted); margin-left: 4px;"><?php echo htmlspecialchars($dev['vlan_name']); ?></span>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        <span style="opacity: 0.35; font-size: 0.7rem;">-</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 12px; font-size: 0.8rem; color: var(--text);">
                                                                    <?php echo htmlspecialchars($dev['hostname'] ?: '-'); ?>
                                                                </td>
                                                                <td style="padding: 6px 12px; font-size: 0.75rem; color: var(--text-muted);">
                                                                    <?php echo htmlspecialchars($dev['vendor'] ?: '-'); ?>
                                                                </td>
                                                                <td style="padding: 6px 12px; text-align: right; font-size: 0.75rem; color: var(--text-muted);">
                                                                    <?php echo !empty($dev['last_seen_on_port']) ? date('H:i, d M', strtotime($dev['last_seen_on_port'])) : '-'; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php if ($dev_count > $max_display_devs): ?>
                                                <div style="padding: 6px 12px; font-size: 0.72rem; color: var(--text-muted); background: rgba(0,0,0,0.2); border-top: 1px solid var(--border); text-align: center;">
                                                    Menampilkan <?php echo $max_display_devs; ?> dari <?php echo $dev_count; ?> total perangkat di port ini. Gunakan kolom pencarian di atas untuk memfilter MAC atau IP tertentu.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- /#table-view-container -->
</div> <!-- /#switch-ports-main-card -->
</div> <!-- /.grid-side-detail -->

<!-- History Charts Section -->
<div style="margin-top: 3rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <h2 style="font-size: 1.25rem; margin: 0;">📈 Performance History</h2>
        <div style="display: flex; gap: 0.5rem;" id="history-btns">
            <?php foreach ([1, 6, 24, 48] as $h): ?>
            <button onclick="loadHistory(<?php echo $h; ?>)"
                    id="btn-h<?php echo $h; ?>"
                    class="btn btn-secondary"
                    style="padding: 4px 12px; font-size: 0.8rem; min-width: 60px; justify-content: center;
                           background: <?php echo $h == 6 ? 'var(--primary)' : 'var(--surface-light)'; ?>;
                           color: <?php echo $h == 6 ? '#fff' : 'var(--text-muted)'; ?>;">
                <?php echo $h; ?>h
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
        <div class="card">
            <h3 style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.5px;">CPU Load History</h3>
            <div class="chart-container" style="height: 250px;"><canvas id="cpuChart"></canvas></div>
        </div>
        <div class="card">
            <h3 style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.5px;">Memory Usage History</h3>
            <div class="chart-container" style="height: 250px;"><canvas id="memChart"></canvas></div>
        </div>
    </div>

    <div class="card">
        <h3 style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Period Summary</h3>
        <div style="display: flex; gap: 3rem; flex-wrap: wrap; justify-content: start;">
            <div><div style="font-size: 2rem; font-weight: 800; color: var(--primary);" id="stat-ports">—</div><div style="font-size: 0.8rem; color: var(--text-muted);">Active Interfaces</div></div>
            <div><div style="font-size: 2rem; font-weight: 800; color: var(--success);" id="stat-devices">—</div><div style="font-size: 0.8rem; color: var(--text-muted);">Mapped Devices</div></div>
            <div><div style="font-size: 2rem; font-weight: 800; color: var(--warning);" id="stat-avg-cpu">—</div><div style="font-size: 0.8rem; color: var(--text-muted);">Avg CPU</div></div>
            <div><div style="font-size: 2rem; font-weight: 800; color: var(--danger);" id="stat-peak-cpu">—</div><div style="font-size: 0.8rem; color: var(--text-muted);">Peak CPU</div></div>
        </div>
    </div>

    <!-- Port Traffic History (New) -->
    <div class="card" id="port-traffic-section" style="display: none; margin-top: 1.5rem; border: 1px solid rgba(56, 189, 248, 0.25); background: linear-gradient(180deg, rgba(15,23,42,0.8) 0%, rgba(15,23,42,0.6) 100%);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
            <div>
                <h3 style="font-size: 0.95rem; font-weight: 800; color: white; display: flex; align-items: center; gap: 8px; margin: 0;">
                    <i data-lucide="activity" style="color: #38bdf8; width: 18px; height: 18px;"></i>
                    Port Traffic Bandwidth: <span id="selected-port-name" style="color: #38bdf8;">—</span>
                </h3>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                    Visualisasi throughput counter SNMP 64-bit real-time
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="display: flex; gap: 4px;" id="port-traffic-range-btns">
                    <?php foreach ([1, 6, 24, 48] as $th): ?>
                    <button type="button" onclick="changePortTrafficHours(<?php echo $th; ?>)"
                            id="btn-port-h<?php echo $th; ?>"
                            class="btn btn-secondary"
                            style="padding: 3px 10px; font-size: 0.75rem; min-width: 45px; justify-content: center;
                                   background: <?php echo $th == 6 ? 'var(--primary)' : 'var(--surface-light)'; ?>;
                                   color: <?php echo $th == 6 ? '#fff' : 'var(--text-muted)'; ?>;">
                        <?php echo $th; ?>h
                    </button>
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="closePortTraffic()" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem;" title="Tutup Grafik">
                    <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                </button>
            </div>
        </div>

        <!-- Port KPI Metric Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.7rem; color: #38bdf8; font-weight: 700; text-transform: uppercase;">Current In (RX)</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: white; margin-top: 2px;" id="metric-cur-rx">—</div>
            </div>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.7rem; color: #ec4899; font-weight: 700; text-transform: uppercase;">Current Out (TX)</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: white; margin-top: 2px;" id="metric-cur-tx">—</div>
            </div>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Peak In</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #38bdf8; margin-top: 2px;" id="metric-peak-rx">—</div>
            </div>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Peak Out</div>
                <div style="font-size: 1.25rem; font-weight: 800; color: #ec4899; margin-top: 2px;" id="metric-peak-tx">—</div>
            </div>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Avg In / Out</div>
                <div style="font-size: 1rem; font-weight: 700; color: var(--text); margin-top: 5px;" id="metric-avg-rxtx">—</div>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 1rem; margin-bottom: 0.5rem; font-size: 0.75rem; color: var(--text-muted);">
            <div><span style="display: inline-block; width: 10px; height: 10px; background: #38bdf8; border-radius: 2px; margin-right: 4px;"></span> Inbound (Download)</div>
            <div><span style="display: inline-block; width: 10px; height: 10px; background: #ec4899; border-radius: 2px; margin-right: 4px;"></span> Outbound (Upload)</div>
        </div>
        <div class="chart-container" style="height: 300px;">
            <canvas id="portTrafficChart"></canvas>
        </div>
    </div>
</div>

<script>
// --- Switch Faceplate Inspector Data & Functions ---
const FACEPLATE_PORTS = <?php echo json_encode($faceplate_json_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
let currentInspectorPort = null;

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

function formatTimestamp(ts) {
    if (!ts) return '-';
    const d = new Date(ts);
    if (isNaN(d.getTime())) return ts;
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ', ' + d.toLocaleDateString([], { day: '2-digit', month: 'short' });
}

window.switchViewMode = function(mode) {
    const fpView = document.getElementById('faceplate-view-container');
    const tbView = document.getElementById('table-view-container');
    const btnFp  = document.getElementById('btn-mode-faceplate');
    const btnTb  = document.getElementById('btn-mode-table');
    
    if (mode === 'table') {
        if (fpView) fpView.style.display = 'none';
        if (tbView) tbView.style.display = 'block';
        if (btnTb) btnTb.classList.add('active');
        if (btnFp) btnFp.classList.remove('active');
        localStorage.setItem('switch_view_mode', 'table');
    } else {
        if (fpView) fpView.style.display = 'block';
        if (tbView) tbView.style.display = 'none';
        if (btnFp) btnFp.classList.add('active');
        if (btnTb) btnTb.classList.remove('active');
        localStorage.setItem('switch_view_mode', 'faceplate');
    }
    if (window.lucide) lucide.createIcons();
};

window.findPortInTable = function(portName) {
    switchViewMode('table');
    const searchInput = document.getElementById('portSearch');
    if (searchInput) {
        searchInput.value = portName;
        searchInput.dispatchEvent(new Event('input'));
    }
    const targetRow = document.querySelector(`.port-row[data-port-name="${CSS.escape(portName)}"]`);
    if (targetRow) {
        targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        targetRow.style.background = 'rgba(245, 158, 11, 0.2)';
        setTimeout(() => { targetRow.style.background = ''; }, 3000);
    }
};

window.inspectPort = function(portName) {
    currentInspectorPort = portName;
    const data = FACEPLATE_PORTS[portName];
    if (!data) return;

    // 1. Highlight active button on faceplate
    document.querySelectorAll('.fp-port-btn').forEach(btn => {
        if (btn.getAttribute('data-port-id') === portName) {
            btn.classList.add('fp-port-active');
        } else {
            btn.classList.remove('fp-port-active');
        }
    });

    // 2. Header
    const portTitleEl = document.getElementById('insp-port-title');
    const portAliasEl = document.getElementById('insp-port-alias');
    const statusBadgeEl = document.getElementById('insp-status-badge');
    const stpBadgeEl = document.getElementById('insp-stp-badge');

    if (portTitleEl) {
        const isUplink = data.dev_count > 3 || /(-to-|-sw|uplink|trunk|core|dist|po\d+|bond|ae\d+|sfp)/i.test(data.name);
        portTitleEl.innerHTML = `
            <span style="font-family:'JetBrains Mono',monospace;font-size:1.15rem;font-weight:700;color:var(--text);">${escapeHtml(data.name)}</span>
            ${isUplink ? '<span style="font-size:0.65rem;background:var(--brand-soft);color:var(--primary);padding:2px 6px;border-radius:4px;font-weight:800;letter-spacing:0.5px;">UPLINK</span>' : ''}
            ${data.is_sfp ? '<span style="font-size:0.65rem;background:rgba(245,158,11,0.15);color:#f59e0b;padding:2px 6px;border-radius:4px;font-weight:800;letter-spacing:0.5px;">SFP FIBER</span>' : ''}
        `;
    }

    if (portAliasEl) {
        if (data.alias) {
            portAliasEl.style.display = 'block';
            portAliasEl.textContent = `Alias: "${data.alias}"`;
        } else {
            portAliasEl.style.display = 'none';
        }
    }

    if (statusBadgeEl) {
        const isUp = data.status === 'up';
        statusBadgeEl.style.display = 'inline-flex';
        statusBadgeEl.style.background = isUp ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.15)';
        statusBadgeEl.style.color = isUp ? 'var(--success)' : 'var(--danger)';
        statusBadgeEl.innerHTML = `
            <i data-lucide="${isUp ? 'circle-check' : 'circle-x'}" style="width:12px;height:12px;"></i>
            ${data.status.toUpperCase()}
        `;
    }

    if (stpBadgeEl) {
        if (data.stp_state && data.status === 'up' && data.stp_state !== 'disabled') {
            stpBadgeEl.style.display = 'inline-flex';
            if (data.stp_state === 'blocking') {
                stpBadgeEl.style.background = 'rgba(239,68,68,0.2)';
                stpBadgeEl.style.color = '#ef4444';
                stpBadgeEl.innerHTML = '<i data-lucide="shield-alert" style="width:11px;height:11px;"></i> STP: BLOCKING';
            } else if (data.stp_state === 'forwarding') {
                stpBadgeEl.style.background = 'rgba(16,185,129,0.1)';
                stpBadgeEl.style.color = 'var(--success)';
                stpBadgeEl.innerHTML = 'STP: FWD';
            } else {
                stpBadgeEl.style.background = 'rgba(148,163,184,0.1)';
                stpBadgeEl.style.color = 'var(--text-muted)';
                stpBadgeEl.innerHTML = `STP: ${escapeHtml(data.stp_state)}`;
            }
        } else {
            stpBadgeEl.style.display = 'none';
        }
    }

    // 3. Port Configuration & VLANs
    const speedEl = document.getElementById('insp-speed');
    const typeEl  = document.getElementById('insp-type');
    if (speedEl) speedEl.textContent = data.speed || 'Auto / Negotiated';
    if (typeEl)  typeEl.textContent  = (data.type && data.type !== 'other') ? data.type : 'Ethernet CSMA/CD';

    // Native Untagged VLAN
    const nativeVlanEl = document.getElementById('insp-native-vlan');
    if (nativeVlanEl) {
        if (data.vlan_id) {
            nativeVlanEl.innerHTML = `
                <div style="display:inline-flex;align-items:center;gap:4px;">
                    <span class="vlan-chip" style="font-size:0.75rem;padding:2px 8px;">
                        ID: ${data.vlan_id}
                    </span>
                    <span style="font-weight:600;color:var(--text);">${escapeHtml(data.vlan_name || 'VLAN ' + data.vlan_id)}</span>
                    <span style="color:var(--text-muted);font-size:0.7rem;">(Untagged PVID)</span>
                </div>
            `;
        } else {
            nativeVlanEl.innerHTML = '<span style="color:var(--text-muted);font-size:0.8rem;">None / Default</span>';
        }
    }

    // Tagged VLANs Chips
    const taggedVlanContainer = document.getElementById('insp-tagged-vlans');
    if (taggedVlanContainer) {
        if (data.tagged_vlans && data.tagged_vlans.trim() !== '') {
            const items = data.tagged_vlans.split(',');
            let chipsHtml = '<div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:4px;">';
            items.forEach(it => {
                if (!it.trim()) return;
                const parts = it.split(':', 2);
                const vid = parts[0];
                const vname = parts[1] || '';
                chipsHtml += `<span class="vlan-chip" style="font-size:0.7rem;padding:2px 7px;" title="${escapeHtml(vname)}">VLAN ${escapeHtml(vid)}${vname ? ': ' + escapeHtml(vname) : ''}</span>`;
            });
            chipsHtml += '</div>';
            taggedVlanContainer.innerHTML = chipsHtml;
        } else {
            taggedVlanContainer.innerHTML = '<span style="color:var(--text-muted);font-size:0.75rem;">None (Access Port)</span>';
        }
    }

    // SFP Diagnostics
    const sfpContainer = document.getElementById('insp-sfp-section');
    if (sfpContainer) {
        const hasSfp = data.is_sfp || (data.sfp_vendor && data.sfp_vendor !== '0') || data.sfp_rx_power || data.sfp_tx_power;
        if (hasSfp) {
            sfpContainer.style.display = 'block';
            const vendorClean = (data.sfp_vendor && data.sfp_vendor !== '0') ? data.sfp_vendor : (data.is_sfp ? 'SFP Optical (DDM)' : '-');
            document.getElementById('insp-sfp-vendor').textContent = vendorClean;
            document.getElementById('insp-sfp-part').textContent = (data.sfp_part && data.sfp_part !== '-') ? data.sfp_part : (data.is_sfp ? 'Optical Transceiver' : '-');
            document.getElementById('insp-sfp-serial').textContent = data.sfp_serial || '-';
            const rxP = (data.sfp_rx_power && data.sfp_rx_power !== 'N/A') ? data.sfp_rx_power : 'N/A';
            const txP = (data.sfp_tx_power && data.sfp_tx_power !== 'N/A') ? data.sfp_tx_power : 'N/A';
            document.getElementById('insp-sfp-power').textContent = `RX: ${rxP} | TX: ${txP}`;
        } else {
            sfpContainer.style.display = 'none';
        }
    }

    // 4. Downstream Connected Hosts
    const devCountBadge = document.getElementById('insp-dev-count-badge');
    const devicesContainer = document.getElementById('insp-devices-container');
    const devSearchWrap = document.getElementById('insp-dev-search-wrap');
    
    if (devCountBadge) devCountBadge.textContent = `${data.dev_count} Device${data.dev_count === 1 ? '' : 's'}`;

    if (devicesContainer) {
        if (data.dev_count === 0) {
            if (devSearchWrap) devSearchWrap.style.display = 'none';
            devicesContainer.innerHTML = `
                <div style="padding: 2rem 1rem; text-align: center; color: var(--text-muted); background: var(--surface); border-radius: 6px; border: 1px dashed var(--border);">
                    <i data-lucide="plug-2" style="width:24px;height:24px;margin-bottom:6px;opacity:0.4;"></i>
                    <div style="font-size: 0.85rem; font-weight: 600;">No Active Devices Connected</div>
                    <div style="font-size: 0.75rem; opacity: 0.7; margin-top: 2px;">No MAC table records active on this interface.</div>
                </div>
            `;
        } else if (data.dev_count === 1) {
            if (devSearchWrap) devSearchWrap.style.display = 'none';
            const dev = data.devices[0];
            devicesContainer.innerHTML = `
                <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; display: flex; flex-direction: column; gap: 0.6rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">
                        <div style="font-family:'JetBrains Mono',monospace; font-size:0.9rem; font-weight:700; color:var(--text); display:flex; align-items:center; gap:6px;">
                            <i data-lucide="laptop" style="width:14px;color:var(--primary);"></i>
                            ${escapeHtml(dev.mac_addr)}
                        </div>
                        <span style="font-size:0.7rem; color:var(--text-muted);">
                            ${dev.last_seen_on_port ? 'Seen: ' + formatTimestamp(dev.last_seen_on_port) : ''}
                        </span>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.8rem;">
                        <div>
                            <div style="color:var(--text-muted);font-size:0.7rem;">IP Address</div>
                            <div style="margin-top:2px;">
                                ${dev.ip_addr ? `<a href="javascript:void(0)" onclick="openIpIntelligence('${escapeHtml(dev.ip_addr)}')" style="color:var(--primary);text-decoration:none;font-weight:700;border-bottom:1px dashed var(--primary);cursor:pointer;">${escapeHtml(dev.ip_addr)}</a>` : '<span style="opacity:0.4;">Not in IPAM</span>'}
                            </div>
                        </div>
                        <div>
                            <div style="color:var(--text-muted);font-size:0.7rem;">Hostname</div>
                            <div style="font-weight:600;margin-top:2px;">${escapeHtml(dev.hostname || '-')}</div>
                        </div>
                        <div>
                            <div style="color:var(--text-muted);font-size:0.7rem;">Vendor / Hardware</div>
                            <div style="color:var(--text-muted);margin-top:2px;">${escapeHtml(dev.vendor || 'Unknown')}</div>
                        </div>
                        <div>
                            <div style="color:var(--text-muted);font-size:0.7rem;">VLAN</div>
                            <div style="margin-top:2px;">
                                ${dev.vlan_id ? `<span class="vlan-chip" style="font-size:0.7rem;">ID: ${escapeHtml(dev.vlan_id)}</span>` : '<span style="opacity:0.4;">-</span>'}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } else {
            // Multiple devices
            if (devSearchWrap) devSearchWrap.style.display = 'block';
            let tableRows = '';
            data.devices.forEach((dev) => {
                tableRows += `
                    <tr class="insp-dev-row" style="border-bottom:1px solid rgba(255,255,255,0.04);">
                        <td style="padding:6px 10px; font-family:'JetBrains Mono',monospace; font-size:0.78rem; font-weight:600; color:var(--text);">
                            ${escapeHtml(dev.mac_addr)}
                        </td>
                        <td style="padding:6px 10px;">
                            ${dev.ip_addr ? `<a href="javascript:void(0)" onclick="openIpIntelligence('${escapeHtml(dev.ip_addr)}')" style="color:var(--primary);text-decoration:none;font-weight:700;border-bottom:1px dashed var(--primary);cursor:pointer;">${escapeHtml(dev.ip_addr)}</a>` : '<span style="opacity:0.35;font-size:0.7rem;">Not in IPAM</span>'}
                        </td>
                        <td style="padding:6px 10px; font-size:0.75rem; color:var(--text);">
                            ${escapeHtml(dev.hostname || '-')}
                        </td>
                        <td style="padding:6px 10px; font-size:0.72rem; color:var(--text-muted);">
                            ${escapeHtml(dev.vendor || '-')}
                        </td>
                        <td style="padding:6px 10px; text-align:right; font-size:0.72rem; color:var(--text-muted);">
                            ${dev.last_seen_on_port ? formatTimestamp(dev.last_seen_on_port) : '-'}
                        </td>
                    </tr>
                `;
            });

            devicesContainer.innerHTML = `
                <div style="max-height: 250px; overflow-y: auto; overflow-x: auto; max-width: 100%; border: 1px solid var(--border); border-radius: 6px; background: var(--surface);">
                    <table style="width:100%; border-collapse:collapse; font-size:0.8rem;" id="insp-devices-table">
                        <thead>
                            <tr style="background:rgba(0,0,0,0.3); position:sticky; top:0; z-index:2; border-bottom:1px solid var(--border); text-align:left;">
                                <th style="padding:6px 10px; color:var(--text-muted); font-size:0.72rem;">MAC Address</th>
                                <th style="padding:6px 10px; color:var(--text-muted); font-size:0.72rem;">IP Address</th>
                                <th style="padding:6px 10px; color:var(--text-muted); font-size:0.72rem;">Hostname</th>
                                <th style="padding:6px 10px; color:var(--text-muted); font-size:0.72rem;">Vendor</th>
                                <th style="padding:6px 10px; color:var(--text-muted); font-size:0.72rem; text-align:right;">Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                </div>
            `;
        }
    }

    if (window.lucide) lucide.createIcons();
};

window.filterInspectorDevices = function(input) {
    const q = input.value.toLowerCase().trim();
    document.querySelectorAll('.insp-dev-row').forEach(row => {
        row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
};

document.addEventListener('DOMContentLoaded', function() {
    // Restore or set initial view mode
    const savedMode = localStorage.getItem('switch_view_mode') || 'faceplate';
    switchViewMode(savedMode);

    // Initial inspect port
    const initialPort = <?php echo json_encode($initial_port_id); ?>;
    if (initialPort && FACEPLATE_PORTS[initialPort]) {
        inspectPort(initialPort);
    }
});

// Drawer toggle for multi-MAC ports
window.togglePortDrawer = function(drawerId) {
    const drawer = document.getElementById(drawerId);
    if (!drawer) return;
    
    const isHidden = drawer.style.display === 'none' || !drawer.style.display;
    drawer.style.display = isHidden ? 'table-row' : 'none';
    
    const chevron = document.getElementById('chevron-' + drawerId);
    if (chevron) {
        chevron.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
    }
    if (window.lucide) lucide.createIcons();
};

// Popover toggle for tagged VLANs on trunk ports
window.toggleVlanPopover = function(popoverId) {
    const pop = document.getElementById(popoverId);
    if (!pop) return;
    const isVisible = pop.style.display === 'block';
    // Close other open popovers
    document.querySelectorAll('.vlan-popover-menu').forEach(el => el.style.display = 'none');
    pop.style.display = isVisible ? 'none' : 'block';
    if (window.lucide) lucide.createIcons();
};

// Auto-close VLAN popovers when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.vlan-popover-container')) {
        document.querySelectorAll('.vlan-popover-menu').forEach(el => el.style.display = 'none');
    }
});

// Filter downstream devices inside a specific port drawer
window.filterDrawerTable = function(input, tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const q = input.value.toLowerCase().trim();
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(tr => {
        tr.style.display = (!q || tr.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
};

// Port search filter across main table and open/closed drawers
document.getElementById('portSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.port-row').forEach(row => {
        const portName = row.getAttribute('data-port-name') || '';
        const drawerId = 'drawer-' + portName.replace(/[^a-zA-Z0-9_-]/g, '_');
        const drawer = document.getElementById(drawerId);
        
        const rowText = row.textContent.toLowerCase();
        const drawerText = drawer ? drawer.textContent.toLowerCase() : '';
        const matches = !q || rowText.includes(q) || drawerText.includes(q);
        
        row.style.display = matches ? '' : 'none';
        if (!matches && drawer) {
            drawer.style.display = 'none';
            const chev = document.getElementById('chevron-' + drawerId);
            if (chev) chev.style.transform = 'rotate(0deg)';
        }
    });
});

(function() {
    const switchId = <?php echo $id; ?>;
    const badge    = document.getElementById('live-badge');
    const cpuVal   = document.getElementById('cpu-val');
    const cpuBar   = document.getElementById('cpu-bar');
    const memVal   = document.getElementById('mem-val');
    const memBar   = document.getElementById('mem-bar');
    const tempVal  = document.getElementById('temp-val');
    const tempBar  = document.getElementById('temp-bar');
    const tempBadge = document.getElementById('temp-badge');
    const lastPoll = document.getElementById('last-poll-val');

    function setBar(bar, val, dangerThreshold, dangerColor, normalColor) {
        if (!bar) return;
        bar.style.width = val + '%';
        bar.style.background = val > dangerThreshold ? dangerColor : normalColor;
    }

    if (typeof EventSource === 'undefined') {
        badge.textContent = 'NO SSE';
        return;
    }

    const es = new EventSource('api/switch-health-stream?id=' + switchId);

    es.onopen = function() {
        badge.textContent = 'LIVE';
        badge.style.background = 'rgba(34,197,94,0.15)';
        badge.style.color = '#22c55e';
    };

    es.onmessage = function(e) {
        try {
            const d = JSON.parse(e.data);
            if (cpuVal) cpuVal.textContent = d.cpu + '%';
            setBar(cpuBar, d.cpu, 80, 'var(--danger)', 'var(--primary)');
            if (memVal) memVal.textContent = d.mem + '%';
            setBar(memBar, d.mem, 90, 'var(--danger)', 'var(--success)');
            if (d.temp !== undefined && d.temp !== null) {
                if (tempVal) tempVal.textContent = d.temp + '°C';
                if (tempBar) {
                    tempBar.style.width = Math.min(100, Math.max(0, d.temp)) + '%';
                    tempBar.style.background = d.temp > 65 ? 'var(--danger)' : (d.temp > 50 ? '#f59e0b' : '#10b981');
                }
                if (tempBadge) {
                    const color = d.temp > 65 ? 'var(--danger)' : (d.temp > 50 ? '#f59e0b' : '#10b981');
                    tempBadge.innerHTML = `<span style="color: ${color};">${d.temp}°C</span>`;
                }
            }
            if (lastPoll) lastPoll.textContent = d.last_poll;
        } catch(err) { console.warn('SSE parse error', err); }
    };

    es.onerror = function() {
        badge.textContent = 'OFFLINE';
        badge.style.background = 'rgba(239,68,68,0.15)';
        badge.style.color = 'var(--danger)';
    };

    window.addEventListener('beforeunload', () => es.close());
})();

</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const SWITCH_ID = <?php echo $id; ?>;

    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: { backgroundColor: 'rgba(15,15,25,0.9)', titleColor: '#aaa', bodyColor: '#fff', padding: 10 }
        },
        scales: {
            x: { ticks: { color: '#888', maxTicksLimit: 8, font: { size: 11 } }, grid: { color: 'rgba(255,255,255,0.05)' } },
            y: { min: 0, max: 100, ticks: { color: '#888', callback: v => v + '%', font: { size: 11 } }, grid: { color: 'rgba(255,255,255,0.05)' } }
        }
    };

    function makeGradient(ctx, r, g, b) {
        if (!ctx.chart.ctx) return `rgba(${r},${g},${b},0.4)`;
        const grad = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
        grad.addColorStop(0,   `rgba(${r},${g},${b},0.4)`);
        grad.addColorStop(1,   `rgba(${r},${g},${b},0)`);
        return grad;
    }

    const cpuCtx = document.getElementById('cpuChart').getContext('2d');
    const memCtx = document.getElementById('memChart').getContext('2d');

    const cpuChart = new Chart(cpuCtx, {
        type: 'line',
        data: { labels: [], datasets: [{ label: 'CPU %', data: [], borderColor: '#58a6ff', borderWidth: 2, pointRadius: 0, fill: true, backgroundColor: (ctx) => makeGradient(ctx, 88, 166, 255), tension: 0.4 }] },
        options: JSON.parse(JSON.stringify(chartDefaults))
    });

    const memChart = new Chart(memCtx, {
        type: 'line',
        data: { labels: [], datasets: [{ label: 'RAM %', data: [], borderColor: '#22c55e', borderWidth: 2, pointRadius: 0, fill: true, backgroundColor: (ctx) => makeGradient(ctx, 34, 197, 94), tension: 0.4 }] },
        options: JSON.parse(JSON.stringify(chartDefaults))
    });

    async function safeFetch(url) {
        try {
            const response = await fetch(url);
            const text = await response.text();
            
            // Find the start of JSON content to ignore any leading garbage/warnings
            const jsonStart = text.indexOf('{');
            if (jsonStart === -1) {
                console.error('Invalid API response (no JSON found):', text);
                throw new Error('Invalid JSON response');
            }
            
            return JSON.parse(text.substring(jsonStart));
        } catch (err) {
            console.error('Fetch error for ' + url + ':', err);
            throw err;
        }
    }

    window.loadHistory = function(hours) {
        [1,6,24,48].forEach(h => {
            const btn = document.getElementById('btn-h' + h);
            if (!btn) return;
            btn.style.background = (h === hours) ? 'var(--primary)' : 'var(--surface-light)';
            btn.style.color      = (h === hours) ? '#fff' : 'var(--text-muted)';
        });

        safeFetch(`api/switch-history?id=${SWITCH_ID}&hours=${hours}`)
            .then(d => {
                cpuChart.data.labels   = d.labels;
                cpuChart.data.datasets[0].data = d.cpu;
                cpuChart.update('active');

                memChart.data.labels   = d.labels;
                memChart.data.datasets[0].data = d.mem;
                memChart.update('active');

                document.getElementById('stat-ports').textContent   = d.port_count   || '0';
                document.getElementById('stat-devices').textContent = d.device_count || '0';

                if (d.cpu && d.cpu.length > 0) {
                    const avg  = Math.round(d.cpu.reduce((a,b) => a+b, 0) / d.cpu.length);
                    const peak = Math.max(...d.cpu);
                    document.getElementById('stat-avg-cpu').textContent  = avg  + '%';
                    document.getElementById('stat-peak-cpu').textContent = peak + '%';
                } else {
                    document.getElementById('stat-avg-cpu').textContent  = 'N/A';
                    document.getElementById('stat-peak-cpu').textContent = 'N/A';
                }
            })
            .catch(err => {
                console.warn('History load failed', err);
            });
    };

    // --- Port Traffic Logic ---
    let trafficChart = null;
    let currentSelectedPort = null;
    let currentPortHours = 6;

    window.changePortTrafficHours = function(h) {
        if (currentSelectedPort) {
            selectPort(currentSelectedPort, h);
        }
    };

    window.closePortTraffic = function() {
        document.getElementById('port-traffic-section').style.display = 'none';
        document.querySelectorAll('.port-row').forEach(r => {
            r.style.background = '';
        });
    };

    window.selectPort = function(portName, hours = null) {
        currentSelectedPort = portName;
        if (hours !== null) {
            currentPortHours = hours;
        }

        document.getElementById('port-traffic-section').style.display = 'block';
        document.getElementById('selected-port-name').textContent = portName;

        // Update active hour button
        [1, 6, 24, 48].forEach(h => {
            const btn = document.getElementById('btn-port-h' + h);
            if (!btn) return;
            btn.style.background = (h === currentPortHours) ? 'var(--primary)' : 'var(--surface-light)';
            btn.style.color      = (h === currentPortHours) ? '#fff' : 'var(--text-muted)';
        });
        
        // Highlight row
        document.querySelectorAll('.port-row').forEach(r => {
            r.style.background = r.getAttribute('data-port-name') === portName ? 'rgba(56, 189, 248, 0.08)' : '';
        });

        safeFetch(`api/port-history?id=${SWITCH_ID}&port=${encodeURIComponent(portName)}&hours=${currentPortHours}`)
            .then(d => {
                const ctx = document.getElementById('portTrafficChart').getContext('2d');
                
                // Update KPI metrics
                if (d.stats) {
                    document.getElementById('metric-cur-rx').textContent = d.stats.current_rx + ' Mbps';
                    document.getElementById('metric-cur-tx').textContent = d.stats.current_tx + ' Mbps';
                    document.getElementById('metric-peak-rx').textContent = d.stats.peak_rx + ' Mbps';
                    document.getElementById('metric-peak-tx').textContent = d.stats.peak_tx + ' Mbps';
                    document.getElementById('metric-avg-rxtx').textContent = d.stats.avg_rx + ' / ' + d.stats.avg_tx + ' Mbps';
                } else {
                    document.getElementById('metric-cur-rx').textContent = '—';
                    document.getElementById('metric-cur-tx').textContent = '—';
                    document.getElementById('metric-peak-rx').textContent = '—';
                    document.getElementById('metric-peak-tx').textContent = '—';
                    document.getElementById('metric-avg-rxtx').textContent = '—';
                }

                if (trafficChart) {
                    trafficChart.destroy();
                }

                function makeGrad(ctx, r, g, b) {
                    if (!ctx.chart || !ctx.chart.ctx) return `rgba(${r},${g},${b},0.3)`;
                    const gLine = ctx.chart.ctx.createLinearGradient(0, 0, 0, 250);
                    gLine.addColorStop(0, `rgba(${r},${g},${b},0.35)`);
                    gLine.addColorStop(1, `rgba(${r},${g},${b},0.01)`);
                    return gLine;
                }

                trafficChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: d.labels,
                        datasets: [
                            {
                                label: 'Inbound / Download (Mbps)',
                                data: d.rx,
                                borderColor: '#38bdf8',
                                backgroundColor: (ctx) => makeGrad(ctx, 56, 189, 248),
                                fill: true,
                                tension: 0.35,
                                borderWidth: 2,
                                pointRadius: d.labels.length > 50 ? 0 : 2,
                                pointHoverRadius: 5
                            },
                            {
                                label: 'Outbound / Upload (Mbps)',
                                data: d.tx,
                                borderColor: '#ec4899',
                                backgroundColor: (ctx) => makeGrad(ctx, 236, 72, 153),
                                fill: true,
                                tension: 0.35,
                                borderWidth: 2,
                                pointRadius: d.labels.length > 50 ? 0 : 2,
                                pointHoverRadius: 5
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.95)',
                                titleColor: '#94a3b8',
                                bodyColor: '#ffffff',
                                borderColor: 'rgba(255,255,255,0.1)',
                                borderWidth: 1,
                                padding: 10,
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y + ' Mbps';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { color: 'rgba(255,255,255,0.03)' },
                                ticks: { color: '#888', font: { size: 10 }, maxTicksLimit: 12 }
                            },
                            y: { 
                                beginAtZero: true, 
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: { color: '#888', callback: v => v + ' Mbps', font: { size: 10 } }
                            }
                        }
                    }
                });
                
                // Scroll to chart smoothly
                document.getElementById('port-traffic-section').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                if (window.lucide) lucide.createIcons();
            })
            .catch(err => {
                console.warn('Port history load failed', err);
            });
    };

    loadHistory(6);
})();
</script>

<?php include 'includes/footer.php'; ?>
