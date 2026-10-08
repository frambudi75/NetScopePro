# NetScope Pro: Development & Update History

All major functional changes, enhancements, and critical fixes are documented here.

## [2.32.1] - 2026-10-08
### Fixed & Enhanced
- **Alcatel OmniSwitch 802.1Q Tagged Trunk VLAN Resolution (`cron_switch_poll.php`)**:
  - Integrated native Alcatel Enterprise VLAN Manager MIB `vpaTable` (`.1.3.6.1.4.1.6486.800.1.2.1.3.1.1.2.1.1.3` and `.801...`) to discover trunk tagged VLANs alongside fallback tables.
  - Added support for Alcatel `vlanDescription` (`.1.3.6.1.4.1.6486.800/801.1.2.1.3.1.1.1.1.1.2`) to fetch descriptive VLAN labels.
  - Implemented Phase 1.5 direct registration: all discovered tagged VLANs per interface are committed into `switch_port_vlans` immediately, even if no active host MACs are currently transmitting across the trunk.
  - Added dynamic traffic tag learning: packets arriving on non-PVID VLANs on trunk interfaces automatically register that VLAN into `switch_port_vlans`.
- **Compact Tagged VLAN Chips & Interactive Popover (`switch-details.php`)**:
  - Replaced bulky paragraphs of stacked VLAN names on trunk interfaces with neat, compact numeric VLAN ID badges (e.g. `10`, `20`, `231`).
  - Added an interactive `+N more ▾` toggle button with a sleek glassmorphic popover showing the complete table of VLAN IDs alongside descriptive VLAN names.
  - Included outside-click event listeners to dismiss open popovers seamlessly.
- **Downstream Devices Drawer VLAN Column & Live Filter (`switch-details.php`)**:
  - Added a dedicated **VLAN** badge column to the multi-device expandable drawer accordion on trunk/uplink ports.
  - Enhanced drawer search filtering to evaluate IP, MAC address, hardware vendor, and **VLAN ID/Name** in real-time.
- **Alcatel Source Learning Disambiguation & Port Normalization (`cron_switch_poll.php`)**:
  - Resolved `slMacAddressTable` index tuple inversion where VLAN IDs (e.g. VLAN 100) were incorrectly mapped as physical port numbers, generating ghost `"Port 100"` entries.
  - Implemented `$fnResolveAlcatelSl` heuristic validator to accurately differentiate between `VLAN.ifIndex.MAC` and `ifIndex.VLAN.MAC` formats across AOS versions.
  - Extended `normalize_port_name()` to map slot 1 physical bridge ports (`1..64`) directly to Alcatel's standard format (`1/X`) instead of unmapped fallback (`Port X`).
  - Automatically purges stale unmapped `"Port %"` records on Alcatel switches to maintain clean interface inventories.
- **False-Positive Loop Detection Fix & Flap Threshold Enforcement (`cron_switch_poll.php`, `includes/loop.helper.php`, `loop-detective.php`)**:
  - Eliminated critical false alarm where moving a single laptop, phone, or Wi-Fi roaming client (`access_suspects`) bypassed `loop_flap_threshold` and marked all switches as `loop_detected = 1`.
  - Enforced strict flap threshold requirement (`>= 3-5` concurrent oscillating MACs on the identical port pair) before triggering an L2 switching loop warning.
  - Restricted end-device root-cause candidate isolation to confirmed high-frequency flapping pairs or active STP quarantine ports only.
  - Eliminated false cross-switch flapping alarms in `LoopDetectiveHelper` previously triggered by lifetime cumulative TCN counts (`> 20`).
  - Added automated database auto-cleanup in `includes/db.php` resetting false `loop_detected` flags and resolving single-flap entries in `ip_conflict_events`.

## [2.32.0] - 2026-10-07
### Added & Enhanced
- **L2 Loop Detective Module (`loop-detective.php` & `includes/loop.helper.php`)**:
  - Dedicated forensic troubleshooting center specifically engineered to answer: *Where is the loop visible? Which ports and MACs are oscillating? What downstream segment must be inspected?*
  - **Live Telemetry Radar**: Real-time evaluation of STP/RSTP state, TCN counter, FDB thrashing status, port movement pair, and calibrated 80% High-Risk scoring on active CAM thrashing.
  - **Investigation Path Visualizer (Downstream Trace Tree)**:
    - Multi-level visual chain from Upstream Core / Root Bridge ➔ Target Switch (Symptom Detected) ➔ Downstream Switch / Access Link (Suspect Target) ➔ Culprit Endpoint / Bouncing Device (Root Cause).
    - Resolves oscillating MAC addresses to hardware vendors (e.g. Cisco Meraki, Alcatel, MikroTik, Aruba) and endpoint IP/hostnames directly from inventory.
    - Resolves transit trunk ports cleanly without empty parentheses (`1/23 ↔ 1/1 (Uplink)`).
  - **Actionable Forensic Verdict & Scope Boundaries**:
    - Differentiates between intra-switch issues and unmanaged loops on downstream cables or dumb switches.
    - Provides step-by-step physical cable inspection instructions and loop isolation SOPs for NOC engineers.
  - **Global Switches STP Health Matrix**: Global overview of all monitored bridge nodes with one-click direct investigation trigger.
  - **Dynamic Navigation Integration**: Added `Loop Detective` to sidebar menu with an automated alert counter badge when switches report active quarantined ports.

- **Switch Interface Inventory & Expandable Multi-Host Drawer (`switch-details.php`)**:
  - Re-architected interface inventory table with port-level grouping: single-host ports display direct IP/MAC, while multi-host/trunk links display an expandable drawer accordion.
  - In-drawer search filter and scrollable container preventing layout clutter on 200+ MAC trunk links.
  - Inline SFP optical telemetry badge showing vendor, part number, serial number, and live RX/TX power in dBm.

- **Alcatel OmniSwitch Enterprise MIB Support (`cron_switch_poll.php` & `includes/db.php`)**:
  - Native support for Alcatel `ALCATEL-IND1/ENT1-MAC-ADDRESS-MIB` (`slMacAddressTable`, `slMacToPortMacTable`, `alaSlMacAddressGlobalTable`) with `ifIndex . vlan . MAC` tuple parsing.
  - Automated database migration query purging corrupted legacy sequential fake MAC records (`00:00:00:00:00:xx`).
  - Native 802.1Q tagged VLAN discovery auto-registered into `switch_port_vlans`.

- **Enterprise Hardware & OUI Database Expansion (`includes/network.php`)**:
  - Expanded OUI database with Cisco Meraki, Alcatel-Lucent Enterprise, HPE Aruba, Ruijie / Reyee, Grandstream, Yealink, ZTE, Hikvision, Dahua.

- **Multi-Device Responsive Design**:
  - Optimized layout for mobile, tablet, and desktop: compact tree connectors, fluid typography, and horizontal table swiping (`overflow: auto;`).

- **Apache URL Routing & Trailing-Slash Sanitizer (`.htaccess`)**:
  - Added trailing-slash rewrite rule preventing 403 Forbidden / 500 Internal Server Error loops on route names.

