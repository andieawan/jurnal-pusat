# Pasang Jurnal Guru di VM baru

Satu aplikasi = satu VM, sama seperti BK, TU, dan PKL. Guru masuk lewat **Pusat Sekolah**; admin lokal hanya untuk
keadaan darurat saat Pusat mati.

| | Nilai |
|---|---|
| VM / IP | jurnal-pusat / `192.168.10.22` (lihat dokumen Susunan IP) |
| Folder | `/var/www/jurnal` |
| Database / user | `jurnal_guru` / `jurnal` (password acak, dibuat skrip) |
| Domain | `jurnal.smkibupakusari.sch.id` |

## 1. VM baru

Ubuntu Server 24.04 / 26.04 (atau Debian 13). Clone **tanpa `sudo`**: folder jadi milik user Anda, sehingga
`git pull` saat pembaruan tidak meminta token lagi.

```bash
sudo mkdir -p /var/www/jurnal && sudo chown $USER:$USER /var/www/jurnal
git config --global credential.helper store      # repo privat: Username = akun GitHub, Password = token
git clone https://github.com/andieawan/jurnal-pusat.git /var/www/jurnal
cd /var/www/jurnal
sudo DOMAIN=jurnal.smkibupakusari.sch.id APP_URL=https://jurnal.smkibupakusari.sch.id PROXY_IP=192.168.10.10 bash deploy/install.sh
```

Skrip ini memasang paket (Nginx, PHP, MariaDB, cron), membuat database dengan password acak, menulis `.env`,
menyiapkan Nginx, penjadwal (backup harian 02:00 dan antrean ke Pusat), lalu menanyakan password untuk admin lokal
`admin.sekolah`. Skrip aman diulang: `.env` dan database yang sudah ada tidak diubah. `vendor/` sudah ikut di repo,
jadi tidak perlu `composer install`.

Lalu di **NPMplus** buat Proxy Host `jurnal.smkibupakusari.sch.id` → `192.168.10.22`, port 80, SSL Let's Encrypt,
**Force SSL**; tambahkan juga DNS-nya bila belum ada.

## 2. Daftarkan di Pusat (VM Pusat)

Perbarui Pusat dulu, lalu daftarkan:

```bash
cd /var/www/pusat-sekolah && sudo bash deploy/update.sh
sudo -u www-data php artisan pusat:aplikasi-baru jurnal --base-url=https://jurnal.smkibupakusari.sch.id
```

Templat "jurnal" memberi izin yang sama dengan Absensi (data induk, mapel, pengampu, akun, login cadangan).
Salin `client_id`, `client_secret`, dan `webhook_secret` (hanya tampil sekali) ke `.env` Jurnal:

```ini
PUSAT_ENABLED=true
PUSAT_URL=https://pusat.smkibupakusari.sch.id
PUSAT_CLIENT_ID=...
PUSAT_CLIENT_SECRET=...
PUSAT_WEBHOOK_SECRET=...
```

## 3. Aktifkan

```bash
cd /var/www/jurnal
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan absensi:periksa      # semua baris harus [OK]
```

Masuk sebagai `admin.sekolah` → **Admin → Integrasi Pusat** → *Uji koneksi* → *Sinkron sekarang*. Kelas, siswa, mapel,
pengampu, dan akun guru tersalin.

## 4. Akun & peran (di Pusat → Akun)

Beri akses **Jurnal Guru** untuk guru (peran `guru`; juga `kepsek`, `bk`, `tu`, `admin` sesuai tugas), satu per satu atau
lewat *Buat akun sekaligus* (peran awal guru di Jurnal sudah terisi). Jurnal adalah aplikasi kehadiran sekolah: BK
membaca dan menulis kehadiran ke Jurnal lewat sambungan "Jurnal Guru → BK" di Pusat → Administrasi → Sambungan.

## 5. Setelah BK dan TU terpasang

Jurnal masih punya menu BK dan TU bawaan. Agar menu itu membuka aplikasi BK dan TU, isi di `.env` Jurnal:

```ini
BK_URL=https://bk.smkibupakusari.sch.id
TU_URL=https://tu.smkibupakusari.sch.id
```

lalu `sudo -u www-data php artisan config:clear`.

## 6. Perangkat Ajar & asisten AI (opsional)

Alur: **tim MGMP** menyusun draf perangkat ajar (modul ajar, LKPD, asesmen & rubrik, bahan bacaan, tindak lanjut) per
mapel + tingkat tiap semester, boleh dengan bahan ajar rujukan (PDF/DOCX/TXT), lalu menandainya *siap*. Guru yang
mengampu mapel itu tinggal **mengambil** draf untuk kelasnya; salinan memakai nama, NIP, dan kelasnya sendiri.

- Anggota MGMP: di Pusat → Akun, beri peran **mgmp** di aplikasi Jurnal Guru (selain `guru`). Draf hanya bisa disusun
  untuk mapel yang diampunya.
- AI hanya dipakai untuk menyusun draf MGMP (sekali per draf, bukan per guru). Kunci API Gemini gratis dari
  [Google AI Studio](https://aistudio.google.com/apikey), pilih salah satu:
  - **Kunci sekolah:** isi `GEMINI_API_KEY=...` di `.env` lalu `sudo -u www-data php artisan config:clear`; dipakai semua MGMP.
  - **Kunci pribadi:** biarkan `GEMINI_API_KEY` kosong; tiap anggota MGMP mengisi kuncinya sendiri di
    Perangkat Ajar → **Kunci AI** (tersimpan terenkripsi di server).
- Tanpa kunci, draf tetap bisa dibuat dari templat. Yang dikirim ke Google hanya data pembelajaran dan bahan rujukan,
  tanpa nama guru atau siswa. `GEMINI_MODEL` (bawaan `gemini-flash-latest`) bisa diganti bila perlu.

## 7. Cek akhir

- Buka `https://jurnal.smkibupakusari.sch.id` → *Masuk lewat Pusat* → menu **Mengajar** muncul.
- Admin → Integrasi Pusat menampilkan sinkron terakhir berhasil.

## Pembaruan & cadangan

```bash
cd /var/www/jurnal && sudo bash deploy/update.sh
```

Backup database dibuat otomatis tiap hari pukul 02:00 (dan sebelum setiap pembaruan). Uji pulih: [`UJI-PULIH.md`](UJI-PULIH.md).

## Pasang manual (tanpa skrip)

Bila skrip tidak bisa dipakai, ikuti [`UBUNTU.md`](UBUNTU.md); caranya sama, hanya ganti folder `/var/www/jurnal`,
database `jurnal_guru` / `jurnal`, dan domain `jurnal.smkibupakusari.sch.id`.
