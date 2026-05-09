<?php
require_once 'includes/network.php';

$ip = '192.168.1.1'; // Test with the one in the user's screenshot
exec("arp -a", $output);

echo "Checking IP: $ip\n";
echo "--- RAW ARP OUTPUT ---\n";
print_r($output);
echo "--- MATCHING TEST ---\n";

foreach ($output as $line) {
    if (preg_match('/^\s*' . preg_quote($ip) . '\s+([0-9a-fA-F]{2}[-:][0-9a-fA-F]{2}[-:][0-9a-fA-F]{2}[-:][0-9a-fA-F]{2}[-:][0-9a-fA-F]{2}[-:][0-9a-fA-F]{2})/', $line, $matches)) {
        echo "MATCH FOUND: " . $matches[1] . "\n";
    }
}

$mac = get_mac_from_arp($ip);
echo "Result from function: " . ($mac ?: 'NULL') . "\n";
