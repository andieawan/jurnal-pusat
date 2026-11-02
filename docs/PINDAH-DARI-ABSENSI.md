# Pindah dari Absensi ke Jurnal Guru

Panduan hari-H mematikan aplikasi **Absensi** setelah **Jurnal Guru** siap. Semua data lama (absensi, nilai,
catatan BK, surat TU, mutasi, absen pegawai, tautan wali murid & papan kelas, berkas unggahan, log) ikut pindah,
dan BK/TU/PKL otomatis tersambung ke Jurnal.

Perkiraan waktu: ±30 menit. Lakukan di luar jam pelajaran (sore/akhir pekan).

| | Server Absensi | Server Jurnal | Server Pusat |
|---|---|---|---|
| Folder | `/var/www/absensi` | `/var/www/jurnal` | `/var/www/pusat-sekolah` |

> **Prinsip:** data **digabung, bukan ditimpa**. Isian guru di Jurnal selama uji coba tetap ada. Kalau satu
> pertemuan tercatat di keduanya, yang **diubah paling akhir** yang dipakai. Perintah impor aman diulang.

---

## 0. Sebelum hari-H

- Jurnal sudah terpasang (`docs/PASANG-VM-JURNAL.md`), login lewat Pusat berhasil, dan **Sinkron Pusat** di Jurnal
  sudah hijau (siswa, kelas, mapel, akun terisi). Siswa/kelas/mapel dicocokkan lewat data Pusat ini.
- Perbarui ketiga server ke versi terbaru:
  ```bash
  cd /var/www/pusat-sekolah && sudo bash deploy/update.sh   # di server Pusat
  cd /var/www/absensi       && sudo bash deploy/update.sh   # di server Absensi
  cd /var/www/jurnal        && sudo bash deploy/update.sh   # di server Jurnal
  ```

## 1. Cadangkan keduanya

```bash
cd /var/www/absensi && sudo -u www-data php artisan absensi:backup   # server Absensi
cd /var/www/jurnal  && sudo -u www-data php artisan absensi:backup   # server Jurnal
```

## 2. Hentikan input di Absensi

```bash
cd /var/www/absensi && sudo -u www-data php artisan down
```

Mulai sekarang guru tidak bisa mengisi Absensi, jadi tidak ada data yang tertinggal. Perintah artisan tetap berjalan.

## 3. Ekspor dari Absensi

```bash
cd /var/www/absensi && sudo -u www-data php artisan absensi:ekspor-jurnal
```

Hasilnya satu berkas, misalnya `storage/app/private/pindah/absensi-20261102-160000.zip`, beserta tabel jumlah data.
Salin ke server Jurnal:

```bash
scp storage/app/private/pindah/absensi-*.zip USER@IP-JURNAL:/tmp/
```

> Berkas ini berisi data pribadi siswa dan sandi akun (dalam bentuk hash). Hapus dari `/tmp` setelah langkah 4 selesai.

## 4. Impor ke Jurnal: simulasi dulu, lalu terapkan

```bash
cd /var/www/jurnal
sudo -u www-data php artisan pusat:sinkron --terapkan            # pastikan data induk terbaru
sudo -u www-data php artisan jurnal:impor-absensi /tmp/absensi-*.zip              # SIMULASI, tidak menyimpan
sudo -u www-data php artisan jurnal:impor-absensi /tmp/absensi-*.zip --terapkan   # simpan
```

Bandingkan tabel hasil simulasi dengan tabel ekspor di langkah 3:

- **Baru**: data yang dipindah.
- **Dilengkapi**: data yang sudah ada di Jurnal lalu dilengkapi, misalnya kontak orang tua atau mapel yang diampu.
- **Sudah ada / dilewati**: data yang sudah ada, atau yang siswa/kelas/mapelnya tidak ditemukan di Jurnal.

Kalau banyak yang dilewati padahal Jurnal masih kosong, berarti Sinkron Pusat belum lengkap. Jalankan sinkron lalu
ulangi simulasi. Kalau terjadi galat, tidak ada yang tersimpan sama sekali.

## 5. Alihkan di Pusat

```bash
cd /var/www/pusat-sekolah && sudo -u www-data php artisan pusat:alihkan-absensi jurnal --nonaktifkan-lama
```

Perintah ini melakukan tiga hal:

1. Peran setiap akun di Absensi (guru, wali kelas, BK, kepsek, admin) disalin ke Jurnal Guru. Akses ke aplikasi lain tidak berubah.
2. Sambungan **Absensi → BK** dipindah ke Jurnal. BK otomatis memakai alamat dan kunci Jurnal, tanpa perlu disetel ulang.
3. Absensi dinonaktifkan di Pusat, sehingga hilang dari portal dan login lewat Pusat ke Absensi ditolak.

Lalu, di Jurnal, buka **Admin → Integrasi Pusat → Sinkron**. Di BK, buka **Sambungan → Uji koneksi**.

## 6. Arahkan alamat lama ke Jurnal

Tautan wali murid dan papan kelas yang sudah dibagikan memakai alamat `absensi.smkibupakusari.sch.id`. Agar tetap
berfungsi, arahkan alamat itu ke Jurnal. Jalurnya sama, dan tokennya sudah ikut dipindah.

Pada proxy atau Nginx yang melayani `absensi.smkibupakusari.sch.id`, ganti isi blok `server` menjadi:

```nginx
server {
    listen 443 ssl;   # pakai sertifikat yang sudah ada
    server_name absensi.smkibupakusari.sch.id;
    return 301 https://jurnal.smkibupakusari.sch.id$request_uri;
}
```

Kalau memakai proxy/NAT (misalnya Nginx Proxy Manager), cukup buat *Redirection Host* dari
`absensi.smkibupakusari.sch.id` ke `jurnal.smkibupakusari.sch.id` dengan opsi *preserve path*.

## 7. Periksa, lalu matikan VM Absensi

- [ ] Guru login ke Jurnal lewat Pusat, dan riwayat absensi kelasnya muncul.
- [ ] Rekap dan laporan bulan lalu sama dengan di Absensi.
- [ ] Nilai, catatan BK, dan surat TU lama terbuka, termasuk berkas lampirannya.
- [ ] Sebuah tautan wali murid lama terbuka (lewat alamat absensi.*).
- [ ] BK menampilkan presensi dari Jurnal.

Kalau semua sudah sesuai, **matikan VM Absensi tetapi jangan dihapus**. Simpan VM itu beserta cadangan dari langkah 1
setidaknya satu semester.

---

## Yang tidak ikut pindah

| Data | Alasan / tindakan |
|---|---|
| Kunci API buatan sendiri (menu Kunci API) | Hanya hash yang tersimpan. Buat ulang di Jurnal, lalu perbarui di aplikasi pemakainya. |
| Tautan ketua kelas | Berlaku singkat, jadi buat baru dari Jurnal. |
| Nama sekolah, logo, tahun ajaran | Datang dari Pusat. Ambang peringatan, aturan sandi, dan pengaturan absen pegawai **ikut** pindah. |

## Membatalkan

Kalau ada masalah sebelum VM Absensi dimatikan:

```bash
cd /var/www/pusat-sekolah && sudo -u www-data php artisan pusat:alihkan-absensi jurnal --balik   # server Pusat
cd /var/www/absensi && sudo -u www-data php artisan up                                           # server Absensi
```

Absensi kembali aktif dan sambungan BK kembali ke Absensi. Akses Jurnal yang sudah disalin tidak dihapus, dan data
yang sudah diimpor tetap ada di Jurnal. Impor bisa diulang kapan saja.
