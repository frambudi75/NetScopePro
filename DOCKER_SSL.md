# 🔒 Panduan HTTPS untuk IPManager Pro (Docker)

Dokumentasi ini menjelaskan cara mengaktifkan **HTTPS/SSL** pada deployment Docker IPManager Pro.

## Arsitektur

```
User (Browser)
     │
     ▼ HTTPS :443
┌──────────┐
│  Nginx   │  ← SSL Termination + Reverse Proxy
│  (Alpine)│
└────┬─────┘
     │ HTTP :80 (internal network)
     ▼
┌──────────┐
│  Apache  │  ← IPManager Pro (PHP)
│  (PHP)   │
└──────────┘
```

Nginx meng-handle HTTPS di depan, lalu meneruskan request ke Apache (container `app`) melalui internal Docker network. Semua traffic eksternal terenkripsi.

---

## Langkah-langkah

### 1. Siapkan Sertifikat SSL

#### Opsi A: Self-Signed (Testing / Intranet)
Jalankan perintah ini di server Docker Anda:

```bash
mkdir -p docker/nginx/ssl
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout docker/nginx/ssl/key.pem \
  -out docker/nginx/ssl/cert.pem \
  -subj "/C=ID/ST=Jakarta/L=Jakarta/O=IPManager/CN=ipmanager.local"
```

> **Catatan:** Self-signed certificate akan menampilkan warning "Not Secure" di browser. Untuk intranet/internal, ini cukup aman.

#### Opsi B: Let's Encrypt (Production / Public Domain)
Jika Anda punya domain publik (misal: `ipmanager.company.com`):

```bash
# Install certbot
sudo apt install certbot

# Generate certificate
sudo certbot certonly --standalone -d ipmanager.company.com

# Copy ke folder project
mkdir -p docker/nginx/ssl
sudo cp /etc/letsencrypt/live/ipmanager.company.com/fullchain.pem docker/nginx/ssl/cert.pem
sudo cp /etc/letsencrypt/live/ipmanager.company.com/privkey.pem docker/nginx/ssl/key.pem
```

#### Opsi C: Custom Certificate (dari IT / CA Kantor)
Simpan file sertifikat Anda ke:
```
docker/nginx/ssl/cert.pem    ← Public certificate (+ chain)
docker/nginx/ssl/key.pem     ← Private key
```

### 2. Jalankan Docker Compose (SSL)

```bash
sudo docker compose -f docker-compose.ssl.yml up -d --build
```

### 3. Akses Aplikasi

```
https://localhost
https://ipmanager.local
https://192.168.x.x
```

Port `80` (HTTP) otomatis redirect ke `443` (HTTPS).

---

## Kembali ke HTTP (Tanpa SSL)

Jika ingin kembali ke mode HTTP biasa:

```bash
sudo docker compose -f docker-compose.ssl.yml down
sudo docker compose up -d --build
```

---

## Troubleshooting

### Browser menampilkan "NET::ERR_CERT_AUTHORITY_INVALID"
**Normal** untuk self-signed certificate. Klik "Advanced" → "Proceed" untuk melanjutkan.

Untuk menghilangkan warning:
- Import `cert.pem` ke Trusted Root CA di browser/OS Anda
- Atau gunakan Let's Encrypt untuk sertifikat yang trusted

### Error: "no such file or directory: ssl/cert.pem"
Anda belum membuat sertifikat. Jalankan perintah di **Langkah 1** terlebih dahulu.

### SSE / Live Streaming tidak bekerja melalui HTTPS
Sudah diatasi — Nginx config memiliki rule khusus untuk endpoint `api/*stream` yang menonaktifkan buffering.

### Scan timeout melalui HTTPS
Sudah diatasi — Nginx timeout disetel ke **300 detik** (5 menit), sama dengan `set_time_limit()` di PHP.
