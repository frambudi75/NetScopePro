# Panduan Instalasi Standalone (XAMPP / Apache / Linux)

Dokumentasi ini menjelaskan cara menginstal **IPManager Pro** secara langsung di server web (tanpa Docker), seperti di lingkungan XAMPP (Windows) atau server Linux (Ubuntu/Debian).

## 1. Persyaratan Sistem

Pastikan server Anda memenuhi persyaratan berikut:
- **PHP**: v8.1 atau v8.2 (Sangat disarankan v8.2)
- **Database**: MariaDB 10.6+ atau MySQL 8.0+
- **Web Server**: Apache 2.4 (dengan modul `mod_rewrite` aktif)
- **Ekstensi PHP Wajib**:
    - `php-snmp` (Untuk monitoring hardware)
    - `php-curl` (Untuk update check & API)
    - `php-pdo_mysql` (Koneksi database)
    - `php-mbstring` & `php-gd` (Optimasi UI)
    - `php-redis` (Opsional, sangat disarankan untuk performa tinggi)
- **Tools Sistem**: `nmap`, `traceroute`, `net-snmp`

---

## 2. Persiapan Lingkungan

### Windows (XAMPP)
1.  Buka **XAMPP Control Panel**.
2.  Klik tombol **Config** pada baris Apache, pilih `PHP (php.ini)`.
3.  Cari baris berikut dan hapus tanda titik koma (`;`) di depannya:
    ```ini
    extension=snmp
    extension=curl
    extension=pdo_mysql
    ```
4.  Simpan file dan **Restart Apache**.

### Linux (Ubuntu/Debian)
Jalankan perintah berikut:
```bash
sudo apt update
sudo apt install apache2 mariadb-server php php-mysql php-snmp php-curl nmap traceroute redis-server php-redis
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 3. Instalasi Aplikasi

1.  **Clone / Copy Kode**:
    Letakkan folder proyek di dalam direktori web root:
    - **XAMPP**: `C:\xampp\htdocs\ipmanage`
    - **Linux**: `/var/www/html/ipmanage`

2.  **Konfigurasi Database**:
    - Buka **phpMyAdmin** atau terminal MySQL.
    - Buat database baru bernama `ipmanage`.
    - Impor file `sql/database.sql` ke dalam database tersebut.

3.  **Pengaturan Kredensial**:
    Buka file `includes/config.php` dan sesuaikan pengaturan database:
    ```php
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'ipmanage');
    define('DB_USER', 'root'); // Ganti dengan user DB Anda
    define('DB_PASS', '');     // Ganti dengan password DB Anda
    ```

4.  **Izin File (Linux Only)**:
    Berikan izin tulis kepada web server:
    ```bash
    sudo chown -R www-data:www-data /var/www/html/ipmanage
    sudo chmod -R 755 /var/www/html/ipmanage
    ```

---

## 4. Konfigurasi Apache (Penting untuk Clean URL)

Agar fitur navigasi seperti `/login`, `/dashboard`, dan `/subnets` berfungsi, pastikan Apache mengizinkan `.htaccess`.

### XAMPP
Umumnya sudah aktif secara default. Pastikan folder proyek memiliki file `.htaccess`.

### Linux (VirtualHost)
Edit konfigurasi situs Anda (misal: `/etc/apache2/sites-available/000-default.conf`) dan pastikan ada blok berikut:
```apache
<Directory /var/www/html/ipmanage>
    AllowOverride All
    Require all granted
</Directory>
```
Lalu restart: `sudo systemctl restart apache2`

---

## 5. Otomasi Background Scanner (Cron Job)

IPManager Pro melakukan pemindaian jaringan di latar belakang. Anda harus menjadwalkannya secara manual.

### Linux (Crontab)
Jalankan `crontab -e` dan tambahkan baris berikut:
```bash
# Jalankan scanner setiap 15 menit
*/15 * * * * php /var/www/html/ipmanage/cron_scanner.php > /dev/null 2>&1

# Jalankan poller switch setiap 5 menit
*/5 * * * * php /var/www/html/ipmanage/cron_switch_poll.php > /dev/null 2>&1

# Jalankan Netwatch monitor setiap 1 menit (Rekomendasi)
* * * * * php /var/www/html/ipmanage/cron_netwatch.php > /dev/null 2>&1
```

### Windows (Task Scheduler)
1.  Buka **Task Scheduler** > **Create Basic Task**.
2.  Nama: `IPManage Scanner`.
3.  Trigger: **Daily** (lalu set Repeat task every **15 minutes**).
4.  Action: **Start a Program**.
5.  Program/script: `C:\xampp\php\php.exe`.
6.  Add arguments: `C:\xampp\htdocs\ipmanage\cron_scanner.php`.

**Penting:** Lakukan hal yang sama untuk `cron_netwatch.php` dengan interval setiap **1 menit** agar monitoring host berjalan real-time.

---

## 6. Optimasi Performa (Opsional)

### Redis (Caching)
IPManager Pro mendukung Redis untuk mempercepat sesi dan hasil polling.
1.  Pastikan Redis Server berjalan.
2.  Pastikan `extension=redis` aktif di `php.ini`.
3.  Aplikasi akan otomatis mendeteksi dan menggunakan Redis jika tersedia.

### PHP Opcache
Untuk menghilangkan lag UI, aktifkan Opcache di `php.ini`:
```ini
zend_extension=opcache
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=2
```

---

## 7. Selesai!
Akses aplikasi melalui: `http://localhost/ipmanage`

**Login Default:**
- **Username**: `admin`
- **Password**: `admin123`
