# Backend Discovery Engine

Dokumen ini menjelaskan alur pembacaan `IP`, `MAC`, `hostname` agar akurat dan minim miss.

## A. Alur Scan Manual (`api/scan.php`)

1. Validasi autentikasi sesi.
2. Ambil subnet target.
3. Hitung range dari `subnet/mask`.
4. Parse ARP awal menjadi map `IP => MAC`.
5. Loop range host dan skip alamat non-usable:
   - skip network/broadcast untuk subnet `/30` ke bawah.
6. Jalankan deteksi host multi-sinyal.
7. Jika aktif:
   - resolve hostname (DNS, retry ringan);
   - ambil MAC + vendor;
   - enrich SNMP (jika tersedia);
   - hitung confidence score;
   - upsert ke `ip_addresses`.

## B. Alur Scan Otomatis (`api/cron.php`)

1. Cari subnet yang due berdasarkan `scan_interval` dan `last_scan`.
2. Scan host (flow discovery sama seperti scan manual).
3. Update `last_scan` per subnet.
4. Rekonsiliasi offline:
   - `state = offline` untuk host `active` yang `last_seen` melewati TTL.

## C. Strategi Deteksi Aktivitas Host

Function inti: `detect_host_signals()` di `includes/network.php`.

Sinyal yang dipakai:

- `ping` (dengan retry);
- `arp` (dari ARP map);
- `port` probe ke daftar port umum: `80, 443, 22, 445, 135, 139, 3389, 8000`.
- `nmap` fallback berbasis binary system untuk host "siluman".

Tambahan anti-miss:

- refresh ARP adaptif jika ada sinyal aktif tapi ARP belum muncul;
- final lightweight recheck untuk host borderline.
- optional Nmap host discovery (`-sn`) untuk negative-case yang tetap meragukan.

## D. Mode Discovery Lanjutan

Sistem mendukung dua mode tambahan via `includes/config.php`:

1. **DISCOVERY_AGGRESSIVE_MODE** (`true`):
   - Menambah daftar port probe menjadi: `80, 443, 22, 445, 135, 139, 3389, 53, 161, 8000, 8080, 8443, 554, 37777`.
   - Melibatkan pengecekan ke port khusus IoT, CCTV (554/37777), dan High-port Web (8000/8080/8443).

2. **ENABLE_NMAP_FALLBACK** (`true`):
   - Menggunakan `nmap -sn -n` sebagai langkah pamungkas jika Ping/ARP/Port gagal.
   - Memerlukan binary `nmap` terinstal di host scanner.
   - Efektif menembus berbagai proteksi firewall host.

## E. Hostname Resolution

- DNS reverse lookup memakai retry ringan.
- Hasil hostname dinormalisasi:
  - trim, lowercase;
  - tolak string whitespace/invalid;
  - batasi panjang (100 chars).
- Hostname dari SNMP hanya overwrite jika lolos normalisasi.

## E. SNMP Enrichment

`includes/snmp.php` memakai adaptive profile:

1. Attempt 1 (cepat): timeout lebih pendek.
2. Attempt 2 (fallback): timeout lebih longgar + retry.

OID yang diambil:

- `sysName.0` -> hostname kandidat.
- `sysDescr.0` -> deskripsi perangkat.

## F. Confidence Scoring

Function: `calculate_discovery_confidence()`.

Bobot saat ini:

- `snmp = 35`
- `arp = 30`
- `nmap = 25`
- `ping = 20`
- `port = 10`
- `dns = 5`

Output:

- `confidence_score` (0-100, dibatasi minimum 5 saat ada data)
- `data_sources` (csv, contoh: `snmp,arp,ping`)

## G. Prinsip Upsert Data

Semua hasil discovery masuk via:

- `INSERT ... ON DUPLICATE KEY UPDATE`
- Kunci unik: `(subnet_id, ip_addr)`

Aturan update:

- `hostname`, `mac`, `vendor`, `description` tidak overwrite dengan string kosong.
- `state` dipaksa `active` saat terdeteksi.
- `last_seen` selalu di-refresh.
