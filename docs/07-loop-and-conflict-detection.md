# Deteksi L2 Switching Loop & IP Conflict

Dokumen ini memberikan panduan teknis mendalam mengenai arsitektur, algoritma, alat diagnostik, dan langkah mitigasi untuk **L2 Switching Loop Detection** dan **Enterprise IP Conflict Detection** di NetScope Pro (v2.27.0+).

---

## 1. L2 Switching Loop Detection Engine

### A. Bahaya L2 Switching Loop
Looping pada Layer 2 (Data Link) terjadi ketika terdapat jalur redundan tanpa kontrol protokol Spanning Tree atau akibat salah konfigurasi kabel (misal dumb switch/hub dicolok kedua ujungnya ke switch managed). Dampak L2 loop:
- **Broadcast Storm**: Frame broadcast (seperti ARP request) digandakan terus-menerus hingga menghabiskan seluruh bandwidth jaringan.
- **CAM/FDB Table Thrashing**: Tabel MAC address pada switch mengalami update ribuan kali per detik, menyebabkan paket di-drop atau switch restart akibat CPU/Memory exhaustion.
- **Konektivitas Drop Massal**: Host-host di satu VLAN atau seluruh switch kehilangan akses jaringan.

### B. Metode Deteksi di NetScope Pro

NetScope Pro mengombinasikan dua metode deteksi otomatis melalui proses background worker dan poller SNMP (`cron_switch_poll.php`):

```
┌────────────────────────────────────────────────────────┐
│             NetScope Pro L2 Loop Detection             │
└───────────────────────────┬────────────────────────────┘
                            │
            ┌───────────────┴───────────────┐
            ▼                               ▼
  [Metode 1: Polling STP]        [Metode 2: MAC Thrashing]
  - dot1dStpPortState            - Same-switch FDB flapping
  - Port BLOCKING (State 2)      - Multi-port MAC oscillation
  - TCN Topology Changes         - Unmanaged switch loop detection
```

#### 1. Polling Spanning Tree Protocol (STP / RSTP / MSTP)
Sistem memanfaatkan MIB standar IEEE 802.1D Spanning Tree MIB (`dot1dStp`):
- **Port Operational State** (`dot1dStpPortState`, OID `.1.3.6.1.2.1.17.2.15.1.3`):
  - `1`: `disabled`
  - `2`: `blocking` ➔ **Loop Terisolasi Secara Preventif**. Port ini secara aktif diblokir oleh switch untuk mencegah loop broadcast. NetScope Pro mendeteksi port ini dan menampilkannya dengan badge merah `🚫 BLOCKING`.
  - `3`: `listening`
  - `4`: `learning`
  - `5`: `forwarding` ➔ Port beroperasi normal mengalirkan traffic (`STP: FWD`).
  - `6`: `broken`
- **Topology Change Detection (TCN)**:
  - `dot1dStpTopChanges` (OID `.1.3.6.1.2.1.17.2.4.0`): Menghitung akumulasi perubahan topologi STP.
  - `dot1dStpTimeSinceTopologyChange` (OID `.1.3.6.1.2.1.17.2.3.0`): Waktu sejak TCN terakhir. Jika waktu sejak TCN terakhir sangat kecil (< 60 detik) disertai kenaikan counter secara konstan, switch dicurigai mengalami link flapping atau loop periodik.
- **STP Protocol Specification** (`dot1dStpProtocolSpecification`, OID `.1.3.6.1.2.1.17.2.1.0`):
  - Mengidentifikasi protokol yang aktif: `RSTP` (Rapid STP 802.1w), `STP` (802.1D klasik), atau `MSTP`.

#### 2. Deteksi FDB MAC Thrashing / Flapping
Jika STP tidak aktif atau loop terjadi di switch unmanaged (dumb hub) yang terhubung ke port access:
- Worker memantau Forwarding Database (FDB / CAM Table) dari switch (`dot1dTpFdbPort`).
- Jika MAC address berpindah-pindah antar port pada **switch fisik yang sama** dalam waktu singkat, NetScope Pro memicu alarm `MAC Thrashing / Loop Detected` dengan rincian port yang terlibat.

---

## 2. Enterprise IP Conflict Detection Engine

IP Conflict terjadi ketika dua perangkat berbeda menggunakan IP address yang sama di dalam subnet/VLAN yang sama.

### A. Fitur Anti False-Positive
Pada versi sebelumnya, deteksi konflik sering memunculkan *false positive* akibat:
1. **Nmap Multi-Fingerprint**: Nmap mendeteksi satu mesin Linux dan memberikan beberapa kemungkinan kernel (misal `Linux 4.15`, `OpenWrt 21.02`, `MikroTik RouterOS 7`). Ini **bukan** konflik multi-OS.
2. **Trunk / Uplink Switch Port Propagation**: Di jaringan bertingkat (Access Switch ➔ Distribution ➔ Core Switch), sebuah MAC address wajar tercatat di port uplink pada switch CORE dan port edge pada switch Access. Ini **bukan** port conflict.

