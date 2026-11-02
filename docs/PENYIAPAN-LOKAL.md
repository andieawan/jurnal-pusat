# Mencoba Absensi Siswa di komputer pribadi (Windows)

Pasang **Pusat** lebih dulu dan siapkan alat (Laragon, Git) mengikuti `docs/PENYIAPAN-LOKAL.md` di repo `pusat-sekolah`. Aplikasi ini dijalankan di **port 8001**; semua perintah di Terminal Laragon, satu per satu.
Selalu pakai alamat `http://127.0.0.1:8001` (bukan `localhost`).

## 1. Ambil kode

> **`vendor/` sudah ikut di repo** (dibuat dengan `composer install --no-dev`, PHP 8.3). Setelah `git clone`, langkah `composer install` boleh dilewati. Jalankan `composer install` hanya untuk menjalankan tes (butuh paket dev) atau setelah `composer.json` berubah; untuk memperbarui `vendor/` di repo, jalankan workflow **Bundel vendor** (tab Actions di GitHub).

```bash
cd C:\laragon\www
git clone https://github.com/andieawan/absensi-pusat.git
cd absensi-pusat
composer install
```
(Repo privat: *Password* = token GitHub, lihat panduan VM.)

## 2. Database & `.env`
```bash
mysql -u root -e "CREATE DATABASE absensi_siswa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
copy .env.example .env
php artisan key:generate
notepad .env
```
Ubah:
```ini
APP_URL=http://127.0.0.1:8001
DB_DATABASE=absensi_siswa
DB_USERNAME=root
DB_PASSWORD=
SESSION_SECURE_COOKIE=false
SESSION_COOKIE=absensi_session
TRUSTED_PROXIES=
```
`SESSION_COOKIE` harus beda dari aplikasi lain (cookie tidak membedakan port).

## 3. Tabel & akun admin
```bash
php artisan migrate
php artisan absensi:admin admin.sekolah      # password ditanya (min. 10 karakter)
php artisan db:seed --class=DemoSeeder   # opsional: data contoh
```

## 4. Jalankan
```bash
php artisan serve --port=8001
```
Buka http://127.0.0.1:8001/login

## 5. Sambungkan ke Pusat (opsional)
Cara tercepat: dari folder **Pusat**, satu perintah mendaftarkan Absensi dan menuliskan kredensial ke `.env` Absensi ini:
```bash
php artisan pusat:siapkan-lokal --absensi-url=http://127.0.0.1:8001 --env-absensi=C:\laragon\www\absensi-pusat\.env --demo
```
(Ganti alamat dan path sesuai milik Anda; `--demo` menambah guru/kelas/siswa contoh.) Lalu di folder Absensi:
```bash
php artisan migrate
php artisan config:clear
```
Masuk ke Absensi → Admin → **Pusat** → *Uji koneksi* → *Periksa* (simulasi) → *Terapkan*. Tombol "Masuk lewat Pusat" di halaman login bisa dipakai dengan akun superadmin Pusat.

Cara manual (tanpa perintah di atas): `php artisan pusat:aplikasi-baru absensi --base-url=http://127.0.0.1:8001` di Pusat, lalu isi `PUSAT_ENABLED=true`, `PUSAT_URL`, `PUSAT_CLIENT_ID`, `PUSAT_CLIENT_SECRET`, `PUSAT_WEBHOOK_SECRET` di `.env` Absensi.

Masalah umum (database, cookie, redirect_uri, port): lihat bagian terakhir `docs/PENYIAPAN-LOKAL.md` di repo `pusat-sekolah`.

## Galat sertifikat HTTPS (cURL error 60)
Bila situs lokal memakai HTTPS bersertifikat buatan sendiri (mis. envkit/mkcert), PHP menolaknya. Pilih satu di `.env` aplikasi ini, lalu `php artisan config:clear`:
- `PUSAT_URL=http://...` bila Pusat juga bisa dibuka lewat http;
- `PUSAT_CA_BUNDLE=C:\path\rootCA.pem` (berkas CA lokal; pemeriksaan tetap aktif) — disarankan;
- `PUSAT_VERIFY_SSL=false` — hanya untuk uji lokal, jangan di server sungguhan.
Di sisi Pusat (pengiriman webhook ke aplikasi ini) pakai `PUSAT_WEBHOOK_VERIFY_SSL=false` atau `PUSAT_WEBHOOK_CA_BUNDLE=...`. Pintasan: `php artisan pusat:siapkan-lokal ... --abaikan-ssl` (di folder Pusat) menuliskan keduanya.
