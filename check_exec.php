<?php
$disabled = ini_get('disable_functions');
echo "Disabled Functions: " . ($disabled ? $disabled : 'None') . "\n";
$output = [];
$result = -1;
exec('whoami', $output, $result);
echo "Whoami Result: $result\n";
echo "Whoami Output: " . implode("\n", $output) . "\n";
