# Troubleshooting Discovery

## 1) Host Aktif Tidak Terdeteksi (Miss)

Checklist:

- scanner dijalankan dari jaringan yang dapat reach subnet target;
- host tidak sleep/deep power saving;
- firewall host target tidak drop semua probe;
- route antar VLAN/subnet benar;
- interval cron tidak terlalu jarang.

Tindakan:

- lakukan scan manual dari subnet detail;
- cek apakah `last_seen` ter-update;
- cek source signal (`data_sources`) di Devices.

## 2) MAC Address Kosong

Kemungkinan:

- ARP belum terisi (terutama inter-VLAN routed environment);
- host merespons service tapi tidak mengisi ARP cache lokal secara cepat.

Tindakan:

- pastikan scanner berada sedekat mungkin secara L2/L3;
- scan ulang (backend sudah ada ARP refresh adaptif);
- cek output `arp -a` di host scanner.

## 3) Hostname Kosong atau Tidak Konsisten

Kemungkinan:

- reverse DNS (PTR) tidak tersedia;
- SNMP tidak aktif / community tidak cocok;
- nilai SNMP sysName tidak valid (akan disaring backend).

Tindakan:

- validasi PTR zone;
- validasi SNMP service + community subnet.

## 4) Confidence Rendah Terlalu Banyak

Kemungkinan:

- banyak host hanya terdeteksi lewat satu sinyal lemah;
- akses SNMP belum benar;
- firewall membatasi ICMP/TCP probe.

Tindakan:

- lihat dashboard "Needs Attention";
- prioritaskan host dengan confidence terendah;
- optimalkan akses SNMP dan DNS internal.

## 5) Error Saat Tambah Unique Key

Error umum:

- `Duplicate entry ... for key 'uniq_subnet_ip'`

Solusi:

1. jalankan query deduplikasi;
2. ulangi `ALTER TABLE ... ADD UNIQUE KEY`.

Lihat SQL lengkap di `docs/04-database-and-migrations.md`.

## 6) Performa Scan Menurun

Tindakan:

- perbesar `scan_interval` subnet besar;
- pastikan server tidak overload CPU/network;
- hindari menjalankan banyak job scanner paralel pada host yang sama.
