# Pasang Jurnal Guru di VM baru

Ringkas khusus Jurnal Guru. Penjelasan lengkap tiap langkah (HTTPS lewat proxy, cadangan ke VPS, masalah umum) ada di
[`UBUNTU.md`](UBUNTU.md) — caranya sama, hanya nama folder, database, dan domain yang berbeda.

Contoh di bawah memakai:

| | Nilai |
|---|---|
| Folder | `/var/www/jurnal` |
| Database / user | `jurnal_guru` / `jurnal` |
| Domain | `jurnal.smkibupakusari.sch.id` |

## 1. Paket sistem (Ubuntu 24.04 / 26.04)

```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone Asia/Jakarta
sudo apt -y install nginx mariadb-server git unzip curl \
  php-fpm php-cli php-mysql php-sqlite3 php-mbstring php-xml php-zip php-curl php-intl php-gd php-bcmath
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'); echo "PHP $PHPV"      # harus ≥ 8.3
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 10M/; s/^post_max_size.*/post_max_size = 12M/' /etc/php/$PHPV/fpm/php.ini
sudo systemctl restart php$PHPV-fpm
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
```
Ubuntu 22.04 (PHP 8.1) perlu PPA dulu — lihat `UBUNTU.md` bagian 2.

## 2. Database

```bash
sudo mariadb
```
```sql
CREATE DATABASE jurnal_guru CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'jurnal'@'localhost' IDENTIFIED BY 'GantiPasswordKuat!';
GRANT ALL PRIVILEGES ON jurnal_guru.* TO 'jurnal'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 3. Kode aplikasi (tanpa `sudo`)

```bash
sudo mkdir -p /var/www/jurnal && sudo chown $USER:$USER /var/www/jurnal
git clone https://github.com/andieawan/jurnal-pusat.git /var/www/jurnal
cd /var/www/jurnal
git config credential.helper store      # agar git pull berikutnya tidak meminta token lagi
```
`vendor/` sudah ikut di repo — tidak perlu `composer install`.

## 4. `.env`

```bash
cp .env.example .env
php artisan key:generate
nano .env
```
Ubah:
```ini
APP_NAME="Jurnal Guru"
APP_URL=https://jurnal.smkibupakusari.sch.id
DB_DATABASE=jurnal_guru
DB_USERNAME=jurnal
DB_PASSWORD=GantiPasswordKuat!
SESSION_SECURE_COOKIE=true        # false bila belum HTTPS
```

## 5. Hak akses, tabel, admin

```bash
sudo chown -R $USER:www-data .
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
php artisan migrate --force
php artisan absensi:admin admin.sekolah       # akun admin lokal (darurat)
```

## 6. Nginx + HTTPS

```bash
sudo cp deploy/nginx-absensi.conf /etc/nginx/sites-available/jurnal
PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo sed -i "s#absensi.smkibupakusari.sch.id#jurnal.smkibupakusari.sch.id#; s#/var/www/absensi#/var/www/jurnal#; s#php8.5-fpm.sock#php$PHPV-fpm.sock#" /etc/nginx/sites-available/jurnal
sudo ln -s /etc/nginx/sites-available/jurnal /etc/nginx/sites-enabled/jurnal
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```
HTTPS: `sudo apt -y install certbot python3-certbot-nginx && sudo certbot --nginx -d jurnal.smkibupakusari.sch.id`
(atau lewat NPMplus seperti aplikasi lain — lihat `UBUNTU.md` bagian 9, lalu isi `TRUSTED_PROXIES` di `.env`).

## 7. Cron (backup & antrean ke Pusat)

```bash
sudo crontab -u www-data -e
```
```
* * * * * cd /var/www/jurnal && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Sambungkan ke Pusat Sekolah

1. Di server **Pusat**:
   ```bash
   cd /var/www/pusat-sekolah && sudo bash deploy/update.sh
   php artisan pusat:aplikasi-baru jurnal --base-url=https://jurnal.smkibupakusari.sch.id \
     --redirect=https://jurnal.smkibupakusari.sch.id/sso/callback \
     --webhook=https://jurnal.smkibupakusari.sch.id/api/pusat/webhook
   ```
   Salin `client_id`, `client_secret`, `webhook_secret` (hanya tampil sekali). Izinnya otomatis sama dengan Absensi
   (data induk, mapel, pengampu, akun, login cadangan).
2. Di VM Jurnal, tambahkan ke `.env`:
   ```ini
   PUSAT_ENABLED=true
   PUSAT_URL=https://pusat.smkibupakusari.sch.id
   PUSAT_CLIENT_ID=...
   PUSAT_CLIENT_SECRET=...
   PUSAT_WEBHOOK_SECRET=...
   ```
   lalu `php artisan config:cache`.
3. Masuk sebagai admin → **Admin → Integrasi → Pusat Sekolah**: *Uji koneksi*, lalu *Sinkron sekarang*.
   Kelas, siswa, mapel, pengampu, dan akun guru tersalin; guru langsung bisa masuk lewat Pusat.
4. Di Pusat → **Akun**, beri akses aplikasi **Jurnal Guru** untuk guru (peran `guru`), atau saat *Buat akun sekaligus*.

## 9. Cek akhir

- Buka `https://jurnal.smkibupakusari.sch.id` → login → menu **Mengajar** muncul.
- `sudo -u www-data php artisan absensi:periksa` tidak menampilkan masalah.

## Memperbarui

```bash
cd /var/www/jurnal && sudo bash deploy/update.sh
```
