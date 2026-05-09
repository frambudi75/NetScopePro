# IPManager Pro: Development & Update History

All major functional changes, enhancements, and critical fixes are documented here.

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
