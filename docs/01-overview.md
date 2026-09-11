# Overview Sistem

## Tujuan Aplikasi

IPManage adalah aplikasi IP Address Management (IPAM) untuk:

- manajemen subnet, VLAN, dan alokasi IP;
- inventory perangkat berdasarkan hasil scan jaringan;
- monitoring kualitas discovery (confidence score, sumber data, last seen).

## Komponen Utama

- `index.php`  
  Dashboard: statistik global, discovery health, dan daftar perangkat yang perlu perhatian.
- `subnets.php`, `subnet-details.php`, `add-subnet.php`  
  Manajemen subnet dan operasi scan per subnet.
- `devices.php`  
  Inventori perangkat lintas subnet.
- `switches.php`, `switch-details.php`  
  Inventori switch L2/L3, port mapping, status Spanning Tree (STP/RSTP), SFP/DOM monitoring, dan loop alerts.
- `tools.php`  
  Network diagnostic toolkit: Live Ping, Traceroute, Port Scanner, Subnet Calculator, IP Conflict Diagnostic Prober, dan L2 Loop Diagnostic Prober.
- `cron_switch_poll.php`  
  Background daemon untuk polling SNMP switch ports, FDB table, optical DOM, dan status STP / MAC flapping.
- `api/scan.php`  
  Endpoint scan manual/chunked untuk UI.
- `api/cron.php`  
  Scan otomatis terjadwal + rekonsiliasi status offline.
- `includes/network.php`  
  Helper jaringan (ping, ARP parser, MAC normalization, hostname resolver, multi-probe detector).
- `includes/snmp.php`  
  Helper SNMP dengan adaptive retry.
- `sql/database.sql`  
  Skema database.

## Fitur Discovery Backend Saat Ini

- Normalisasi IPv4 dan MAC address.
- Parsing ARP lintas format output OS.
- Resolver hostname dengan retry ringan.
- SNMP lookup dua tahap (fast try + adaptive retry).
- Multi-probe activity detection (ping + ARP + port scan).
- Confidence scoring berbasis sumber data.
- Auto-mark offline berbasis TTL.
- **Enterprise IP Conflict Detection**: Deteksi tabrakan IP, Smart OS Family Classifier, dan Trunk-Aware switch mapping.
- **L2 Switching Loop & STP Discovery**: Polling status STP (`forwarding`, `blocking`), pemantauan TCN, dan deteksi MAC flapping/thrashing pada switch yang sama.

## Terminologi

- `last_seen`  
  Waktu terakhir host terdeteksi aktif.
- `confidence_score`  
  Skor keyakinan discovery (0-100).
- `data_sources`  
  Sumber data yang berkontribusi pada hasil (`snmp,arp,ping,port,dns`).
- `scan_interval`  
  Interval scan otomatis per subnet (menit).
- `conflict_detected`  
  Flag (0/1) penanda IP mengalami konflik kepemilikan MAC atau anomali respon OS.
- `stp_state`  
  Status operasional Spanning Tree pada port switch (`forwarding`, `blocking`, `learning`, `disabled`).
- `loop_detected`  
  Flag (0/1) penanda switch mendeteksi adanya L2 loop atau MAC thrashing.