- **Enterprise Discord Rich Embeds & Webhook Sentinels (`includes/notifications.php` & `settings.php`)**:
  - Upgraded Discord notifications from plain markdown text to interactive **Discord Rich Embed Cards**:
    - Dynamic sidebar accent colors (🔴 Red for DOWN/Loop, 🟢 Green for UP/Resolved, 🟡 Amber for Warning/Conflict, 🔵 Blue for New Device).
    - Structured 2-column inline grid fields (Device, IP, Status, Latency, Downtime Duration, Timestamps).
    - Native ISO8601 Discord live relative timestamps (`<t:time:R>`).
    - Automated username override (`NetScopePro Sentinel`) with branded NOC footer.
  - Added one-click **Test Discord Webhook** and **Test Slack Webhook** buttons in System Settings with immediate diagnostic feedback.

## [2.31.3] - 2026-10-04
### Fixed & Hardened
- **SSH Channel 1 Collision Fix (`api/server-metrics.php`)**:
  - Eliminated `RuntimeException: Please close the channel (1) before trying to open it again` by ensuring secondary device probes (e.g. RouterOS) run on a clean, isolated connection rather than reusing an open channel.
  - Replaced blocking commands (`top -b -n 1`, `sudo ipmitool`) with non-blocking instant telemetry via `/proc/stat` and `/proc/loadavg` (100ms delta), guaranteeing fast execution.
- **SSH Connection Leak & MaxStartups Exhaustion Fix (`api/server-metrics.php`)**:
  - Added explicit `$ssh->disconnect()` across all execution paths (success, fallback, and error handlers) to terminate SSH sessions immediately after reading metrics.
  - Prevents target server OpenSSH daemons from hitting `MaxStartups` limits during extended live monitoring sessions.
- **Resilient Frontend Polling & Concurrency Guard (`server-assets.php`)**:
  - Added `isFetchingMetrics` mutex flag to prevent overlapping concurrent HTTP/SSH requests if network latency fluctuates.
  - Added consecutive error tolerance threshold (`consecutiveErrors`) to prevent single transient network blips from killing live charts or aborting polling.
  - Adjusted polling cadence to a stable 6-second interval with proper timer cleanup on modal close.

## [2.31.2] - 2026-10-04
### Fixed & Hardened
- **Server Assets Live Metrics Modal Fix (`server-assets.php` & `api/server-metrics.php`)**:
  - Fixed `SyntaxError: Unexpected token '<'` when opening Live Metrics modal.
  - Replaced `catch (\Exception $e)` with `catch (\Throwable $e)` to guarantee any runtime errors return valid JSON rather than raw HTML error pages.
  - Added proactive check `class_exists('phpseclib3\Net\SSH2')` to prevent fatal class not found crashes when dependencies are missing.
  - Enhanced frontend `fetchMetrics()` to parse text responses safely, cleanly extract error messages from non-JSON responses, and terminate the loading spinner immediately on failure.
  - Added `session_write_close()` before long SSH polling operations to release PHP session locks and prevent freezing the web application.
- **Docker Vendor Dependency Auto-Restoration (`Dockerfile` & `entrypoint.sh`)**:
  - Added Composer and build-time dependency caching into `/opt/vendor-backup` during image creation.
  - `entrypoint.sh` now automatically restores `/opt/vendor-backup` into `/var/www/html/vendor` when host volume mounts mask the container's vendor directory.
- **Absolute Path Resolution in Server Asset APIs**:
  - Standardized `__DIR__` inclusion across `api/server-metrics.php`, `api/asset-health.php`, and `api/get-asset-password.php`.

## [2.31.1] - 2026-10-04
### Fixed & Enhanced
- **Zero-Touch Database Auto-Healing (`includes/db.php`)**:
  - Automatically detects missing core tables (`users`, `subnets`, `switches`, `ip_addresses`, `vlans`, `switch_port_map`).
  - If tables are missing (e.g. fresh installation or interrupted initial load), automatically imports `sql/database.sql` directly via PDO.
  - Implements a hardened fallback mechanism to guarantee table creation and seeds default `admin` (`admin123`) credentials even if the SQL file is inaccessible.
- **Fresh Docker Container Boot Fix (`sql/database.sql`)**:
  - Fixed syntax/column mismatch in `sql/database.sql` (Line 503) where `switches` table had 21 columns but `INSERT INTO switches VALUES` only supplied 16 values, causing MariaDB's entrypoint script to abort halfway through.
  - With explicit column definitions, fresh Docker seed now imports 100% of all 20 tables without errors.
- **Proactive Initialization on Login (`login.php`)**:
  - Connects to the database and runs auto-healing on initial page visit (GET) rather than deferring to POST submission.
  - Wrapped authentication queries in resilient try-catch logic with automatic schema recovery retry, completely preventing `Table 'ipmanage.users' doesn't exist` crashes.
- **Container Startup Upgrade (`includes/db_upgrade.php`)**:
  - Integrated schema verification logging during container entrypoint execution.

## [2.31.0] - 2026-10-02
### Added
- **IP Conflict & Flap Center (`conflicts.php` & `includes/conflict.helper.php`)**:
  - Dedicated NOC-grade Conflict Center module for monitoring, investigating, and mitigating IP address conflicts and MAC thrashing.
  - **Dual-Host Forensic Comparison**: Side-by-side card comparing Host A (Incumbent) vs Host B (New Intruder/Device) with MAC address, Vendor (OUI), Physical Switch & Port, VLAN, Hostname, Detection Timestamp, and Flap Counts.
  - **1-Click Administrative Actions**:
    - **Resolve Conflict**: Clears conflict flags and stores the resolution audit trail.
    - **Accept New Host**: Adopts the new MAC address as the legitimate host, updating device history and clearing the flag.
    - **Ignore / Acknowledge**: Suppresses active alerts for benign dual-NIC or bridge environments without deleting forensic logs.
    - **Live 3-Cycle Sequential Prober**: Triggers real-time ARP and MAC stability verification directly from the event row.
  - **Flapping History Timeline**: Complete historical log table of all detected conflict events with status badges, timestamps, and resolution summaries.
  - **Navigation & Badges**: Added dedicated "Conflict Center" in sidebar navigation under *Monitoring & Tools* with a dynamic red badge counter showing active conflicts.
  - **Automated Event Detection**: Integrated into `scanner_worker.php` and `api/scan.php` to automatically log new MAC flapping events into `ip_conflict_events` table during network sweeps.
  - **Auto-Migration**: Idempotent table creation for `ip_conflict_events` in `includes/db.php` and `includes/db_upgrade.php`.

