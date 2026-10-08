# Panduan Instalasi Docker - NetScope Pro

Dokumentasi ini menjelaskan cara menginstal dan menjalankan **NetScope Pro (v2.32.1+)** menggunakan Docker dan Docker Compose.

---

## Persyaratan Sistem

Pastikan host Anda sudah terpasang:

- [Docker Engine](https://docs.docker.com/get-docker/) (v20.10+)
- [Docker Compose](https://docs.docker.com/compose/install/) (v2.0+)

Proyek ini berjalan di atas tiga kontainer utama:

1. **app (`netscope_app`)**: Apache + PHP 8.2 (dilengkapi `php-redis`, `opcache`, dan `memory_limit = 512M`). Menjalankan web interface serta orchestrator background poller (`cron_netwatch.php`, `cron_switch_poll.php`, `cron_scanner.php`) secara otomatis via `entrypoint.sh`.
2. **db (`netscope_db`)**: MariaDB 10.11 untuk penyimpanan data persisten (terikat pada volume `db_data`).
3. **redis (`netscope_redis`)**: Redis 7.0 sebagai _high-performance caching layer_ untuk session dan akselerasi data switch/IP.

---

## Langkah-langkah Instalasi

### 1. Clone Repositori

```bash
git clone https://github.com/frambudi75/NetScopePro.git netscopepro
cd netscopepro
```

### 2. Konfigurasi Lingkungan (Opsional)

Konfigurasi standar telah disiapkan di `docker-compose.yml`:

- **Port Web**: `2025` (diakses via `http://localhost:2025`)
- **Port Database Host**: `3309` (agar tidak bentrok dengan MySQL port 3306 lokal)
- **Database User**: `ipmanager` / **Password**: `ipmanager_pass`
- **Database Root Password**: `root_password_secure`

Jika ingin mengubah port aplikasi atau environment lainnya, Anda dapat mengedit file `docker-compose.yml` atau menambahkan file `.env`.

### 3. Build & Jalankan Kontainer

```bash
docker compose up -d --build
```

Perintah ini akan secara otomatis:
- Membangun image PHP 8.2 dengan seluruh dependensi SNMP, curl, nmap, dan memory limit 512M.
- Menjalankan kontainer MariaDB dan mengimpor skema dari `./sql/database.sql`.
- Menjalankan Redis cache container.
- Menjalankan healthcheck database dan meluncurkan background task otomatis.

### 4. Verifikasi Status Kontainer

```bash
docker compose ps
```

Pastikan seluruh kontainer (`netscope_app`, `netscope_db`, `netscope_redis`) berada dalam status **Up / Healthy**.

### 5. Akses Web Console

Buka browser:
```text
http://localhost:2025
```

**Kredensial Default:**
- **Username**: `admin`
- **Password**: `admin123`

*(Disarankan segera mengubah password default pada menu Settings).*

---

## Manajemen dan Operasional

### Melihat Log Aplikasi & Background Worker
```bash
docker logs -f netscope_app
```

### Melihat Log Database
```bash
docker logs -f netscope_db
```

### Menghentikan Stack
```bash
docker compose down
```

### Update ke Versi Terbaru (Git Pull)
```bash
git pull
docker compose down
docker compose up -d --build
```

### Reset Database Total (Perhatian: Menghapus Semua Data!)
```bash
docker compose down -v
docker compose up -d --build
```

---

## Penjelasan Background Task di Docker

Pada instalasi Docker, Anda **tidak perlu** mengonfigurasi crontab di sistem host secara manual. Berkas `entrypoint.sh` secara otomatis menjalankan background scheduler loop di dalam kontainer `netscope_app`:

- `cron_netwatch.php`: Memantau latensi dan status up/down host setiap 10-60 detik.
- `cron_switch_poll.php`: Memantau port switch, tabel FDB MAC, optical telemetry, dan STP loop detection setiap ~5 menit.
- `cron_scanner.php`: Melakukan sweep penemuan host subnet baru secara berkala.

---

## Troubleshooting

### Error: `failed to bind host port 0.0.0.0:3309`
Port 3309 sedang dipakai oleh aplikasi lain di host.
**Solusi:** Ubah pemetaan port di `docker-compose.yml` pada bagian `db` service, misalnya `"3310:3306"`.

### Error: `getaddrinfo for db failed` / Database Belum Siap
Terjadi jika Apache menyala sebelum MariaDB menyelesaikan inisialisasi volume.
**Solusi:** Restart kontainer:
```bash
docker compose restart app
```

### Error: `404 Not Found` pada routing (`/login`, `/dashboard`)
Pada Docker, aplikasi dijalankan pada root direktori `/` (bukan `/ipmanage/`).
`entrypoint.sh` secara otomatis menerapkan konfigurasi `.htaccess.docker`. Jika Anda memperbarui berkas secara manual, pastikan `.htaccess` menggunakan `RewriteBase /`.
Akses yang benar:
- `http://<IP-SERVER>:2025/login`
- **Bukan** `http://<IP-SERVER>:2025/ipmanage/login`
