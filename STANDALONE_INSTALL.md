# Panduan Instalasi Standalone (XAMPP / Apache / Linux)

Dokumentasi ini menjelaskan cara menginstal dan mengonfigurasi **NetScope Pro (v2.32.1+)** secara langsung di server web (tanpa Docker), seperti di lingkungan XAMPP (Windows) atau server Linux (Ubuntu/Debian).

---

## 1. Persyaratan Sistem

Pastikan server Anda memenuhi persyaratan minimum berikut:

- **PHP**: v8.1 atau v8.2 (Disarankan **v8.2**)
- **PHP Memory Limit**: Disarankan **512M** (Minimal 256M).
  > **Catatan Penting:** Switch manageable dengan tabel FDB/MAC dan VLAN besar membutuhkan alokasi memori yang cukup saat rendering rincian port dan deteksi loop (`cron_switch_poll.php` & `switch-details.php`).
- **Database**: MariaDB 10.6+ atau MySQL 8.0+
- **Web Server**: Apache 2.4 (dengan modul `mod_rewrite` aktif)
- **Ekstensi PHP Wajib**:
  - `php-snmp` (Untuk monitoring switch, FDB table, optical DOM SFP, dan STP loop detection)
  - `php-curl` (Untuk update check, webhook alert Telegram/Discord)
  - `php-pdo_mysql` & `php-mysqli` (Koneksi database)
  - `php-mbstring` & `php-gd` (Manipulasi string & rendering UI)
  - `php-openssl` & `php-bcmath` (Dukungan SSH terminal dan enkripsi)
  - `php-redis` (Opsional, sangat disarankan untuk high-performance caching & anti-lag)
- **Tools Sistem Jaringan**: `nmap`, `traceroute` / `tracert`, `net-snmp`, `iputils-ping`

---

## 2. Persiapan Lingkungan

### Windows (XAMPP)

1. Buka **XAMPP Control Panel**.
2. Klik tombol **Config** pada baris Apache, lalu pilih `PHP (php.ini)`.
3. Sesuaikan alokasi memori:
   ```ini
   memory_limit = 512M
   ```
4. Cari baris ekstensi berikut dan hapus tanda titik koma (`;`) di depannya untuk mengaktifkan:
   ```ini
   extension=snmp
   extension=curl
   extension=pdo_mysql
   extension=mbstring
   extension=openssl
   extension=gd
   ```
5. Simpan file `php.ini` dan **Restart Apache**.

### Linux (Ubuntu / Debian)

Jalankan perintah instalasi paket dependensi:

```bash
sudo apt update
sudo apt install apache2 mariadb-server \
    php php-mysql php-snmp php-curl php-mbstring php-gd php-bcmath php-xml \
    nmap traceroute iputils-ping snmp redis-server php-redis
```

Atur `memory_limit` pada PHP Apache dan PHP CLI:
```bash
# Ubah memory_limit menjadi 512M di php.ini (sesuaikan versi PHP Anda, misal 8.2)
sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.2/apache2/php.ini
sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.2/cli/php.ini
```

Aktifkan modul rewrite Apache dan restart web server:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 3. Instalasi Aplikasi

1. **Clone / Salin Kode**:
   Letakkan repositori di dalam direktori web root:
   - **XAMPP**: `C:\xampp\htdocs\ipmanage`
   - **Linux**: `/var/www/html/ipmanage`

   ```bash
   git clone https://github.com/frambudi75/NetScopePro.git /var/www/html/ipmanage
   ```

2. **Konfigurasi Database**:
   - Buka **phpMyAdmin** atau terminal MySQL:
     ```bash
     mysql -u root -p
     ```
   - Buat database baru bernama `ipmanage`:
     ```sql
     CREATE DATABASE ipmanage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
   - Impor skema dasar dari file `sql/database.sql`:
     ```bash
     mysql -u root -p ipmanage < /var/www/html/ipmanage/sql/database.sql
     ```
   *(Skema akan otomatis melakukan migrasi struktur terbaru melalui auto-healing `db_upgrade.php` saat pertama kali diakses).*

3. **Pengaturan Kredensial Database**:
   Buka file `includes/config.php` dan sesuaikan parameter koneksi:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'ipmanage');
   define('DB_USER', 'root');      // Ganti dengan user DB Anda
   define('DB_PASS', '');          // Ganti dengan password DB Anda
   ```