### Fixed
- **L2 Switching Loop & STP Stability Prober False Alarm Fix (`tools.php`)**:
  - MikroTik RouterOS Bridge returns `dot1dStpPortState = 2 (blocking)` on disconnected/unplugged ports (`ifOperStatus != 1`).
  - The live diagnostic prober now queries `dot1dBasePortIfIndex` and `ifOperStatus` (`.1.3.6.1.2.1.2.2.1.8`) to confirm physical link state before evaluating STP blocking states.
  - Link-down ports are now classified into a separate **`Inactive / Down`** category (e.g. `ether3-to-Sw (Down)`) and will no longer trigger false-positive loop mitigation alarms or critical warnings.
  - Enhanced interface naming to display friendly port labels instead of generic `Port #X` numbering.
  - Hardened database fallback to verify `port_status = 'up'` before flagging blocked ports.

## [2.30.0] - 2026-10-02
### Added
- **IP Intelligence Dossier (NetScope 360°)**:
  - Introduced unified single-pane dossier modal (`includes/ip-intelligence-modal.php` & `api/ip-intelligence.php`) accessible across the entire application.
  - **L2 Physical Attachment**: Correlates IP &rarr; MAC &rarr; Switch Port (`etherX`), Switch Model, Port Speed, STP State, and SFP Optical telemetry.
  - **L3 Network Context**: Subnet CIDR, Gateway, VLAN ID & Name, DNS resolvers.
  - **Device Telemetry**: Hostname, Asset Tag, Owner, MAC Address, Vendor (OUI), OS fingerprint, and Discovery Confidence breakdown.
  - **Conflict Watch & 1-Click Resolver**: Real-time IP conflict indicator with conflicting MAC details and one-click conflict resolution directly from the modal, logged to `audit_logs`.
  - **Universal Search Integration**: Universal search (`Cmd+K` / `Ctrl+K`) now indexes `ip_addresses` and directly opens the Intelligence Dossier upon selection.
  - **Clickable IP Triggers**: Wired across `subnet-details.php` (table and visual grid), `switch-details.php` (port list), and `index.php` (conflict alert banner and Needs Attention table).
- **Executive NOC Dashboard Redesign (`index.php`)**:
  - Restructured top section into a high-density **4-Pillars Executive Metric Bar**: IPAM Fleet, Switch Fabric & Port Capacity, Netwatch Infrastructure Monitor, and Discovery Quality & Confidence.
  - Balanced telemetry layout: 7-Day Usage Trend Line Chart paired with Radial Allocation Progress, and Switch Port Distribution Donut Chart paired with Host State Distribution.
  - Integrated rich **NOC Quick Actions** shortcuts (`+ New Subnet`, `Switches`, `Netwatch`, `VLANs`, `Conflict Prober`, `Settings`).
  - Fixed HTML nesting defect where Needs Attention table was improperly enclosed within quick grid container.

### Fixed
- **SNMP Poller Anti-Freeze & PHP Session Lock Release**:
  - Added `session_write_close()` across background-capable scripts (`cron_switch_poll.php`, `cron_scanner.php`, `cron_netwatch.php`, `api/scan.php`). Prevents exclusive PHP session file locking on Apache/Windows that caused the entire web application to freeze while polling unreachable switches.
  - Implemented 1.0-second SNMP reachability pre-flight probe (`@snmp2_get`) on switch `sysDescr`. Skips dead or unreachable switches in 1 second instead of hanging for minutes.
- **MikroTik & Multi-Brand Inactive Port STP Filter**:
  - MikroTik RouterOS Bridge returns `dot1dStpPortState = 2 (blocking)` on disconnected/unplugged ports (`ifOperStatus != 1`).
  - Added physical link status check (`ifOperStatus == 1`) before evaluating STP blocking states in `cron_switch_poll.php` and `switch-details.php`. Completely eliminates false-positive "L2 Switching Loop / Blocking" alarms on disconnected switch ports.
- **Subdirectory Routing & 404 Fix (`.htaccess`)**:
  - Removed rigid `RewriteBase /` and absolute leading slash redirect in `.htaccess` that broke subfolder installations (e.g. `/ipmanage/` in XAMPP) with 404 Not Found errors. Restored directory-relative rewrite rules compatible with both root domain (Docker) and subfolder deployments.

## [2.29.2] - 2026-10-01
### Fixed
- **Subnet Usage Bar Synchronization & Accurate State Filter**:
  - Fixed query discrepancy between Subnet List (`subnets.php`, `export.php`, `reports.php`, `index.php`) and Subnet Details (`subnet-details.php`). Usage bars now consistently count allocated/used IPs (`active`, `reserved`, `dhcp`), ignoring stale `offline` records.
  - Aligned `$assigned_total` and `$stats['free']` in `subnet-details.php` to match the visual segments on the utilization scale bar.
- **CIDR Calculation Network Boundary Masking**:
  - Fixed `cidr_to_range()` in `includes/network.php` to apply bitwise network mask (`$ip_long & $mask_long`). Subnets specified with gateway IPs (e.g. `10.11.0.1/24`) now calculate true network boundaries (`10.11.0.0` to `10.11.0.255`) instead of shifting the start by 1 IP.
  - Added `normalize_subnet_address()` to normalize inputs upon adding or editing subnets.
- **Orphaned / Ghost IP Record Healing**:
  - Implemented `sync_and_cleanup_orphaned_ips()` which automatically detects and purges or reassigns ghost IP records whose `subnet_id` no longer falls within their subnet CIDR range after a subnet has been edited.
- **Switch Polling & Redirect Stability**:
  - Fixed 404 error redirecting to `/var/www/html/switches`: added `RewriteBase /` with leading slash rewrite in `.htaccess` and changed `cron_switch_poll.php` redirect target to clean URL `switches?message=Poll completed`.
  - Fixed PHP Notices "Only variables should be passed by reference" on `end(explode())` in `includes/vendor.helper.php` and `includes/network.php`.
  - Fixed PHP Warnings "Undefined array key rx_power/tx_power" in `cron_switch_poll.php` by properly initializing telemetry keys in vendor SFP helper and using null coalescing.
- **Database Auto-Migrations (`includes/db.php`)**:
  - Integrated `switch_port_vlans`, traffic history counters, and switch hardware metrics into the central auto-migration routine.

## [2.29.1] - 2026-10-01
### Fixed
- **Server Assets Decryption Stability & Ciphertext Leak Fix**:
  - Implemented multi-key fallback in `AssetHelper::decrypt()` (active database key &rarr; legacy standard key `'27ffed91f93d4e8eaf12a66852b4a156'`), preventing existing encrypted credentials and notes from becoming unreadable after container rebuilds or key regeneration.
  - Added safety fallback in `server-assets.php` to prevent raw base64 ciphertext from being dumped onto dashboard cards.
  - Fixed card checkbox positioning in `server-assets.php` to reside cleanly inside the card header rather than hanging outside card boundaries.
- **Universal Live Server Metrics (`api/server-metrics.php`)**:
  - Switched Linux metric collection to native kernel interfaces (`/proc/meminfo`, `/proc/stat`, `/proc/uptime`, `df -P /`, and `/proc/net/dev`) for reliable data across all distros (Debian, Ubuntu, CentOS, Alpine, Busybox, Docker).
  - Added automatic detection and telemetry parsing for **MikroTik RouterOS** via `/system resource print`.
  - Replaced silent fallback to 0% with transparent error reporting when target shell output cannot be parsed.