### B. Arsitektur Deteksi di NetScope Pro (v2.27.0)
- **Smart OS Family Classifier (`get_os_family()`)**:
  Mengelompokkan fingerprint OS ke dalam keluarga besar:
  - `Windows`
  - `Linux` (termasuk OpenWrt, Android, Ubuntu, Debian, CentOS, RouterOS)
  - `BSD` (FreeBSD, OpenBSD, macOS/Darwin, pfSense)
  - `Cisco` (IOS, IOS-XE, NX-OS)
  - `MikroTik`
  Jika fingerprint yang dilaporkan masih dalam keluarga yang sama (misal varian Linux), sistem tidak akan memicu alarm konflik OS palsu.
- **Trunk-Aware L2 Switch Port Analysis**:
  Sistem hanya memvalidasi konflik port jika:
  - Terdapat lebih dari 1 MAC address berbeda yang memegang IP yang sama; atau
  - MAC yang sama muncul di lebih dari 1 port pada **switch fisik yang sama**.

---

## 3. Alat Diagnostik Interaktif (`tools.php`)

NetScope Pro menyediakan dua prober interaktif langsung dari menu **Network Tools**:

### A. L2 Loop & Topology Diagnostic Prober
Akses via: `tools.php?action=loop&switch_id=<ID>`

Prober ini menjalankan 6 fase pengujian real-time:
1. **Phase 1 - Switch Identity & Bridge MIB**: Validasi IP switch, sysName, vendor, dan ketersediaan Bridge MIB.
2. **Phase 2 - STP Protocol & Root Bridge**: Membaca protokol STP (`RSTP`/`STP`), Root Bridge MAC, dan path cost.
3. **Phase 3 - Port Operational States**: Melakukan iterasi seluruh port untuk mencari port dalam kondisi `BLOCKING` atau `BROKEN`.
4. **Phase 4 - Topology Stability & TCN**: Menghitung counter TCN dan waktu stabil sejak perubahan terakhir.
5. **Phase 5 - MAC Address Distribution**: Memeriksa FDB table terhadap anomali distribusi MAC multi-port.
6. **Phase 6 - Diagnostic Verdict & Actionable Remediation**: Kesimpulan otomatis tingkat keparahan (CRITICAL / WARNING / HEALTHY) beserta panduan penanganan.

### B. IP Conflict Diagnostic Prober
Akses via: `tools.php?action=conflict&ip=<TARGET_IP>`

Prober ini menjalankan 4 fase pengujian komprehensif:
1. **Phase 1 - Database & Inventory Record**: Status IP, MAC, hostname, OS fingerprint, dan flag konflik tersimpan.
2. **Phase 2 - Switch Port & L2 Hardware Mapping**: Memetakan port switch tempat MAC terhubung secara trunk-aware.
3. **Phase 3 - Multi-Probe ICMP Ping & TTL Variance**: Mengirim probe live ICMP berulang. Jika terdapat variasi nilai TTL (Time To Live) yang drastis, terindikasi dua host berbeda sedang menjawab paket.
4. **Phase 4 - Diagnostic Verdict**: Rekomendasi apakah konflik valid dan panduan isolasi port.

---

## 4. Tampilan Visual & Monitoring (UI)

- **Dashboard NOC Banner (`index.php`)**:
  - Banner peringatan merah/oranye muncul di bagian paling atas jika terdapat L2 loop atau port blocking yang terdeteksi di jaringan.
- **Switch Inventory (`switches.php`)**:
  - Badge protokol STP (`RSTP`, `STP`) pada setiap kartu switch.
  - Tag peringatan `LOOP ALERT` warna merah jika switch mengalami loop atau MAC flapping.
- **Switch Details (`switch-details.php`)**:
  - Panel sidebar baru: **L2 Topology & STP Status** menampilkan status STP, Root Bridge, jumlah port blocking, dan counter TCN.
  - Pada tabel port: Badge status per-port seperti `STP: FWD` (hijau) atau `🚫 BLOCKING` (merah).

---

## 5. Panduan Remediasi (Runbook)

### Jika Ditemukan Port dalam Status `BLOCKING`:
1. Buka **Switch Details** dan identifikasi port yang berstatus `🚫 BLOCKING`.
2. Cek kabel yang terhubung ke port tersebut:
   - Apakah terhubung ke switch lain yang juga memiliki link redundant tanpa LACP/LAG?
   - Apakah terhubung ke dumb switch yang mengalami loop kabel lokal?
3. Jika merupakan link redundan yang disengaja (failover), status `BLOCKING` adalah **normal** (STP bekerja mengamankan jaringan).
4. Jika bukan link yang diinginkan, cabut kabel atau shutdown port yang bersangkutan.

### Jika Terjadi MAC Thrashing / Flapping:
1. Identifikasi kedua port yang bergantian mencatat MAC address yang sama di `tools.php?action=loop`.
2. Lakukan isolasi sementara pada salah satu port (`shutdown` via CLI switch).
3. Telusuri titik temu kabel di area kerja user untuk menemukan sambungan loop fisik (biasanya user mencolok kedua port wall-outlet ke unmanaged switch atau VoIP phone bridge).
