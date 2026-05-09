# Panduan Instalasi Docker - IPManager Pro

Dokumentasi ini menjelaskan cara menginstal dan menjalankan **IPManager Pro** menggunakan Docker dan Docker Compose.

## Persyaratan Sistem

Pastikan Anda sudah menginstal perangkat lunak berikut:
- [Docker Engine](https://docs.docker.com/get-docker/) (v20.10+)
- [Docker Compose](https://docs.docker.com/compose/install/) (v2.0+)

Proyek ini menggunakan tiga kontainer utama:
1. **app**: Apache + PHP 8.2 (dengan `php-redis` & `opcache`). Menjalankan scanner otomatis dan **Netwatch Monitor** di background via `entrypoint.sh`.
2. **db**: MariaDB 10.11 untuk penyimpanan data persisten (diikat ke volume `db_data`).
3. **redis**: Redis 7.0 sebagai *high-performance caching layer* untuk session dan hasil polling SNMP.

## Langkah-langkah Instalasi

### 1. Clone Repositori
```bash
git clone https://github.com/frambudi75/IP-Manage.git ipmanage
cd ipmanage
```

### 2. Konfigurasi (Opsional)
Anda dapat mengubah port atau password di `docker-compose.yml`. Default:
- Port Aplikasi: `2025`
- Database User: `ipmanager` / Password: `ipmanager_pass`

### 3. Jalankan Docker Compose
```bash
sudo docker compose up -d --build
```

Perintah ini akan:
- Membangun image PHP dengan semua dependensi.
- Menjalankan MariaDB dan mengimpor skema dari `./sql/database.sql` secara otomatis.
- Menjalankan kedua kontainer di background.

### 4. Verifikasi
```bash
sudo docker ps
```
Pastikan `ipmanager_app` dan `ipmanager_db` berstatus **Up / Healthy**.

### 5. Akses Aplikasi
Buka browser: `http://localhost:2025`

**Login Default:**
- Username: `admin`
- Password: `admin123`

## Perintah Berguna

### Melihat Log Aplikasi
```bash
sudo docker logs -f ipmanager_app
```

### Melihat Log Database
```bash
sudo docker logs -f ipmanager_db
```

### Menghentikan Aplikasi
```bash
sudo docker compose down
```

### Reset Total (Hapus Semua Data)
```bash
sudo docker compose down -v
```
> **Perhatian:** Perintah `-v` akan menghapus semua data database!

### Update Kode Terbaru
```bash
git pull
sudo docker compose down
sudo docker compose up -d --build
```

## Troubleshooting

### Error: `entrypoint.sh: permission denied`
Terjadi saat file dari Windows tidak memiliki permission execute di Linux.
**Solusi:** Sudah diatasi secara otomatis — Dockerfile menggunakan `bash` untuk menjalankan entrypoint.

### Error: `getaddrinfo for db failed`
Database belum siap atau ada konflik jaringan.
**Solusi:**
```bash
sudo docker compose down
sudo docker compose up -d
```

### Error: `Access denied for user`
Volume database lama tersisa dengan kredensial lama.
**Solusi:**
```bash
sudo docker compose down -v
sudo docker compose up -d
```

### Error: `failed to bind host port 0.0.0.0:3306`
Port 3306 sudah digunakan oleh MySQL/XAMPP lokal.
**Solusi:** Database sudah dipetakan ke port **3307** di host. Tidak ada yang perlu diubah.

### Error: Docker tidak bisa connect (`/var/run/docker.sock`)
Docker Daemon belum berjalan.
**Solusi:**
```bash
sudo systemctl start docker
```