## [2.29.0] - 2026-09-30
### Added
- **Evidence-Based IP Conflict Prober (`tools.php?action=conflict`)**:
  - **Sequential 3-Cycle MAC Stability Check**: Executes 3 successive ping and ARP resolution probes to catch oscillating MAC addresses caused by physical IP collisions in real time.
  - **Multi-Layer Evidence Summary Checklist**: Structured breakdown covering Host Reachability, Multi-Probe MAC Consistency, ICMP TTL Fingerprint, Switch L2 Port Path, and IPAM Database records.
  - **Diagnostic Confidence Score**: Quantified confidence rating (`HIGH`, `MEDIUM`, `LOW`) and conflict risk probability percentage so engineers immediately know the diagnostic certainty.
- **Enhanced L2 Switching Loop & STP Stability Prober (`tools.php?action=loop`)**:
  - **TCN Operational Analysis**: Interprets `dot1dStpTimeSinceTopologyChange` with human-readable elapsed time and real-time recalculation flags (`< 60s`), grading network convergence stability (`HIGH`, `MODERATE`, `CRITICAL`).
  - **CAM Table Thrashing & Flapping Detection**: Cross-references FDB MAC counts across switch ports to spot abnormal concentration or flapping between ports.
  - **Evidence Summary Checklist & Confidence Scoring**: Summarizes STP protocol, loop guard status, TCN recalculation rate, and CAM distribution before drawing conclusions.
  - **Engineer-Accurate Non-Absolute Verdict**: Replaced misleading absolute claims with realistic verdicts (`NO ACTIVE LOOP INDICATORS DETECTED ON TARGET SWITCH`) accompanied by diagnostic scope notes acknowledging unmanaged hub boundaries.
- **Documentation**: Updated `docs/07-loop-and-conflict-detection.md` with detailed explanations of the 5-phase IP Conflict probe and 7-phase L2 Loop probe architecture.

## [2.28.1] - 2026-09-26
### Improved
- **L2 Switching Loop & MAC Flapping Accuracy**:
  - Implemented pair-specific MAC thrashing analysis (`Port A <-> Port B`) to eliminate false positive loop alerts caused by normal Wi-Fi roaming and mobile client movement.
  - Added configurable `loop_flap_threshold` sensitivity setting in `settings.php` (default: 5 MACs on the exact same port pair).
  - Implemented state-transition alerting to prevent notification spamming: alerts are only sent on new loop events or condition changes, with automatic recovery notification (`RESOLVED: L2 Switching Loop Cleared`) when topology stabilizes.
  - Fixed per-switch STP blocked port tracker isolation in `cron_switch_poll.php` preventing cross-switch state leakage.
- **Modern Sleek Dark Scrollbar**:
  - Replaced bright-white default Windows/browser scrollbars and legacy arrow buttons with floating semi-transparent pill scrollbars.
  - Added native `color-scheme: dark` and thin Firefox `scrollbar-width` support.
  - Added cache-busting version parameters to CSS links in `header.php` and `login.php`.

## [2.28.0] - 2026-09-11
### Added
- **Enterprise Multi-Event Telegram Alert Engine**: Comprehensive instant alerting via Telegram Bot API with clean HTML formatting, supporting:
  - 🔄 **L2 Switching Loop & STP Blocking Alerts**: Real-time notification when switch loops or ports in `BLOCKING` state are detected by `cron_switch_poll.php`.
  - 🚨 **Netwatch Host Down & Recovery Alerts**: Instant downtime notification with latency and downtime duration metrics.
  - ⚠️ **Enterprise IP Conflict Alerts**: Dispatches alerts when IP collision or MAC flapping occurs across subnets.
  - 📉 **SFP Optical Fiber (DDM) Warnings**: Alerts when SFP RX optical power drops below safety thresholds (e.g. `<= -24 dBm`).
  - ⚡ **New Device Discovery Alerts**: Notifications when newly discovered active hosts appear on any subnet.
- **Granular Alert Toggles in System Settings**: Added event-specific checkboxes in `settings.php` allowing administrators to enable or disable individual alert categories.
- **Anti-Spam & Intelligent Cooldown Engine**: Lightweight lock-based throttling mechanism preventing administrators from being flooded with duplicate alert notifications during prolonged network incidents.
- **Switch Port Live Bandwidth & Throughput Visualization**:
  - Direct `Traffic` button pill on each switch port row in `switch-details.php` with smooth auto-scroll to the chart.
  - Enhanced Chart.js throughput graph with dual-color gradient fills (Inbound/Download in Sky Blue, Outbound/Upload in Pink).
  - Time range selectors for `1h`, `6h`, `24h`, and `48h`.
  - Live KPI metric cards displaying Current RX/TX, Peak RX/TX, and Average RX/TX in Mbps computed from SNMP 64-bit counter history.
  - Enhanced `api/port-history.php` with statistical aggregates (`stats` object) and responsive time formatting.

## [2.27.0] - 2026-09-11
### Added
- **L2 Switching Loop Detection Engine**: Automated discovery of network loops in `cron_switch_poll.php` by analyzing Spanning Tree Protocol (STP) blocked ports and rapid MAC address thrashing (flapping) between physical ports on the same switch.
- **L2 Loop & STP Stability Diagnostic Prober**: New interactive diagnostic tool in `tools.php` (`action=loop`) that runs multi-phase diagnostic probes on switches (STP configuration, Root Bridge election, Port Forwarding vs. Blocking states, TCN rate, and FDB MAC distributions).
- **Spanning Tree (STP / RSTP) State Discovery**: Added SNMP discovery for `dot1dStp` MIB (protocol specification, root cost, time since topology change, and per-port STP operational states: disabled, blocking, listening, learning, forwarding).
- **Dashboard NOC Switching Loop Alert Banner**: Added high-priority banner in `index.php` that immediately alerts network operations of active switching loops or STP blocked ports with one-click direct diagnostics.
- **Switch Management Loop Indicators**: Enhanced `switches.php` cards with active loop warning badges, STP protocol tags (e.g. `RSTP`, `STP`), and direct loop probe shortcuts.
- **Switch Port Mapping STP Badges**: Updated `switch-details.php` with STP & Topology Stability sidebar card and per-port `STP: FWD` / `🚫 BLOCKING` status badges to identify ports actively preventing network loops.
- **Database Schema Expansion**: Added `stp_enabled`, `stp_protocol`, `loop_detected`, `loop_details`, `stp_topology_changes` to `switches` table and `stp_state` to `switch_port_map` with idempotent auto-migration in `includes/db.php` and `includes/db_upgrade.php`.

