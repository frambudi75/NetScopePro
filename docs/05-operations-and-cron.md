# Operasional dan Cron

## 1) Scan Manual

Dilakukan dari UI subnet detail (tombol Scan Subnet), memanggil:

- `api/scan.php?id=<subnet_id>&start=<long>&end=<long>`

Catatan:

- Scan manual menggunakan chunking untuk progress UI.
- Cocok untuk validasi cepat setelah perubahan konfigurasi jaringan.

## 2) Scan Otomatis

Script:

- `api/cron.php`

Dijalankan periodik via:

- Windows Task Scheduler, atau
- Linux cron.

### Contoh Jadwal Windows

- Trigger: every 5 minutes.
- Action:
  - Program: `C:\xampp\php\php.exe`
  - Arguments: `C:\xampp\htdocs\ipmanage\api\cron.php`

### Contoh Jadwal Linux

```bash
*/5 * * * * /usr/bin/php /var/www/html/ipmanage/api/cron.php >> /var/log/ipmanage-cron.log 2>&1
```

## 3) Logika Scan Otomatis

Subnet dipilih jika:

- `scan_interval > 0`, dan
- `last_scan IS NULL` atau selisih menit >= `scan_interval`.

## 4) Offline Reconciliation

Di akhir `api/cron.php`, host `active` bisa berubah menjadi `offline` jika:

- `TIMESTAMPDIFF(MINUTE, last_seen, CURRENT_TIMESTAMP) >= OFFLINE_TTL_MINUTES`

Default TTL:

- 30 menit (bisa override via env).

## 5) Tuning yang Disarankan

- Jika host sering missed:
  - naikkan frekuensi cron;
  - pastikan scanner dijalankan dari segmen jaringan yang relevan;
  - verifikasi firewall ICMP/TCP/SNMP.
- Jika scan terlalu lama:
  - naikkan `scan_interval` subnet besar;
  - jalankan cron lebih sering dengan batch kecil ketimbang sangat jarang dengan batch besar.

## 6) SOP Verifikasi Pasca Deploy

1. Jalankan satu scan manual pada subnet sample.
2. Cek `devices.php`:
   - confidence tampil;
   - source badge tampil.
3. Jalankan cron sekali manual:
   - `php api/cron.php`
4. Verifikasi host stale menjadi `offline` sesuai TTL.
