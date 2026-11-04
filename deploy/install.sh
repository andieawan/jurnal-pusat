#!/usr/bin/env bash
# Instalasi Jurnal Guru SMKS Islam Bustanul Ulum di VM Ubuntu Server 24.04 / 26.04 (atau Debian 13).
#
#   git clone https://github.com/andieawan/jurnal-pusat.git /var/www/jurnal      # TANPA sudo (repo privat: isi token)
#   cd /var/www/jurnal
#   sudo DOMAIN=jurnal.smkibupakusari.sch.id APP_URL=https://jurnal.smkibupakusari.sch.id PROXY_IP=192.168.10.10 bash deploy/install.sh
#
# Variabel opsional (awali perintah dengan NAMA=nilai):
#   DOMAIN         nama domain aplikasi (default: _ = melayani akses lewat IP juga)
#   APP_URL        alamat publik lengkap (default: http://$DOMAIN, atau http://IP-VM)
#   PROXY_IP       IP reverse proxy (NPMplus) yang dipercaya (default 192.168.10.10)
#   DB_NAME/DB_USER  (default jurnal_guru / jurnal)
#   ADMIN_USER     username admin lokal darurat (default admin.sekolah; password ditanyakan)
#   SKIP_DB_INSTALL=1  jangan pasang MariaDB (pakai server MySQL/MariaDB yang sudah ada)
#
# Aman diulang: .env, database, dan admin yang sudah ada tidak diubah.
set -euo pipefail

NAMA="jurnal"                 # dipakai untuk nama berkas nginx/cron/php.ini
JUDUL="Jurnal Guru"
DOMAIN="${DOMAIN:-_}"
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
if [ "$DOMAIN" = "_" ]; then APP_URL="${APP_URL:-http://${IP:-localhost}}"; else APP_URL="${APP_URL:-http://$DOMAIN}"; fi
PROXY_IP="${PROXY_IP:-192.168.10.10}"
DB_NAME="${DB_NAME:-jurnal_guru}"
DB_USER="${DB_USER:-jurnal}"
ADMIN_USER="${ADMIN_USER:-admin.sekolah}"
SKIP_DB_INSTALL="${SKIP_DB_INSTALL:-0}"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
gagal() { printf '\n\033[1;31mGAGAL: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || gagal "Jalankan dengan sudo: sudo bash deploy/install.sh"
command -v apt-get >/dev/null || gagal "Skrip ini untuk Ubuntu/Debian (butuh apt)."
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[ -f "$APP_DIR/artisan" ] && [ -d "$APP_DIR/vendor" ] || gagal "Jalankan dari folder hasil git clone (yang berisi artisan dan vendor/)."
# Kode milik user yang meng-clone (git pull & update.sh berjalan sebagai dia); web server lewat grup www-data.
OWNER="$(stat -c %U "$APP_DIR")"
[ "$OWNER" != "root" ] || gagal "Folder $APP_DIR milik root. Clone ulang TANPA sudo, atau: sudo chown -R \$USER:\$USER $APP_DIR"
export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------- 1. Paket
info "Memasang paket (nginx, PHP, MariaDB, cron)"
apt-get update -y
PAKET=(nginx git unzip curl openssl ca-certificates cron
       php-fpm php-cli php-mysql php-sqlite3 php-mbstring php-xml php-curl php-zip php-bcmath php-intl php-gd)
[ "$SKIP_DB_INSTALL" = "1" ] || PAKET+=(mariadb-server)
apt-get install -y "${PAKET[@]}"

PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
    || gagal "PHP $PHPV terlalu lama; butuh PHP 8.3+. Pakai Ubuntu 24.04/26.04 atau Debian 13."
info "PHP $PHPV terdeteksi"
cat > "/etc/php/${PHPV}/fpm/conf.d/99-${NAMA}.ini" <<INI
upload_max_filesize = 10M
post_max_size = 12M
expose_php = Off
INI
systemctl enable --now "php${PHPV}-fpm"
systemctl restart "php${PHPV}-fpm"

# ---------------------------------------------------------------- 2. Izin folder
cd "$APP_DIR"
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/private bootstrap/cache
chown -R "$OWNER":www-data "$APP_DIR"
chmod -R ug+rwX storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod g+s {} +

artisan() { runuser -u www-data -- php "$APP_DIR/artisan" "$@"; }

# ---------------------------------------------------------------- 3. Database + .env
if [ -f .env ]; then
    info ".env sudah ada: database dan .env tidak diubah"
    NEW_INSTALL=0
else
    NEW_INSTALL=1
    DB_PASS="$(openssl rand -hex 12)"
    info "Membuat database '$DB_NAME' dan user '$DB_USER'"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    info "Menulis .env"
    cp .env.example .env
    sed -i \
        -e "s|^APP_NAME=.*|APP_NAME=\"${JUDUL}\"|" \
        -e "s|^APP_URL=.*|APP_URL=${APP_URL}|" \
        -e "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" \
        -e "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" \
        -e "s|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=${PROXY_IP}|" .env
    case "$APP_URL" in
        https://*) sed -i 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|' .env ;;
        *)         sed -i -e 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|' -e 's|^# APP_FORCE_HTTPS=.*|APP_FORCE_HTTPS=false|' .env ;;
    esac
    php artisan key:generate --force          # sebagai root: .env belum dikunci
    chown "$OWNER":www-data .env
    chmod 640 .env