## [2.26.0] - 2026-09-11
### Added
- **IP Conflict Detection Engine**: Real-time identification of IP collisions and MAC flapping in `api/scan.php` and `scanner_worker.php`.
- **Database Schema Expansion & Indexing**: Extended `ip_addresses` with `conflict_mac`, `conflict_details`, and `idx_conflict` index on `conflict_detected` for instantaneous queries across large subnets, fully managed by idempotent auto-migrations in `includes/db_upgrade.php` and `run_auto_migrations()`.
- **Multi-OS Collision Heuristic**: Automatically flags disparate/inconsistent OS fingerprints (e.g. MikroTik RouterOS + Linux VM / OpenWrt responding on the same IP) as active conflicts.
- **Conflict Diagnostic Prober**: New interactive network diagnostic tool in `tools.php` with live multi-probe ICMP TTL variance detection, ARP integrity verification, and L2 physical switch port cross-referencing.
- **Dashboard NOC Alert Banner**: Prominent NOC alert banner in `index.php` that dynamically notifies network engineers of active IP collisions with quick actions to probe or view subnets.
- **Subnet Details Conflict Filter**: Added "Conflict Only" state filter and automatic URL parameter resolution (`?filter=conflict`) to isolate colliding IP addresses instantly.
- **Conflict Resolution Workflow**: Subnet grid and table views in `subnet-details.php` now feature prominent `CONFLICT` badges, collision tooltips, and a modal action to resolve and clear conflict states.
- **Brute-Force Rate Limiting**: Added session-based failed login rate limiting in `login.php` with temporary lockout to protect against brute-force attacks.
- **Windows Task Runner (`run_cron.bat`)**: Automated background service script for Windows/XAMPP environments to easily run Netwatch, Subnet Scanner, and Switch Poller tasks.

### Changed
- **Persistent Conflict State**: Prevented premature erasure of conflict states during subsequent scans until explicitly resolved.
- **Multi-Drive MIBDIRS Auto-Detection**: Dynamically locates Net-SNMP MIB directories on both `D:\xampp` and `C:\xampp` in `.htaccess` and `includes/config.php`.
- **Composer & Dependency Management**: Integrated autoloading for `phpseclib` and configured `.gitignore` to prevent tracking of local composer binaries.

### Fixed
- **PHP Function Redeclaration Guard**: Added `function_exists` guards and `__DIR__` paths in `includes/db.php`, `includes/settings.helper.php`, `includes/config.php`, `cron_netwatch.php`, and `cron_switch_poll.php` preventing fatal redeclaration crashes during CLI and web invocation.

## [2.25.2] - 2026-07-20
### Added
- **Nmap OS Fingerprinting**: Integrated OS fingerprinting into scanner workers for both Legacy and Masscan discovery modes.
- **MAC Enrichment**: Enhanced MAC address resolution for the Masscan discovery pipeline.

### Changed
- **Retention Tuning**: Reduced offline IP auto-cleanup threshold to 1 hour (previously 24 hours).

## [2.25.1] - 2026-07-20
### Added
- **UI Categorization**: Sidebar menu is now grouped into "Infrastructure", "Monitoring & Tools", and "Account" sections for better readability.
- **Status Indicators**: Netwatch menu item now displays a red badge dynamically showing the count of down devices.

### Changed
- **Navigation UX**: Added smooth CSS hover transitions to all sidebar navigation items.
- **Lucide Icons**: Replaced `data-lucide="github"` with an inline SVG across the app due to Lucide dropping brand icons.

### Fixed
- **Clean URLs in Subfolders**: Updated `.htaccess` with generic relative rewrite rules, fixing 404 errors when running the application in a subfolder (removing hardcoded `RewriteBase`).

## [2.25.0] - 2026-06-24
### Added
- **SFP/DOM Transceiver Monitoring**: Automatic detection and logging of SFP module data (Vendor, Part, Serial, Rx/Tx Power) during switch polling via SNMP.
- **MikroTik RouterOS v7 Support**: Full compatibility with RouterOS v7 SNMP polling including `mtxrOpticalTable` for optical transceiver DOM data.
- **Juniper DOM Support**: Integration of `jnxDomCurrentTable` OIDs for reading Juniper transceiver Rx/Tx power levels.
- **Mobile-First Responsive Grids**: New `.grid-stats` and `.grid-2` CSS classes with proper `@media` breakpoints for tablet and mobile stacking.
- **Ponytail Dev Rules**: Integrated lazy senior dev coding philosophy for AI-assisted development efficiency.

### Changed
- **Login Page Redesign**: Complete rewrite of `login.php` with professional dark NOC Console theme — removed all AI-generated gradients, blobs, and excessive animations.
- **Unified Color Palette**: Replaced all legacy `#3b82f6` (Tailwind Blue) and `#6366f1` (Indigo) references with standardized `var(--primary)` / `#58a6ff` across `settings.php`, `topology.php`, `switch-details.php`.

### Fixed
- **Clean URL Redirect Bug**: Added `RewriteBase /ipmanage/` to `.htaccess` to prevent Apache from using full filesystem path (`C:/xampp/...`) in 301 redirects.
- **Login POST Data Loss**: Changed form action from `login.php` to `login` to prevent 301 redirect stripping POST data.

## [2.24.1] - 2026-05-22
### Fixed
- **Database Maintenance**: Bypassed authentication restrictions for CLI contexts using the `IS_CRON` constant.
- **SQL Date Parsing**: Refactored cleanup threshold checks to utilize PHP `date()` rather than MySQL `INTERVAL` parameter bindings to eliminate syntax errors on legacy database engines.
- **Subnet Scanner Execution**: Replaced a premature `exit;` in the subnet scanner with a conditional block, ensuring subsequent maintenance and cron tasks are finalized.
- **Netwatch Retention**: Removed statically coded cleanup statements from netwatch loops, transferring retention authority to user-defined settings.

### Security
- **Cron Script Hardening**: Added robust key-based and session-based authentication to `cron_netwatch.php`, `cron_scanner.php`, and `cron_switch_poll.php` to prevent unauthorized HTTP executions.
- **CLI Log Sanitization**: Modified `cron_switch_poll.php` to detect CLI sapi mode and suppress HTML, CSS, and javascript blocks during cron executions, keeping logs clean.
- **Git Ignore Hardening**: Added `debug-*.php` and `check_*.php` to `.gitignore` to prevent accidental commits of diagnostic files.

## [2.24.0] - 2026-05-10
### Added
- **Database Maintenance System**: Complete auto-cleanup engine (`cron_cleanup.php`) that automatically purges expired data from time-series tables using configurable retention policies.
- **Database Health Dashboard**: New "DATABASE" tab in Settings showing real-time table row counts, disk usage, per-table progress bars, and last cleanup timestamp.
- **Configurable Retention Policy**: Per-table retention settings (Port History, Health History, Netwatch History, Audit Logs) configurable from the UI with recommended defaults (30/90 days).
- **One-click Manual Cleanup**: AJAX-powered cleanup button with detailed per-table results feedback (rows deleted, remaining, status per table).
- **Daily Auto-Cleanup Integration**: Cleanup automatically runs once per day when `cron_scanner.php` executes, controlled by the "Auto Cleanup" toggle in Settings.

