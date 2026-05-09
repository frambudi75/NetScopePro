<?php
/**
 * IPManager Pro Version Information
 */

if (!defined('APP_VERSION')) define('APP_VERSION', '2.22.1');
if (!defined('APP_RELEASE_DATE')) define('APP_RELEASE_DATE', '2026-05-02');
if (!defined('GITHUB_REPO')) define('GITHUB_REPO', 'frambudi75/IP-Manage');
if (!defined('GITHUB_URL')) define('GITHUB_URL', 'https://github.com/' . GITHUB_REPO);

$versions = [
    ['ver' => '2.22.1', 'date' => '2026-05-02', 'changes' => ['Aggressive Autocomplete Prevention (Read-only trick)', 'Styled Terminal-UI for Switch Poller', 'Auto-Redirect after Polling Completion', 'On-Demand OS Detection in Manual Scans']],
    ['ver' => '2.22.0', 'date' => '2026-05-02', 'changes' => ['Styled Terminal-UI for Switch Poller', 'Auto-Redirect after Polling Completion', 'On-Demand OS Detection in Manual Scans', 'Privacy Hardening (Global Autocomplete: OFF)', 'Robust Multi-table ARP Discovery Fallback']],
    ['ver' => '2.21.0', 'date' => '2026-05-02', 'changes' => ['SNMP Multi-vendor Engine (30+ Vendors supported)', 'Dedicated pfSense/OPNsense/net-snmp Monitoring', 'Subnet Scan Optimization (ARP Pre-seeding)', 'Real-time Scan Progress & Elapsed Time tracking']],
    ['ver' => '2.20.0', 'date' => '2026-04-28', 'changes' => ['SNMP Switch Monitoring (VLAN names, Speed, Status)', 'Auto-Generated Topology Map Hierarchy', 'Switch Hardware Capacity Dashboard', 'Uplink/Trunk Detection Logic', 'Multi-vendor SNMP Fallback (Cisco/Alcatel/Huawei)']],
    ['ver' => '2.19.0', 'date' => '2026-04-19', 'changes' => ['Netwatch Latency History & Graphing', 'Discord & Slack Webhook Integration', 'Custom Notification Templates', 'Maintenance Mode (Snooze Alert)', 'Scanner Health Status Indicator']],
    ['ver' => '2.18.1', 'date' => '2026-04-19', 'changes' => ['Downtime Duration calculation in alerts', 'Improved Windows Ping robustness', 'Timezone synchronization logic', 'Telegram HTML Mode upgrade', 'Fixed Netwatch Form Resubmission']],
    ['ver' => '2.18.0', 'date' => '2026-04-16', 'changes' => ['Active Netwatch Monitoring Module', 'Dashboard Status Overview Widget', 'Real-time AJAX Scanner Trigger', 'Header UX/UI consistency cleanup']],
    ['ver' => '2.17.0', 'date' => '2026-04-12', 'changes' => ['Premium Visualization (Gradients & Gridless)', 'Professional Network Reports Module', 'Interactive Progress Tracking']],
    ['ver' => '2.16.0', 'date' => '2026-04-12', 'changes' => ['Global Responsive Refactor (Mobile-First)', 'Standardized CSS utility classes', 'Topology Map Responsive Fix']],
    ['ver' => '2.15.1', 'date' => '2026-04-09', 'changes' => ['Internal Bug Reporting System', 'Automated Activation Telemetry', 'Fixed .htaccess API routing']],
];