fi

info "Migrasi database"
artisan migrate --force
artisan optimize:clear >/dev/null

# ---------------------------------------------------------------- 4. Nginx
info "Menyiapkan Nginx"
SITE="/etc/nginx/sites-available/${NAMA}"
sed -e "s#absensi.smkibupakusari.sch.id#${DOMAIN}#" \
    -e "s#/var/www/absensi#${APP_DIR}#" \
    -e "s#php8.5-fpm.sock#php${PHPV}-fpm.sock#" \
    -e "s#/var/log/nginx/absensi\.#/var/log/nginx/${NAMA}.#g" deploy/nginx-absensi.conf > "$SITE"
if [ "$DOMAIN" = "_" ]; then sed -i 's#listen 80;#listen 80 default_server;#; s#listen \[::\]:80;#listen [::]:80 default_server;#' "$SITE"; fi
ln -sf "$SITE" "/etc/nginx/sites-enabled/${NAMA}"
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx
systemctl reload nginx

# ---------------------------------------------------------------- 5. Penjadwal
# Backup harian (02:00), antrean ke Pusat, dan sinkron otomatis berjalan lewat schedule:run.
echo "* * * * * www-data cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1" > "/etc/cron.d/${NAMA}"
chmod 644 "/etc/cron.d/${NAMA}"
systemctl enable --now cron >/dev/null 2>&1 || true

# ---------------------------------------------------------------- 6. Admin lokal darurat
if [ "$NEW_INSTALL" = "1" ]; then
    if [ -t 0 ]; then
        info "Membuat admin lokal '$ADMIN_USER' (untuk keadaan darurat bila Pusat mati)"
        artisan absensi:admin "$ADMIN_USER" || echo "Admin belum dibuat; ulangi nanti: sudo -u www-data php artisan absensi:admin $ADMIN_USER"
    else
        echo "Admin belum dibuat (tanpa terminal). Jalankan: sudo -u www-data php artisan absensi:admin $ADMIN_USER"
    fi
fi

chown -R "$OWNER":www-data storage bootstrap/cache
artisan absensi:periksa || true

# ---------------------------------------------------------------- 7. Selesai
info "Selesai"
cat <<RINGKAS

  Aplikasi : ${APP_URL}   (sementara http://${IP}/)
  Folder   : ${APP_DIR}  (milik ${OWNER})
RINGKAS
if [ "$NEW_INSTALL" = "1" ]; then
    printf '  Database : %s / user %s (password ada di %s/.env)\n' "$DB_NAME" "$DB_USER" "$APP_DIR"
fi
cat <<LANJUT

  Berikutnya (rincian: docs/PASANG-VM-JURNAL.md):
   1. NPMplus: Proxy Host ${APP_URL#*://} -> ${IP}:80, SSL + Force SSL; DNS bila belum ada
   2. Di VM Pusat: sudo -u www-data php artisan pusat:aplikasi-baru ${NAMA} --base-url=${APP_URL}
      lalu isi PUSAT_ENABLED=true, PUSAT_URL, PUSAT_CLIENT_ID, PUSAT_CLIENT_SECRET, PUSAT_WEBHOOK_SECRET di .env
   3. sudo -u www-data php artisan config:clear && sudo -u www-data php artisan absensi:periksa
   4. Masuk sebagai ${ADMIN_USER} -> Admin -> Integrasi Pusat -> Uji koneksi -> Sinkron sekarang
   Memperbarui nanti: cd ${APP_DIR} && sudo bash deploy/update.sh
LANJUT