### Optimized
- **Performance Indexes**: Added `idx_created_at` on `audit_logs` and `idx_switch_port_time` composite index on `switch_port_history` for faster date-range queries.
- **Batch Delete Engine**: Data cleanup uses 10K-row batches to prevent table locking on large datasets.
- **Auto OPTIMIZE TABLE**: Tables are automatically optimized after significant cleanup (>1000 rows deleted) to reclaim disk space.
- **AUTO_INCREMENT Reset**: Counter IDs are automatically reset after heavy cleanup to prevent unnecessary ID inflation.

### Changed
- **Switch Health Retention**: `cron_switch_poll.php` now uses the configurable retention setting from the database instead of a hardcoded 48-hour window.

## [2.23.0] - 2026-05-09
### Added
- **Major Rebranding (NetScope Pro)**: Transitioned the entire application identity to NetScope Pro, including new logos, consistent naming, and professional "About" credits.
- **Enterprise OS Fingerprinting**: Integrated Nmap-based OS detection into the scanning engine for accurate identification of Windows, Linux, and IoT devices.
- **Robust Ghost IP Prevention**: Re-engineered the detection logic to require physical MAC address validation for local subnets, eliminating false positives from broadcast pings.
- **Optimized Cisco SNMP Polling**: Fixed critical bugs in Cisco switch monitoring, including missing VLAN mappings and LAG interface transparency.
- **Non-blocking SSE Health Stream**: Optimized real-time switch monitoring to use session-aware write closing, preventing dashboard lag and request blocking.
- **API Output Protection**: Implemented global output buffering and "Safe Fetch" JSON parsing to prevent PHP warnings from corrupting frontend data.

## [2.22.1] - 2026-05-02
### Added
- **Styled SNMP Terminal UI**: Replaced raw text output for switch polling with a professional dark-themed console interface including auto-scrolling and real-time status headers.
- **Auto-Redirect Logic**: Implemented intelligent task redirection to automatically return the user to the management page after polling is completed.
- **Enhanced OS Detection**: Integrated Nmap fingerprinting into the manual subnet scan engine, respecting the global "Deep Scan" settings while maintaining UI performance.
- **Aggressive Privacy Hardening**: Implemented advanced autocomplete bypass using the `readonly-onfocus` technique across critical forms (Login, Settings, IP Management) to ensure browsers strictly respect privacy settings and do not leak credential suggestions.
- **SNMP ARP Fallback**: Advanced multi-table ARP discovery (MIB-II, Modern IP-MIB, and Alcatel-Specific) to ensure 100% IP-to-MAC resolution on core switches.


## [2.21.0] - 2026-05-02
### Added
- **SNMP Multi-vendor Engine**: Complete rewrite of the polling logic to support 30+ hardware vendors (Fortinet, pfSense, MikroTik, Cisco, Huawei, Juniper, etc.) with centralized OID management.
- **Subnet Scan Optimization**: Implemented high-performance ARP pre-seeding (batch firing) and parallel discovery signals to prevent timeouts on large subnets.
- **Dedicated pfSense Handler**: Specialized monitoring for pfSense/OPNsense via `net-snmp` (UCD-SNMP-MIB) OIDs for accurate CPU/RAM reporting.
- **Real-time Scan UI**: Dashboard and Subnet views now display real-time elapsed time and more granular progress status during network discovery.

## [2.20.0] - 2026-04-28
### Added
- **SNMP Switch Monitoring**: Granular discovery of port status, VLAN names, interface speed, and port aliases.
- **Auto-Generated Topology Map**: The network map now automatically visualizes the switch hierarchy using `Parent Switch` relationships.
- **Switch Hardware Dashboard**: New "Switch Hardware Capacity" widget on the main dashboard for global port tracking (Active vs Available).
- **Uplink/Trunk Detection**: Intelligent identification of uplink ports based on MAC address density (>3 MACs per port).
- **Multi-vendor Fallback**: Optimized SNMP polling for Huawei, TP-Link, and Ruijie switches via bridge-port to ifIndex mapping.

## [2.19.0] - 2026-04-19
### Added
- **Netwatch Latency History**: Visual graphing of host response time (ms) over the last 24 hours using Chart.js.
- **Multi-Channel Webhooks**: Native support for Discord and Slack notifications with easy webhook integration.
- **Customizable Alerts**: Fully modifiable notification templates with dynamic placeholders ({name}, {host}, {latency}, etc.).
- **Maintenance Mode (Snooze)**: Ability to silence notifications for specific targets (1h, 6h, 24h) during planned maintenance.
- **Scanner Health Monitor**: New UI indicator showing real-time background scanner activity and last global check-in time.


## [2.18.1] - 2026-04-19
### Added
- **Downtime Duration Tracking**: Alerts now automatically calculate and display how long a host was offline once it recovers.
- **Regional Timezone Sync**: Enforced `Asia/Jakarta` (WIB) timezone alignment across PHP and Database sessions for accurate logging.

### Fixed
- **Ping Engine Robustness**: Optimized Windows ICMP parsing to handle diverse OS output formats correctly.
- **Form Resubmission Bug**: Implemented PRG (Post/Redirect/Get) pattern for Netwatch targets to prevent duplicate entries on refresh.
- **Telegram Notification Stability**: Switched notification payload to HTML mode with built-in character escaping for 100% delivery reliability.
- **Interval Enforcement**: Fixed background logic to strictly honor per-target ping intervals.

## [2.18.0] - 2026-04-16
### Added
- **Netwatch Monitoring Module**: Implementation of a proactive host availability tracking system inspired by MikroTik.
- **Dashboard Status Widgets**: Added a dedicated Netwatch Status section on the main dashboard for real-time UP/DOWN visibility.
- **Background Scan Engine**: New `cron_netwatch.php` utility with intelligent fail-thresholds and audit logging.
- **AJAX Trigger**: Integrated "Scan All Now" functionality into the UI for instant manual monitoring refreshes.

### Fixed
- **UI/UX Headers**: Resolved PHP warnings for undefined session keys (username/role) on guest or freshly initialized sessions. 
- **Consistency**: Refined the sidebar layout to ensure all modules are accessible across the core dashboard.

---

## [2.17.0] - 2026-04-12
### Added
- **Premium Visualization Overhaul**: Redeployed all dashboard and report charts using high-fidelity styling (linear area gradients, gridless axes, and premium tooltips) inspired by Material Tailwind.
- **Network Reports Page**: Implementation of a professional `reports.php` module for deep-dive analytics, historical growth tracking, and subnet density metrics.
- **Interactive Progress Tracking**: Added a semi-circle radial progress visualization for global IP allocation tracking.
- **Responsive Charts**: Optimized all Chart.js instances to adapt legend visibility and sizing for mobile viewports.

### Fixed
- **Navigation**: Resolved a 404 error on dashboard links to regional progress reports.
- **Topology Map Sizing**: Fixed an issue where Mermaid.js diagrams could overflow their parent container on high-zoom displays.
- **UX Polish**: Cleaned up visual inconsistencies (unwanted background grid lines) in doughnut and radial charts.


