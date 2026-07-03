<?php
/**
 * IPManager Pro - CSV Export Utility
 */
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/network.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized.");
}

$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

switch ($type) {
    case 'subnets':
        $filename = "subnets_export_" . date('Y-m-d') . ".csv";
        $header = ['ID', 'Subnet', 'Mask', 'Description', 'VLAN', 'Scan Interval', 'Last Scan', 'Used IPs'];
        $query = "
            SELECT s.*, v.number as vlan_number, COUNT(ip.id) as used_ips 
            FROM subnets s 
            LEFT JOIN vlans v ON s.vlan_id = v.id 
            LEFT JOIN ip_addresses ip ON ip.subnet_id = s.id 
            GROUP BY s.id 
            ORDER BY s.subnet ASC
        ";
        $data = $db->query($query)->fetchAll();
        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                $row['id'], $row['subnet'], $row['mask'], $row['description'], 
                $row['vlan_number'] ?: '-', $row['scan_interval'], $row['last_scan'] ?: '-', $row['used_ips']
            ];
        }
        break;

    case 'subnet_details':
        $stmt = $db->prepare("SELECT subnet, mask FROM subnets WHERE id = ?");
        $stmt->execute([$id]);
        $s_info = $stmt->fetch();
        if (!$s_info) die("Subnet not found");

        $filename = "subnet_" . str_replace('.', '_', $s_info['subnet']) . "_" . $s_info['mask'] . "_export.csv";
        $header = ['IP Address', 'State', 'Hostname', 'MAC Address', 'Vendor', 'Description', 'Last Seen', 'Confidence %'];
        
        $stmt = $db->prepare("SELECT * FROM ip_addresses WHERE subnet_id = ? ORDER BY ip_addr ASC");
        $stmt->execute([$id]);
        $data = $stmt->fetchAll();
        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                $row['ip_addr'], $row['state'], $row['hostname'], $row['mac_addr'], 
                $row['vendor'], $row['description'], $row['last_seen'], $row['confidence_score'] . '%'
            ];
        }
        break;

    case 'devices':
        $filename = "all_devices_export_" . date('Y-m-d') . ".csv";
        $header = ['IP Address', 'Hostname', 'State', 'Subnet', 'MAC Address', 'Vendor', 'OS', 'Last Seen', 'Confidence %'];
        $query = "
            SELECT ip.*, s.subnet, s.mask 
            FROM ip_addresses ip
            JOIN subnets s ON ip.subnet_id = s.id
            WHERE ip.state IN ('active', 'reserved', 'dhcp')
            ORDER BY ip.last_seen DESC, ip.ip_addr ASC
        ";
        $data = $db->query($query)->fetchAll();
        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                $row['ip_addr'], $row['hostname'], $row['state'], $row['subnet'] . '/' . $row['mask'],
                $row['mac_addr'], $row['vendor'], $row['os'], $row['last_seen'], $row['confidence_score'] . '%'
            ];
        }
        break;

    case 'vlans':
        $filename = "vlans_export_" . date('Y-m-d') . ".csv";
        $header = ['ID', 'VLAN Number', 'Name', 'Description'];
        $query = "SELECT * FROM vlans ORDER BY number ASC";
        $data = $db->query($query)->fetchAll();
        $rows = [];
        foreach ($data as $row) {
            $rows[] = [$row['id'], $row['number'], $row['name'], $row['description']];
        }
        break;

    default:
        die("Invalid type.");
}

// Output CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
// UTF-8 BOM for Excel support
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, $header);
foreach ($rows as $row) {
    fputcsv($output, $row);
}
fclose($output);
exit;