4. **Izin Berkas (Khusus Linux)**:
   Berikan izin kepemilikan kepada user web server (`www-data`):
   ```bash
   sudo chown -R www-data:www-data /var/www/html/ipmanage
   sudo chmod -R 755 /var/www/html/ipmanage
   ```

---

## 4. Konfigurasi Apache (Clean URL / Routing)

Pastikan Apache mengizinkan `.htaccess` agar routing seperti `/login`, `/dashboard`, `/subnets`, dan `/switches` berjalan normal.

### XAMPP
Umumnya sudah aktif secara default. Pastikan file `.htaccess` ada di root folder `ipmanage`.

### Linux (VirtualHost)
Edit file konfigurasi virtual host Apache (contoh: `/etc/apache2/sites-available/000-default.conf`):

```apache
<Directory /var/www/html/ipmanage>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Terapkan konfigurasi:
```bash
sudo systemctl restart apache2
```

---

## 5. Otomasi Background Scanner & Poller (Cron Job)

NetScope Pro membutuhkan proses latar belakang terjadwal untuk mengumpulkan data ARP, status host Netwatch, serta tabel FDB/STP switch.

### Linux (Crontab)

Jalankan `sudo crontab -e -u www-data` dan tambahkan:

```bash
# 1. Netwatch Host Availability (Setiap 1 menit)
* * * * * php /var/www/html/ipmanage/cron_netwatch.php > /dev/null 2>&1

# 2. Switch Telemetry, FDB Table & STP Loop Detective (Setiap 5 menit)
*/5 * * * * php /var/www/html/ipmanage/cron_switch_poll.php > /dev/null 2>&1

# 3. Subnet Host Discovery Scanner (Setiap 15 menit)
*/15 * * * * php /var/www/html/ipmanage/cron_scanner.php > /dev/null 2>&1

# 4. Automated Backup Database (Harian jam 02:00 pagi - Opsional)
0 2 * * * php /var/www/html/ipmanage/cron_backup.php > /dev/null 2>&1
```

### Windows (Task Scheduler)

1. Buka **Task Scheduler** > **Create Basic Task**.
2. **Netwatch Monitor (1 Menit)**:
   - Trigger: Daily, repeat every **1 minute** indefinitely.
   - Action: Start a program &rarr; `C:\xampp\php\php.exe`.
   - Arguments: `C:\xampp\htdocs\ipmanage\cron_netwatch.php`.
3. **Switch Poller & Loop Detective (5 Menit)**:
   - Trigger: Daily, repeat every **5 minutes**.
   - Action: Start a program &rarr; `C:\xampp\php\php.exe`.
   - Arguments: `C:\xampp\htdocs\ipmanage\cron_switch_poll.php`.
4. **Subnet Scanner (15 Menit)**:
   - Trigger: Daily, repeat every **15 minutes**.
   - Action: Start a program &rarr; `C:\xampp\php\php.exe`.
   - Arguments: `C:\xampp\htdocs\ipmanage\cron_scanner.php`.

---

## 6. Optimasi Performa Ekstra (Opsional)

### Redis Caching Layer
NetScope Pro memiliki modul Redis bawaan untuk mempercepat query tabel berulang dan session:
1. Pastikan service Redis aktif (`sudo systemctl status redis-server`).
2. Aktifkan ekstensi `redis` di PHP.
3. Aplikasi akan mendeteksi Redis secara otomatis di `127.0.0.1:6379`.

### PHP OPcache
Untuk mempercepat eksekusi skrip PHP:
```ini
zend_extension=opcache
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=2
opcache.enable_cli=1
```

---

## 7. Verifikasi dan Login

Akses aplikasi melalui browser:
```text
http://localhost/ipmanage
```

**Kredensial Default:**
- **Username**: `admin`
- **Password**: `admin123`

*(Segera ganti password default di menu Settings setelah login pertama kali!)*