## [2.16.0] - 2026-04-12
### Added
- **Global Responsive Refactor**: Complete overhaul of the IPManager Pro interface to be fully mobile-first and responsive using modern CSS Grid and Flexbox.
- **Responsive Utilities**: Implementation of `.page-header`, `.table-responsive`, and `.grid-side-detail` CSS utility classes for consistent mobile-stacking behavior.
- **Mobile-Friendly Modules**: Optimized dashboard, listing pages (Subnets, Devices, Switches, Assets), and all management forms for small viewports.
- **Responsive Visualization**: Re-engineered the Network Topology Map with scroll-aware containers, adaptive legends, and improved loading states.
- **Tool Modernization**: Refactored the IP Calculator and Network Toolbox terminal output to prevent layout breaking on mobile devices.

### Fixed
- **UI Bug**: Resolved a critical syntax error in `topology-manager.php` that prevented the Link Manager from loading.
- **UX Polish**: Improved button visibility and form alignment across all secondary pages (About, Change Password, Add Subnet).

---

## [2.15.1] - 2026-04-09
### Fixed
- **UI/UX**: Resolved an issue in the Network Toolbox where the active tool highlighting (blue box) did not update upon switching tools.
- **Header**: Refined activation telemetry to include better technical context.
### Added
- **Bug Reporting System**: Internal utility for administrators to report issues directly to the developer, including automated system state capture (PHP version, OS, browser info).
- **Activation Telemetry**: One-time background notification to developer upon new installations to track active deployments.
- **Database Migrations v2**: Automated table creation for Bug Reports and settings.

### Fixed
- **Pretty URL Compatibility**: Fixed `.htaccess` redirect bug that caused API calls to fail with absolute file paths.
- **Header Robustness**: Resolved dependency issues with `NotificationHelper` in global includes.
### Fixed
- **UI Interaction**: Resolved syntax error in `server-assets.php` that prevented Add and Edit modals from opening.
- **Redundancy**: Removed duplicate batch action bar elements for cleaner DOM.

---

## [2.14.2] - 2026-04-08
### Added
- **Universal Search (Cmd/Ctrl + K)**: A high-performance spotlight-style search bar accessible from any page.
- **Batch Asset Operations**: Multi-select checkboxes for server assets with bulk status checking.
- **Professional PDF Export**: Export selected server assets into a clean, printable PDF report.
- **Enhanced Data-at-Rest Encryption**: All sensitive fields (Username, Notes, App Lists) are now encrypted in the database.
- **Visual Analytics Dashboard**: Added Server Asset health cards and category distribution charts to the main dashboard.

### Fixed
- **AssetHelper Robustness**: Improved decryption stability to handle legacy or unencrypted data gracefully without PHP warnings.

---

## [2.13.0] - 2026-04-08
### Added
- **Asset Password Encryption**: Implementasi enkripsi AES-256-CBC untuk kredensial server guna meningkatkan keamanan data at rest.
- **Secure Password Reveal**: Password hanya didekripsi saat dibutuhkan via AJAX dan mencatat kejadian akses ke Audit Logs.
- **Server Health Check (Uptime)**: Indikator status ONLINE/OFFLINE real-time untuk setiap aset server menggunakan pengecekan port TCP.
- **Server Grouping (Category)**: Dukungan pengelompokan server berdasarkan kategori/tag (misal: Production, Database, Apps).
- **Advanced CSV Backup/Restore**: Pembaruan sistem backup agar mendukung metadata kategori, status, dan flag keamanan terbaru.

### Fixed
- **Responsive Layout Improvement**: Penataan ulang elemen UI pada modal dan grid list agar lebih optimal di perangkat mobile.

---

## [2.12.0] - 2026-04-08
### Added
- **Server Assets Management**: Modul baru untuk mendata login akses (SSH/Web), spesifikasi software, dan status instalasi aplikasi pada server.
- **Automated Asset Backup**: Pengiriman backup berkala (setiap 3 hari) ke email admin/user dalam format CSV dan Teks Summary.
- **Smart CSV Restore**: Fitur import data dari file CSV backup untuk pemulihan cepat atau migrasi data aset server.
- **Personalized Backups**: Dukungan alamat email per-user untuk pengiriman backup yang lebih relevan dan aman.

### Fixed
- **Sidebar UI refinement**: Perbaikan tautan About dan penataan ulang menu navigasi agar lebih konsisten.
- **Security Check**: Penambahan verifikasi sesi dan otentikasi pada skrip background cron.

---

## [2.11.2] - 2026-04-02
### Optimized
- **Memory Optimization**: Perombakan query SQL di `subnet-details.php` agar hanya mengambil data IP per blok (256 IP), mencegah crash/lag pada subnet besar seperti `/16`.
- **Statistics Accuracy**: Perhitungan statistik (Active/Free IP) kini dilakukan di sisi database menggunakan `INET_ATON` untuk memastikan hanya IP di dalam rentang valid yang terhitung.

---

## [2.11.1] - 2026-04-02
### Added
- **Standalone Setup Guide**: Panduan instalasi mendalam (`STANDALONE_INSTALL.md`) untuk XAMPP (Windows) dan server Linux (Apache/MySQL/PHP).
- **Tata cara konfigurasi**: Dokumentasi khusus untuk modul Apache, penjadwalan Cron Job, dan optimasi PHP.

---

## [2.11.0] - 2026-04-02
### Added
- **Block-based Subnet Pagination**: Implementasi sistem navigasi blok `/24` (256 IP) untuk subnet besar seperti `/21` agar tetap ringan dan responsif.
- **Global Subnet Stats**: Bar utilisasi IP kini menghitung seluruh kapasitas subnet ($2.048$ host untuk `/21`) meskipun sedang melihat satu blok tertentu.
- **Smart Chunked Scanning**: Fungsi pemindaian otomatis kini menyesuaikan dengan blok yang sedang dibuka untuk meminimalkan beban server.

### Fixed
- **IP Display Limit**: Perbaikan bug di mana subnet yang lebih besar dari `/24` hanya menampilkan 256 IP pertama.

---

## [2.10.0] - 2026-04-01
### Added
- **Manual Network Topology Manager**: Antarmuka terpusat baru untuk mendefinisikan koneksi fisik antar switch dan switch-ke-subnet secara eksplisit.
- **Hirarki Visual Pintar**: Visualisasi peta jaringan kini mengikuti alur **Switch -> VLAN -> Subnet** yang lebih logis dan rapi.
- **Polished UI rendering**: Implementasi *loading screen* dan pencegahan *flicker* kode Mermaid.js untuk pengalaman pengguna yang lebih premium.
- **Asset Externalization**: Pemindahan logika filter ke file JS eksternal (`assets/js/topo-manager.js`) untuk mematuhi kebijakan keamanan browser (CSP).

### Fixed
- **Self-linking Logic**: Pencegahan pemilihan switch yang sama sebagai sumber dan tujuan koneksi melalui filter *real-time*.

---

