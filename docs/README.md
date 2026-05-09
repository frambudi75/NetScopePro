# Dokumentasi IPManage

Dokumentasi ini menjadi referensi utama untuk konfigurasi, arsitektur, operasi scanning, dan troubleshooting aplikasi.

## Daftar Dokumen

- `docs/01-overview.md`  
  Gambaran sistem, fitur utama, dan komponen aplikasi.
- `docs/02-setup-and-config.md`  
  Setup XAMPP/Docker, konfigurasi environment, dan parameter penting.
- `docs/03-backend-discovery-engine.md`  
  Detail alur backend untuk pembacaan IP, MAC, hostname, confidence, dan TTL offline.
- `docs/04-database-and-migrations.md`  
  Struktur tabel inti, index penting, dan SQL migrasi untuk instance existing.
- `docs/05-operations-and-cron.md`  
  Panduan menjalankan scan manual/otomatis, schedule cron/task scheduler, serta tuning.
- `docs/06-troubleshooting.md`  
  Checklist diagnosis jika host miss, MAC kosong, hostname kosong, atau performa scan menurun.

## Rekomendasi Urutan Baca

1. `docs/02-setup-and-config.md`
2. `docs/04-database-and-migrations.md`
3. `docs/03-backend-discovery-engine.md`
4. `docs/05-operations-and-cron.md`
5. `docs/06-troubleshooting.md`

## Catatan Cepat

- Backend sekarang memakai pendekatan multi-sinyal: `ping`, `arp`, `tcp port probe`, `dns`, `snmp`.
- Status `offline` dikelola otomatis oleh TTL (`OFFLINE_TTL_MINUTES`).
- Kualitas data discovery disimpan ke `confidence_score` dan `data_sources`.
