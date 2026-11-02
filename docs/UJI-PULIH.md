# Backup & uji pulih

Backup baru terbukti berguna setelah pernah berhasil dipulihkan. Lakukan **uji pulih sebulan sekali** di setiap VM.

## Di mana backup tiap aplikasi

| Aplikasi | Dibuat oleh | Lokasi | Jadwal |
|---|---|---|---|
| Pusat | `deploy/backup.sh` (cron `/etc/cron.d/pusat-backup`) | `/var/backups/pusat/pusat_*.sql.gz` | 01:15 |
| Absensi | `php artisan absensi:backup` (penjadwal Laravel) | `storage/app/backups/absensi_*.sql` | 02:00 |
| BK | `deploy/backup.sh` (cron `/etc/cron.d/bk-backup`, dipasang otomatis oleh install/update) | `/var/backups/bk-pusat/` (`db_*.sql.gz` + `berkas_*.tar.gz`) | 01:30 |
| TU | `deploy/backup.sh` (cron `/etc/cron.d/tu-backup`, dipasang otomatis oleh install/update) | `/var/backups/tu-pusat/` (`db_*.sql.gz` + `berkas_*.tar.gz`) | 01:30 |
| Website | `deploy/backup.sh` (cron `/etc/cron.d/smkibu-backup`) | `/var/backups/smkibu/` (`db_*` + foto) | 01:30 |

Periksa cron backup ada: `ls /etc/cron.d/`. Bila belum ada untuk Pusat/Website, lihat panduan VM masing-masing.

## Uji pulih (aman, tidak mengubah data)

```bash
cd /var/www/<folder-aplikasi>
sudo bash deploy/uji-pulih.sh
```

Skrip memulihkan backup **terbaru** ke database sementara `<nama_db>_ujipulih`, menampilkan jumlah baris tiap tabel
(backup vs sekarang), lalu menghapus database sementara. Hasil yang diharapkan: tulisan hijau **BERHASIL**.

- **PERINGATAN backup berumur > 2 hari** → cron backup tidak berjalan; periksa `/etc/cron.d/` dan `systemctl status cron`.
- **GAGAL: tidak bisa diimpor** → berkas rusak/terpotong (disk penuh saat backup?). Periksa `df -h`, jalankan backup manual, uji lagi.
- **GAGAL: tidak berisi data** → backup kosong; periksa `DB_*` di `.env` yang dibaca skrip backup.

Uji berkas tertentu: `sudo bash deploy/uji-pulih.sh /var/backups/pusat/pusat_2026-10-20_0115.sql.gz`

## Pemulihan sungguhan (bila data rusak/hilang)

**Absensi** punya skrip lengkap (backup pengaman → mode pemeliharaan → impor → migrasi):
```bash
cd /var/www/absensi && sudo bash deploy/restore.sh
```

**Pusat, BK, Website** — langkah manual:
```bash
cd /var/www/<folder-aplikasi>
sudo bash deploy/backup.sh                          # 1. backup pengaman kondisi sekarang
sudo -u www-data php artisan down                   # 2. mode pemeliharaan
DB=$(grep ^DB_DATABASE= .env | cut -d= -f2)
sudo mariadb -e "DROP DATABASE \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c /var/backups/<lokasi>/<berkas>.sql.gz | sudo mariadb "$DB"   # 3. impor
sudo -u www-data php artisan migrate --force        # 4. susulkan migrasi bila backup lebih lama
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan up                     # 5. aktif lagi
```
BK juga punya berkas scan surat: `sudo tar -xzf /var/backups/bk-pusat/berkas_<tanggal>.tar.gz -C /var/www/bk-pusat/storage/app`.

Setelah Pusat dipulihkan, buka **Webhook → Kirim ulang semua** dan jalankan sinkron di Absensi/BK agar salinan data induk selaras.

## Salinan di luar server (sangat disarankan)

Semua backup di atas berada di server fisik yang sama dengan aplikasinya. Bila disk/server rusak, backup ikut hilang.
Salin rutin ke VPS cadangan, misalnya dari tiap VM:
```bash
# /etc/cron.d/backup-luar  (sesudah backup lokal selesai)
0 3 * * * root rsync -az --delete /var/backups/ backup@<ip-vps>:/cadangan/$(hostname)/
```
Di VM Absensi, backup ada di folder aplikasi: ganti sumbernya menjadi `/var/www/absensi/storage/app/backups/`.
(butuh kunci SSH tanpa password untuk user `backup` di VPS; uji pulih juga sesekali dari salinan di VPS).
