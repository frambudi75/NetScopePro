# Database dan Migrasi

## Tabel Inti yang Dipakai Discovery

Tabel: `ip_addresses`

Kolom penting:

- `subnet_id` (FK ke `subnets`)
- `ip_addr`
- `hostname`
- `mac_addr`
- `vendor`
- `description`
- `state` (`active`, `reserved`, `offline`, `dhcp`)
- `last_seen`
- `confidence_score`
- `data_sources`

## Index Penting

Wajib ada:

- `UNIQUE KEY uniq_subnet_ip (subnet_id, ip_addr)`

Tujuan:

- mencegah duplikasi IP dalam subnet yang sama;
- memastikan `ON DUPLICATE KEY UPDATE` bekerja benar.

## SQL Migrasi untuk Instance Existing

Jalankan satu per satu sesuai kondisi database.

### 1) Tambah unique key (jika belum ada)

```sql
ALTER TABLE ip_addresses
ADD UNIQUE KEY uniq_subnet_ip (subnet_id, ip_addr);
```

Jika gagal karena duplicate entry, deduplikasi dulu.

### 2) Deduplikasi (simpan record terbaru)

```sql
DELETE t1
FROM ip_addresses t1
INNER JOIN ip_addresses t2
  ON t1.subnet_id = t2.subnet_id
 AND t1.ip_addr = t2.ip_addr
 AND (
      COALESCE(t1.last_seen, '1970-01-01') < COALESCE(t2.last_seen, '1970-01-01')
      OR (
           COALESCE(t1.last_seen, '1970-01-01') = COALESCE(t2.last_seen, '1970-01-01')
           AND t1.id < t2.id
         )
 );
```

Lalu ulangi pembuatan unique key.

### 3) Tambah kolom confidence (jika belum ada)

```sql
ALTER TABLE ip_addresses
ADD COLUMN confidence_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN data_sources VARCHAR(100) NULL;
```

### 4) Tambah kolom IP Conflict Detection (v2.26.0+)

```sql
ALTER TABLE ip_addresses
ADD COLUMN conflict_detected TINYINT(1) DEFAULT 0,
ADD COLUMN conflict_details TEXT NULL;
```

### 5) Tambah kolom STP & Loop Detection pada Switches (v2.27.0+)

```sql
ALTER TABLE switches
ADD COLUMN stp_enabled TINYINT(1) DEFAULT 0,
ADD COLUMN stp_protocol VARCHAR(32) DEFAULT NULL,
ADD COLUMN loop_detected TINYINT(1) DEFAULT 0,
ADD COLUMN loop_details TEXT DEFAULT NULL,
ADD COLUMN stp_topology_changes INT UNSIGNED DEFAULT 0;

ALTER TABLE switch_port_map
ADD COLUMN stp_state VARCHAR(20) DEFAULT 'unknown';
```

Catatan: Sistem menyertakan `includes/db_upgrade.php` yang menjalankan migrasi di atas secara otomatis dan idempoten setiap kali aplikasi dimulai atau polling switch dijalankan.


## Query Verifikasi

### Cek index unik

```sql
SHOW INDEX FROM ip_addresses WHERE Key_name = 'uniq_subnet_ip';
```

### Cek duplicate residu

```sql
SELECT subnet_id, ip_addr, COUNT(*) AS cnt
FROM ip_addresses
GROUP BY subnet_id, ip_addr
HAVING cnt > 1;
```
