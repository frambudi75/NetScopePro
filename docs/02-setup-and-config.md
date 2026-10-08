# Setup dan Konfigurasi

Panduan komprehensif setup, konfigurasi sistem, dan optimasi performa untuk **NetScope Pro (v2.32.1+)**.

---

## 1) Setup XAMPP (Windows)

1. Letakkan source code di root web server:
   - `C:\xampp\htdocs\ipmanage`
2. Buka XAMPP Control Panel, jalankan Apache dan MySQL.
3. Konfigurasi `php.ini` (XAMPP &rarr; Apache &rarr; Config &rarr; PHP):
   - Atur `memory_limit = 512M` (wajib untuk switch dengan ribuan MAC/FDB table).
   - Pastikan ekstensi berikut aktif (tanpa tanda titik koma `;`):
     ```ini
     extension=snmp
     extension=curl
     extension=pdo_mysql
     extension=mbstring
     extension=openssl
     extension=gd
     ```
4. Buat database `ipmanage` melalui phpMyAdmin atau MySQL CLI.
5. Import skema dasar dari `sql/database.sql`.
6. Akses aplikasi:
   - `http://localhost/ipmanage`
7. **Optimasi Anti-Lag (Opsional)**:
   - Pasang Redis di Windows (via Docker Desktop atau WSL2) dan aktifkan `extension=redis` di `php.ini`.
   - Aktifkan modul `opcache` untuk mempercepat compile skrip PHP.

---

## 2) Setup Docker (Rekomendasi Linux / Production)

1. Jalankan langsung dari root proyek:
   ```bash
   docker compose up -d --build
   ```
2. Verifikasi seluruh kontainer (`netscope_app`, `netscope_db`, `netscope_redis`) berstatus healthy:
   ```bash
   docker compose ps
   ```
3. Akses web console:
   - `http://localhost:2025`
4. *Catatan Docker:* Seluruh worker background (`cron_netwatch.php`, `cron_switch_poll.php`, `cron_scanner.php`) otomatis diorkestrasi di dalam kontainer `netscope_app` melalui `entrypoint.sh`.

---

## 3) Konfigurasi Utama (`includes/config.php`)

Parameter utama yang dapat disesuaikan:

| Parameter | Default | Keterangan |
|---|---|---|
| `DB_HOST` | `localhost` / `db` | Host database MariaDB/MySQL |
| `DB_NAME` | `ipmanage` | Nama basis data |
| `DB_USER` | `root` / `ipmanager` | Username database |
| `DB_PASS` | `''` / `ipmanager_pass`| Password database |
| `REDIS_HOST` | `127.0.0.1` / `redis` | Alamat server Redis |
| `OFFLINE_TTL_MINUTES` | `30` | Batas waktu (menit) host ditandai offline jika tidak terdeteksi |
| `OFFLINE_FAIL_THRESHOLD`| `3` | Jumlah kegagalan ping beruntun sebelum status berubah offline |
| `DISCOVERY_AGGRESSIVE_MODE` | `1` | Mode deteksi aktif (menggabungkan ARP, ICMP sweep, dan TCP probe) |
| `ENABLE_NMAP_FALLBACK` | `0` (Linux Docker: `1`) | Mengaktifkan scanning cadangan berbasis binary `nmap` |

---

## 4) Prasyarat Agar Discovery dan Switch Monitoring Maksimal

- **Akses Jaringan**: Jalankan scanner/server dari host yang memiliki akses Layer-2 atau Layer-3 ke subnet dan switch target.
- **Firewall Rule**: Pastikan firewall mengizinkan:
  - ICMP outbound (Echo Request / Echo Reply);
  - TCP probe outbound (port 80, 443, 22, 445, 3389);
  - SNMP query outbound (UDP 161).
- **SNMP Switch**:
  - Aktifkan SNMP v2c (atau v3) pada switch manajemen Anda.
  - Pastikan SNMP community string pada daftar switch di NetScope Pro sesuai.
  - Aktifkan dukungan Spanning Tree Protocol (STP / RSTP / MSTP) pada switch jika ingin memanfaatkan fitur **L2 Loop Detective**.
- **Memory Limit PHP**:
  - Selalu pastikan `memory_limit` minimal **256M**, disarankan **512M** di server produksi untuk menghindari memory exhaustion saat membaca switch dengan ribuan MAC address pada multi-VLAN trunk.

---

## 5) Jadwal Background Worker (Standalone Crontab)

Untuk instalasi standalone (non-Docker), daftarkan crontab berikut:

```bash
# 1. Netwatch Host Latency & Availability Monitor (Setiap 1 menit)
* * * * * php /var/www/html/ipmanage/cron_netwatch.php > /dev/null 2>&1

# 2. Switch Telemetry, FDB MAC Table & STP Loop Detective (Setiap 5 menit)
*/5 * * * * php /var/www/html/ipmanage/cron_switch_poll.php > /dev/null 2>&1

# 3. Subnet Host Discovery Scanner (Setiap 15 menit)
*/15 * * * * php /var/www/html/ipmanage/cron_scanner.php > /dev/null 2>&1
```

---

## 6) Pengaturan Scan per Subnet

Pada menu detail subnet (`subnet-details.php`):
- `scan_interval = 0` &rarr; Pemindaian manual saja.
- `scan_interval > 0` &rarr; Pemindaian otomatis terjadwal via cron worker.

**Rekomendasi Interval:**
- Subnet server / infrastruktur stabil (/24 atau lebih kecil): 30 - 60 menit.
- Subnet client DHCP / Wi-Fi dinamis: 15 - 30 menit.
- Subnet besar (/22 atau /20): 60 - 360 menit.
