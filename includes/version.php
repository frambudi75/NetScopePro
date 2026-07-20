<?php
/**
 * NetScope Pro Version Information
 */

if (!defined('APP_VERSION')) define('APP_VERSION', '2.25.2');
if (!defined('APP_RELEASE_DATE')) define('APP_RELEASE_DATE', '2026-07-20');
if (!defined('GITHUB_REPO')) define('GITHUB_REPO', 'frambudi75/NetScopePro');
if (!defined('GITHUB_URL')) define('GITHUB_URL', 'https://github.com/' . GITHUB_REPO);

$versions = [
    ['ver' => '2.25.2', 'date' => '2026-07-20', 'changes' => ['Nmap OS Fingerprinting integrated into scanner worker (Legacy & Masscan modes)', 'MAC address enrichment for Masscan discovery path', 'Offline IP auto-cleanup reduced to 1 hour (was 24 hours)']],
    ['ver' => '2.25.1', 'date' => '2026-07-20', 'changes' => ['UI Menu Categorization & Account Labeling', 'Added Netwatch down indicator badge in sidebar', 'Smooth CSS hover transitions for sidebar navigation', 'Fixed .htaccess generic path rewrite for subfolder installations', 'Replaced missing Lucide GitHub brand icon with inline SVG']],
    ['ver' => '2.25.0', 'date' => '2026-06-24', 'changes' => ['SFP/DOM Transceiver Monitoring (MikroTik, Juniper, Generic)', 'RouterOS v7 Full SNMP Compatibility', 'Professional NOC Console Login Redesign', 'Mobile-First Responsive Grid System', 'Unified Color Palette (Legacy Indigo/Blue Cleanup)', '.htaccess RewriteBase Fix for Clean URLs', 'Ponytail Lazy Senior Dev Rules Integration']],
    ['ver' => '2.24.1', 'date' => '2026-05-22', 'changes' => ['Security hardening for all cron scripts (cron_netwatch, cron_switch_poll, cron_scanner)', 'Clean CLI log mode for switch poller by suppressing HTML wrapper', 'CLI context auth bypass with IS_CRON constant', 'SQL binding interval compatibility fixes', 'Fixed premature scanner exit preventing auto-cleanup execution', 'Improved git ignore rules for debug and check files']],
    ['ver' => '2.24.0', 'date' => '2026-05-10', 'changes' => ['Database Maintenance & Auto-Cleanup System', 'Configurable Data Retention Policy (per-table)', 'Database Health Dashboard (row count, disk usage, table stats)', 'One-click Manual Cleanup with AJAX feedback', 'Auto OPTIMIZE TABLE & AUTO_INCREMENT reset', 'Performance Indexes (audit_logs, switch_port_history)', 'Integrated daily auto-cleanup in cron scanner']],
    ['ver' => '2.23.0', 'date' => '2026-05-09', 'changes' => ['Major Rebrand to NetScope Pro', 'Cisco SNMP Engine Stability Fix', 'Ghost IP Prevention (Strict ARP/MAC)', 'Nmap Enterprise OS Fingerprinting', 'Non-blocking SSE Health Stream', 'Robust API Output Buffering']],
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
