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

## 7) IP Conflict Terdeteksi

Gejala:
- Dashboard menampilkan banner merah "Active IP Conflicts Detected".
- Di tabel IP / Devices terdapat badge "Conflict".

Tindakan:
1. Buka `tools.php?action=conflict&ip=<TARGET_IP>` untuk menjalankan **IP Conflict Diagnostic Prober**.
2. Periksa Phase 2 (Switch Port Mapping):
   - Jika MAC yang sama hanya terdeteksi di upstream CORE dan edge Access Switch, sistem otomatis mengenali ini sebagai normal trunk forwarding (bukan konflik port).
   - Jika ada 2 MAC address berbeda yang membalas IP yang sama, catat port switch masing-masing perangkat.
3. Periksa Phase 3 (ICMP TTL Variance):
   - Jika nilai TTL melompat (misal 64 dan 128 secara acak), terdapat 2 perangkat fisik berbeda yang aktif bersamaan.
4. Lakukan isolasi salah satu port switch atau ubah IP salah satu host agar tidak bertabrakan.

## 8) L2 Switching Loop atau Port "BLOCKING" Terdeteksi

Gejala:
- Dashboard menampilkan banner merah "L2 Switching Loop / Blocked Port Detected".
- Switch card menampilkan label `LOOP ALERT` atau port switch bertanda `🚫 BLOCKING`.

Tindakan:
1. Buka `tools.php?action=loop&switch_id=<ID>` untuk menjalankan **L2 Loop Diagnostic Prober**.
2. Evaluasi status STP:
   - Jika ada port `BLOCKING`, identifikasi nomor port dan perangkat downstream yang terhubung.
   - Jika itu link redundan yang disengaja (misal dual-homed switch tanpa LACP), status `BLOCKING` adalah normal karena STP berhasil mencegah broadcast storm.
   - Jika terjadi akibat kabel loop lokal (misal dumb switch atau kabel tercolok balik ke switch yang sama), segera cabut kabel yang bermasalah.
3. Periksa counter TCN (Topology Changes): jika counter terus naik setiap beberapa detik, periksa kabel dan port transceiver (SFP) terhadap link flapping fisik.

