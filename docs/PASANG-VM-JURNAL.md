# Pasang Jurnal Guru di VM baru

Satu aplikasi = satu VM, sama seperti BK, TU, dan PKL. Guru masuk lewat **Pusat Sekolah**; admin lokal hanya untuk
keadaan darurat saat Pusat mati.

| | Nilai |
|---|---|
| VM / IP | jurnal-pusat / `192.168.10.26` (lihat dokumen Susunan IP) |
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

Lalu di **NPMplus** buat Proxy Host `jurnal.smkibupakusari.sch.id` → `192.168.10.26`, port 80, SSL Let's Encrypt,
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

Selama uji coba, beri akses **Jurnal Guru** (peran `guru`) hanya untuk guru yang ikut mencoba. Pada hari peralihan,
akses semua guru disalin sekaligus dengan `pusat:alihkan-absensi` — lihat [`PINDAH-DARI-ABSENSI.md`](PINDAH-DARI-ABSENSI.md).

## 5. Cek akhir

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
