<?php
/**
 * NetScope Pro Version Information
 */

if (!defined('APP_VERSION')) define('APP_VERSION', '2.33.0');
if (!defined('APP_RELEASE_DATE')) define('APP_RELEASE_DATE', '2026-10-08');
if (!defined('GITHUB_REPO')) define('GITHUB_REPO', 'frambudi75/NetScopePro');
if (!defined('GITHUB_URL')) define('GITHUB_URL', 'https://github.com/' . GITHUB_REPO);

$versions = [
    ['ver' => '2.33.0', 'date' => '2026-10-08', 'changes' => [
        'Interactive Switch Physical Faceplate Visualizer in switch-details.php (Dual-row RJ45 matrix & dedicated SFP optical bays with status LEDs)',
        'Click-to-Inspect Architecture: Active Port Inspector Card showing speed, duplex, PVID untagged, tagged VLAN chips, and connected hosts',
        'Hardware Temperature Polling Engine across Cisco, Alcatel, MikroTik, Huawei, Juniper, HP/Aruba, Extreme, Dell, and Fortinet',
        'Dynamic Temperature Gauge widget with real-time SSE stream telemetry in api/switch-health-stream.php',
        'Clean numbered port faceplate labels & Apache DirectorySlash fix in .htaccess',
        'Collapsible Desktop Mini-Sidebar / Icon-Rail mode (68px) with persistent localStorage state & +192px workspace expansion'
    ]],
    ['ver' => '2.32.6', 'date' => '2026-10-08', 'changes' => [
        'Evidence Reasoning & Transparency Matrix in loop-detective.php (Correlated Evidence Checklist & Anti-Noise Disqualification Panel)',
        'L2 Multi-Evidence Correlation Engine (FDB Candidate-Only architecture: FDB table alone never triggers LOOP_DETECTED)',
        'Three Orthogonal States Framework: Detection (NORMAL/SUSPECTED/CONFIRMED), Protection (NONE/STP_BLOCKING), Impact (NORMAL/MITIGATED/ACTIVE)',
        'Automated 11-Scenario Synthetic Regression Test Suite (tests/test_loop_scenarios.php guarantees zero false alarms across all edge cases)',
        'Evidence Scoring & Telemetry Correlation (Evaluates port role, MAC bouncing density, STP states, TCN delta, and exclusion tracking)',
        'Background Switch Poller Integration with Persistence Verification across consecutive polling cycles'
    ]],
    ['ver' => '2.32.1', 'date' => '2026-10-08', 'changes' => [
        'Alcatel OmniSwitch 802.1Q Tagged VLAN Discovery (vpaTable .1.3.6.1.4.1.6486.800/801.1.2.1.3 and vlanDescription support)',
        'Direct Trunk/Tagged Interface Auto-Registration into switch_port_vlans across static configuration and dynamic traffic',
        'Compact Tagged VLAN Chips & Interactive Popover for Trunk Interfaces (+N more modal table with ID & names)',
        'Downstream Devices Drawer VLAN Column with Real-time Search Filter (IP, MAC, Vendor, VLAN)',
        'Smart Alcatel Port vs VLAN Disambiguation (Resolves inverted slMacAddressTable tuples & false Port 100 entries)',
        'Physical Port Normalization for Alcatel OmniSwitch (Maps 1..64 to 1/X and purges stale unmapped ports)',
        'Comprehensive Dummy Sequential MAC Purge & Filter (00:00:00:% eliminated across DB, Cron, and UI)',
        'False-Positive Loop Detection Fix & Flap Threshold Enforcement (Requires >= 3-5 concurrent flapping MACs & cleans stale loop flags)'
    ]],
    ['ver' => '2.32.0', 'date' => '2026-10-07', 'changes' => [
        'L2 Loop Detective & Downstream Root-Cause Investigator (Visual forensic trace: Core ➔ Switch ➔ Access Link ➔ Culprit Device)',
        'Live Telemetry Radar & Calibrated Risk Meter (Accurate 80% high-risk CAM thrashing detection & port pair tracking)',
        'Enterprise Hardware & OUI Detection (Cisco Meraki, Alcatel-Lucent, HPE Aruba, Ruijie, Grandstream, Yealink)',
        'Switch Interface Inventory & Expandable Multi-Host Drawer (Accordions for trunk links & inline SFP optical telemetry)',
        'Alcatel OmniSwitch Enterprise MIB Support (Native slMacAddressTable parsing & auto-purge of legacy fake MACs)',
        'Multi-Device Responsive Design (Optimized mobile tree view, table-responsive horizontal swiping & adaptive drawer)',
        'Apache Routing & Trailing-Slash Sanitizer (.htaccess rewrite guard preventing 403/500 collision on routes)',
        'Enterprise Discord Rich Embeds & Webhook Sentinels (Dynamic color cards, 2-column inline fields, live timestamps & test buttons)'
    ]],
    ['ver' => '2.31.3', 'date' => '2026-10-04', 'changes' => [
        'SSH Channel 1 Collision Fix (Eliminated "Please close the channel (1)" runtime exception)',
        'Target Server SSH Session Leak Prevention (Explicit $ssh->disconnect() prevents OpenSSH MaxStartups exhaustion)',
        'Client-side Polling Mutex Guard (isFetchingMetrics prevents concurrent request pileup)',
        'Resilient Telemetry Poller (Transient error tolerance keeping live charts stable during momentary spikes)'
    ]],
    ['ver' => '2.31.2', 'date' => '2026-10-04', 'changes' => [
        'Server Assets Metrics Modal Fix (Eliminated SyntaxError JSON parse crash & stuck spinner)',
        'Docker Pre-Built Vendor Package Restoration in entrypoint.sh (Guarantees phpseclib3 on volume mounts)',
        'Resilient API Error Handling (Throwable catch blocks & graceful phpseclib availability checks)',
        'Directory Path Hardening (__DIR__ resolution across all server-assets API endpoints)'
    ]],
    ['ver' => '2.31.1', 'date' => '2026-10-04', 'changes' => [
        'Zero-Touch Database Auto-Healing (Automatic schema restoration on empty or partial databases)',
        'Fresh Docker Boot Self-Recovery (Resolved MariaDB seed crash due to column mismatch on switches table)',
        'Proactive Schema Synchronization on Login Load (Guarantees users table & admin account before authentication)',
        'Resilient Multi-Query Fallback Migrations in includes/db.php & includes/db_upgrade.php'
    ]],
    ['ver' => '2.31.0', 'date' => '2026-10-02', 'changes' => ['IP Conflict & Flap Center (Dedicated NOC investigation module with dual-host forensic comparison)', '1-Click Conflict Mitigation (Resolve, Accept New Host, Ignore/Suppress, Live Prober)', 'Automated Conflict & MAC Flapping Event Logger (Scanner worker & API integration)', 'L2 Switching Loop Prober Accuracy Fix (ifOperStatus correlation eliminating false alarms on inactive ports)', 'Friendly Switch Port Labeling in Live STP Diagnostics (e.g. ether3-to-Sw)', 'Dynamic Conflict Counter Badge in Navigation Sidebar']],
    ['ver' => '2.30.0', 'date' => '2026-10-02', 'changes' => ['IP Intelligence Dossier (360-degree single-pane L2/L3 hardware and security view)', 'Executive NOC Dashboard Redesign (4-pillar metric bar, balanced telemetry grids, enhanced quick actions)', 'Universal Search integration for IP Addresses (Instant dossier trigger)', '1-Click Conflict Resolution with real-time audit logging', 'SNMP Poller Anti-Freeze & PHP Session Lock Release (session_write_close & 1s fast reachability probe)', 'MikroTik & Multi-Brand Inactive Port STP Filter (Eliminating false-positive loop alarms on unplugged ports)', 'Clean URL Routing Fix in .htaccess (Subfolder and root domain compatibility)']],
    ['ver' => '2.29.2', 'date' => '2026-10-01', 'changes' => ['Subnet Usage Bar Synchronization (Accurate active/reserved/dhcp calculation across all views)', 'Subnet CIDR Bitwise Masking Fix in cidr_to_range()', 'Auto-heal & purge orphaned/ghost IP records across subnets', 'Added switch_port_vlans & missing columns to auto-migrations']],
    ['ver' => '2.29.1', 'date' => '2026-10-01', 'changes' => ['Server Assets Decryption Fix (Multi-key fallback preventing raw ciphertext leaks)', 'Universal Live Server Metrics (Reliable Linux /proc metrics & MikroTik RouterOS support)', 'Accurate Error Reporting in Metrics Modal (No more false 0% metrics on unsupported targets)', 'Aligned Server Asset Card Checkbox inside header boundary']],
    ['ver' => '2.29.0', 'date' => '2026-09-30', 'changes' => ['IP Conflict Multi-Probe Engine (3-cycle sequential ARP & MAC stability check)', 'Loop & STP Diagnostic Prober Enhancement (TCN operational analysis & CAM table thrashing detection)', 'Multi-Layer Evidence Checklist Summary across all diagnostic tools', 'Diagnostic Confidence Scoring (Confidence Grade & Risk Percentage)', 'Realistic Non-Absolute Verdicts with Diagnostic Scope Boundaries']],
    ['ver' => '2.28.1', 'date' => '2026-09-26', 'changes' => ['L2 Loop Detection & MAC Flapping Accuracy Enhancement (Pair-Specific Thrashing Analysis)', 'State-Transition Alerting (Only alerts on new events, suppressed duplicate spamming)', 'Automatic Recovery Notification (RESOLVED: L2 Switching Loop Cleared)', 'Configurable MAC Flap Sensitivity Threshold in Settings', 'Fixed cross-switch STP blocked port leakage bug']],
    ['ver' => '2.28.0', 'date' => '2026-09-11', 'changes' => ['Enterprise Multi-Event Telegram Alert Engine (L2 Loop, Netwatch, Conflict, SFP DDM)', 'Switch Port Live Bandwidth & Throughput Graph (SNMP 64-bit In/Out Mbps)', 'Event-Specific Alert Toggles in System Settings (Loop, SFP, Conflict, Netwatch)', 'Interactive Port Traffic Section with Live KPI Cards (Current, Peak, Average)', 'Intelligent Alert Throttling & Anti-Spam Protection Engine']],
    ['ver' => '2.27.0', 'date' => '2026-09-11', 'changes' => ['L2 Switching Loop Detection Engine (STP Blocking & MAC Thrashing)', 'L2 Loop & Topology Stability Diagnostic Prober in Network Tools', 'Spanning Tree Protocol (STP / RSTP) State Discovery & Port Badges', 'Dashboard NOC Alert Banner for Active Loops and Blocked Ports', 'Dynamic Switch Port STP Status Mapping & Hardware Sidebar Integration']],
    ['ver' => '2.26.0', 'date' => '2026-09-11', 'changes' => ['IP Conflict Detection Engine with MAC Flapping Protection', 'Conflict Diagnostic Prober Tool (TTL variance & ARP integrity)', 'Dashboard NOC Alert Banner & Subnet Conflict Filter', 'Login Brute-Force Rate Limiting & Windows Background Runner', 'Database Performance Index (idx_conflict) & Idempotent Migrations', 'PHP Function Redeclaration Guards & Autoloading Stabilization']],
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
