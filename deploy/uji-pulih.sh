#!/usr/bin/env bash
# Uji pulih backup: memulihkan backup TERBARU ke database SEMENTARA (bukan database aplikasi),
# membandingkan jumlah baris per tabel dengan database yang sedang dipakai, lalu menghapus database sementara.
# Aplikasi TIDAK terganggu dan data asli TIDAK diubah.
#
#   cd /var/www/<aplikasi> && sudo bash deploy/uji-pulih.sh                 (backup terbaru)
#   sudo bash deploy/uji-pulih.sh /var/backups/pusat/pusat_2026-10-20_0115.sql.gz   (berkas tertentu)
#
# Jalankan sebulan sekali. Backup yang belum pernah diuji pulih belum terbukti bisa dipakai.
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Jalankan dengan sudo: sudo bash deploy/uji-pulih.sh" >&2; exit 1; }
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[ -f "$APP/.env" ] || { echo ".env tidak ada di $APP" >&2; exit 1; }

envval() {
  local v; v="$(grep -E "^$1=" "$APP/.env" | tail -n1 | cut -d= -f2- || true)"
  v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"; printf '%s' "$v"
}
merah() { printf '\033[1;31m%s\033[0m\n' "$*"; }
hijau() { printf '\033[1;32m%s\033[0m\n' "$*"; }

[ "$(envval DB_CONNECTION)" = "mysql" ] || [ "$(envval DB_CONNECTION)" = "mariadb" ] \
  || { echo "Skrip ini untuk MySQL/MariaDB (DB_CONNECTION=$(envval DB_CONNECTION))." >&2; exit 1; }
DB="$(envval DB_DATABASE)"
CLI="$(command -v mariadb || command -v mysql || true)"
[ -n "$CLI" ] || { echo "Klien mariadb/mysql belum terpasang." >&2; exit 1; }
# Akses admin lewat socket root (MariaDB di VM yang sama). Ganti dengan DB_ADMIN_CMD bila berbeda.
SQL="${DB_ADMIN_CMD:-$CLI}"

# 1. Pilih berkas backup (terbaru dari lokasi bawaan semua aplikasi sekolah)
FILE="${1:-}"
if [ -z "$FILE" ]; then
  ABS_DIR="$(envval ABSENSI_BACKUP_DIR)"; ABS_DIR="${ABS_DIR:-$APP/storage/app/backups}"
  FILE="$(ls -1t /var/backups/pusat/pusat_*.sql.gz "$ABS_DIR"/absensi_*.sql /var/backups/bk-pusat/db_*.sql.gz /var/backups/tu-pusat/db_*.sql.gz \
            /var/backups/smkibu/db_*.sql.gz 2>/dev/null | head -1 || true)"
fi
[ -n "$FILE" ] && [ -f "$FILE" ] || { merah "GAGAL: tidak ada berkas backup. Jalankan deploy/backup.sh dulu (atau periksa cron backup)."; exit 1; }

UMUR=$(( ( $(date +%s) - $(date -r "$FILE" +%s) ) / 3600 ))
echo "Backup diuji : $FILE"
echo "Ukuran       : $(du -h "$FILE" | cut -f1), dibuat $UMUR jam lalu"
[ "$UMUR" -le 48 ] || merah "PERINGATAN: backup terbaru berumur lebih dari 2 hari — cron backup mungkin tidak berjalan."

# 2. Pulihkan ke database sementara
UJI="${DB}_ujipulih"
bersih() { $SQL -e "DROP DATABASE IF EXISTS \`$UJI\`" >/dev/null 2>&1 || true; }
trap bersih EXIT
bersih
$SQL -e "CREATE DATABASE \`$UJI\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
echo "Memulihkan ke database sementara \"$UJI\" ..."
case "$FILE" in
  *.gz) gzip -t "$FILE" || { merah "GAGAL: berkas gzip rusak."; exit 1; }
        gunzip -c "$FILE" | $SQL --default-character-set=utf8mb4 "$UJI" || { merah "GAGAL: isi backup tidak bisa diimpor (rusak/terpotong)."; exit 1; } ;;
  *.sql) $SQL --default-character-set=utf8mb4 "$UJI" < "$FILE" || { merah "GAGAL: isi backup tidak bisa diimpor (rusak/terpotong)."; exit 1; } ;;
  *) merah "Format tidak didukung: $FILE (harus .sql atau .sql.gz)"; exit 1 ;;
esac

# 3. Bandingkan jumlah baris per tabel: backup vs database aplikasi sekarang
hitung() {
  local db="$1"
  for t in $($SQL -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$db' AND table_type='BASE TABLE' ORDER BY table_name"); do
    printf '%s %s\n' "$t" "$($SQL -N -e "SELECT COUNT(*) FROM \`$db\`.\`$t\`")"
  done
}
declare -A SEKARANG
while read -r t n; do SEKARANG[$t]=$n; done < <(hitung "$DB")

echo
printf '%-32s %12s %12s\n' "Tabel" "Di backup" "Sekarang"
printf '%-32s %12s %12s\n' "--------------------------------" "------------" "------------"
JML=0; KOSONG=1; HILANG=0
while read -r t n; do
  JML=$((JML+1)); [ "$n" -gt 0 ] && [ "$t" != "migrations" ] && KOSONG=0
  s="${SEKARANG[$t]:--}"
  printf '%-32s %12s %12s\n' "$t" "$n" "$s"
done < <(hitung "$UJI")
for t in "${!SEKARANG[@]}"; do
  $SQL -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='$UJI' AND table_name='$t'" | grep -q 1 || { HILANG=$((HILANG+1)); merah "Tabel $t tidak ada di backup"; }
done

echo
if [ "$JML" -eq 0 ] || [ "$KOSONG" -eq 1 ]; then
  merah "GAGAL: backup bisa dibuka tetapi tidak berisi data."
  exit 1
fi
[ "$HILANG" -eq 0 ] || merah "PERHATIAN: $HILANG tabel tidak ada di backup (wajar bila ada migrasi baru setelah backup dibuat)."
hijau "BERHASIL: backup bisa dipulihkan ($JML tabel). Selisih jumlah baris dengan kolom \"Sekarang\" wajar = data yang masuk sejak backup dibuat."
echo "Database sementara $UJI dihapus otomatis. Data aplikasi tidak diubah."