## [2.9.0] - 2026-03-31
### Added
- **Smart Offline Detection (Fail Counter)**: Mekanisme baru yang mencegah IP ditandai sebagai *offline* secara instan. Menggunakan kolom `fail_count` untuk melacak kegagalan scan berturut-turut.
- **Intensive Verification Probe**: Saat IP menghilang, sistem otomatis menjalankan verifikasi mendalam (Multi-ping, Deep Port Scan, forced ARP refresh, dan Nmap fallback) sebelum menaikkan angka kegagalan.
- **Customizable Fail Threshold**: Pengaturan ambang batas kegagalan scan (default: 3) yang dapat dikonfigurasi melalui menu UI Settings.

### Fixed
- **Subdirectory URL Routing**: Perbaikan file `.htaccess` untuk mendukung Clean URL (`/login`, `/index`) secara stabil saat aplikasi diinstal di sub-folder (seperti `/ipmanage/`).

### Removed
- **Time-based Bulk Cleanup**: Penghapusan logika pembersihan massal berbasis waktu (12 jam) yang tidak akurat, digantikan sepenuhnya oleh logika per-IP yang lebih cerdas.

---

## [2.8.0] - 2026-03-29
### Added
- **PHP Opcache Optimization**: Aktivasi dan tuning Opcache di Docker untuk mengurangi lag eksekusi PHP secara drastis (2-3x lebih responsif).
- **Redis Infrastructure**: Penambahan container Redis 7 dan ekstensi `php-redis` untuk dukungan caching session dan data berkinerja tinggi.
- **Browser Favicon**: Penambahan logo SVG pada header agar muncul di tab browser (favicon).
- **Developer Profile Photo**: Integrasi foto profil pengembang dari Google Drive pada halaman About.

### Fixed
- **Fatal Error (AuditLogHelper)**: Perbaikan bug "Class not found" pada `subnet-details.php` saat melakukan alokasi IP.
- **Database Sanitization**: Pembersihan seluruh token sensitif (Telegram, SMTP) dan data user pribadi dari skema publik `sql/database.sql`.

---

## [2.7.0] - 2026-03-29
### Added
- **Realtime CPU & Memory Monitoring**: Implementasi Server-Sent Events (SSE) pada halaman Switch Details untuk streaming data CPU dan RAM langsung dari SNMP setiap 5 detik — tanpa perlu refresh halaman.
- **Live Status Badge**: Indikator badge `LIVE` / `OFFLINE` di header "Hardware Health" untuk menampilkan status koneksi SSE secara visual.
- **Performance History Charts**: Dua grafik Chart.js riwayat CPU dan Memory di bawah tabel port mapping, dengan filter periode 1h / 6h / 24h / 48h.
- **Period Summary Card**: Kartu statistik yang menampilkan jumlah Active Interfaces, Mapped Devices, Avg CPU, dan Peak CPU selama periode yang dipilih.
- **switch_health_history Table**: Tabel database baru untuk menyimpan snapshot CPU & Memory tiap polling (auto-migrate, retensi 48 jam).
- **API Endpoints Baru**: `api/switch-health-stream.php` (SSE stream SNMP live) dan `api/switch-history.php` (data riwayat untuk Chart.js).

### Enhanced
- **Smooth Bar Animation**: Progress bar CPU dan Memory kini memiliki transisi animasi halus saat nilai berubah.
- **cron_switch_poll.php**: Setiap siklus polling kini otomatis menyimpan snapshot ke tabel history dan membersihkan data lama (>48 jam).

---

## [2.6.0] - 2026-03-29
### Added
- **Docker Support**: Full production-ready Docker Compose setup dengan dua kontainer (app + db).
- **Dual Config System**: Pemisahan konfigurasi otomatis antara lingkungan Docker (`config.docker.php`) dan XAMPP (`config.php`), dideteksi via variabel `DOCKER_ENV`.
- **Docker Volume Mount**: Source code di-mount langsung ke kontainer sehingga perubahan kode tidak memerlukan rebuild image.
- **DOCKER_INSTALL.md**: Panduan instalasi Docker lengkap dalam Bahasa Indonesia.

### Fixed
- **Docker Healthcheck**: Mengganti `healthcheck.sh` dengan `mysqladmin ping` agar kompatibel dengan semua varian image MariaDB di Linux.
- **Entrypoint Permission**: Dockerfile kini memanggil `bash entrypoint.sh` secara eksplisit, mengatasi error `permission denied` akibat perbedaan permission file antara Windows dan Linux.
- **Duplicate Constant**: Hapus definisi ganda `APP_URL` yang menyebabkan error `Constant already defined` dan menggagalkan `session_start()`.
- **Database Encoding**: Sinkronisasi `sql/database.sql` ke encoding UTF-8 tanpa BOM dari backup XAMPP, agar MariaDB di Docker bisa mengimpornya dengan benar.
- **Port Conflict**: Port host database dipindah ke `3307` untuk menghindari tabrakan dengan XAMPP/MySQL lokal yang menggunakan port 3306.
- **Robust Migration**: Skrip `db.php` kini memeriksa keberadaan tabel sebelum menjalankan migrasi, mencegah crash saat database baru diinisialisasi.

---

## [2.5.0] - 2026-03-28
### Added
- **L3 ARP Discovery**: Active polling of switch ARP caches to automatically pair IP addresses with physical ports.
- **Dynamic Subnet Lookup**: Automatic discovery association with the correct IPAM subnet, satisfying database integrity.
### Enhanced
- **Robust SNMP Engine**: Switched to plain value retrieval mode for universal hardware compatibility.
- **MikroTik Fine-Tuning**: Precise OID mapping for RouterOS health vitals.
### Fixed
- **Accuracy Fix**: CPU Load calculation now correctly identifies processor load instead of frequency (no more 680% readings).
- **SQL Integrity**: Resolved foreign key constraint violations during the discovery phase.

---

## [2.4.0] - 2026-03-28
### Added
- **Switch Health Monitoring**: Real-time dashboard for CPU usage, memory utilization, and system uptime.
- **Switch Details Module**: Dedicated deep-dive view for individual switches showcasing physical port mappings.
- **Enhanced Poller**: Background SNMP engine for recurring infrastructure checks.

---

## [2.3.0] - 2026-03-28
### Added
- **Parallel Discovery Engine**: Implementation of IPC worker pools (`proc_open`) for high-speed concurrent network scanning.
### Performance
- **Database Indexing**: Optimized `mac_addr` and `hostname` columns for high-speed device filtering.

---

## [2.2.0] - 2026-03-28
### Added
- **Network Toolbox**: Native integration of Ping, Traceroute, and MAC OUI Lookups.
### Enhanced
- **Discovery Signals**: Multi-probe methodology (Ping, Nmap, TCP Ports, ARP) for near 100% accuracy.

---

## [2.1.0] - 2026-03-28
### Added
- **Audit Logs**: Comprehensive activity tracking for both users and discovery engines.
- **Chart.js Analytics**: Visual trend reporting for network utilization and subnet density.

---

## [2.0.0] - 2026-03-27
### Changed
- **Premium Core**: Initial deployment of the high-performance IPAM v2 platform.
- **UI Redesign**: Complete transformation to professional dark-mode aesthetics.
- **Multi-Platform Core**: Native support for Docker (Linux) and XAMPP (Windows).

---
