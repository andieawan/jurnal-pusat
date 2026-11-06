# Penyambungan ke Pusat Sekolah

Jurnal bisa memakai **login tunggal** dan **data induk (kelas & siswa)** dari aplikasi Pusat Sekolah.
Semuanya **mati secara bawaan** (`PUSAT_ENABLED=false`): mengunggah versi ini tidak mengubah apa pun sampai Anda mengaktifkannya.
Login username/password lama **tetap ada** sebagai cadangan.

## Urutan pemasangan (jangan dibalik)

1. **Pusat sudah berjalan** (lihat README Pusat) dan aplikasi `jurnal` sudah didaftarkan di sana
   (`php artisan pusat:aplikasi-baru jurnal --base-url=https://JURNAL --redirect=https://JURNAL/sso/callback --webhook=https://JURNAL/api/pusat/webhook`).
   Simpan `client_id`, `client_secret`, `webhook_secret` yang dicetak (hanya tampil sekali).
2. **Perbarui database Jurnal**: Admin → Pengaturan → *Perbarui Database Sekarang* (atau `php artisan migrate --force`).
   Ini hanya menambah kolom `pusat_uuid` dan dua tabel kecil; data lama tidak disentuh.
3. *(Hanya bila Jurnal sudah berisi data sebelum disambungkan; pemasangan baru lewati langkah ini — data induk diisi di Pusat.)*
   **Ekspor data Jurnal → Pusat**:
   ```
   php artisan pusat:ekspor        # hasil: storage/app/private/pusat-ekspor/1-guru.csv ... 4-akun.csv
   ```
   Di Pusat (Admin → Impor CSV), impor berurutan: **1-guru → 2-kelas → 3-siswa** (selalu lihat pratinjau dulu).
   Lalu di server Pusat: `php artisan pusat:impor-akun 4-akun.csv --aplikasi=jurnal` (simulasi),
   ulangi dengan `--terapkan`. Password sementara tiap guru ditulis ke satu berkas — bagikan lalu **hapus berkas itu**.
   Hapus juga CSV ekspor setelah selesai (berisi data pribadi siswa).
   Catatan: guru di Jurnal tidak punya NIP, jadi `username` dipakai sebagai ID pegawai sementara.
4. **Isi `.env` Jurnal**, lalu `php artisan config:clear`:
   ```
   PUSAT_ENABLED=true
   PUSAT_URL=https://pusat.sekolah.example
   PUSAT_CLIENT_ID=...
   PUSAT_CLIENT_SECRET=...
   PUSAT_WEBHOOK_SECRET=...
   ```
   Di balik proxy HTTPS, isi juga `TRUSTED_PROXIES` agar `redirect_uri` bersekema `https://` dan cocok dengan yang didaftarkan.
5. **Admin → Integrasi Pusat**: *Uji Koneksi* → *Periksa Perubahan (simulasi)* → baca hasilnya → *Terapkan*.
   Pada penerapan pertama, kelas dan siswa lama **ditautkan** ke data Pusat lewat nama kelas / NIS (tidak membuat ganda).
   Nama kembar atau NIS kembar dilewati dan dilaporkan.
6. **Coba login** dengan satu akun guru lewat tombol *Masuk lewat Pusat* (jendela lain tetap login lokal sebagai cadangan).

## Cara kerja

| Hal | Perilaku |
|---|---|
| Login | Tombol *Masuk lewat Pusat* → Pusat → kembali ke `/sso/callback`. Akun dicocokkan lewat UUID Pusat; **pertama kali** lewat username yang sama. Bila belum ada akun lokal, dibuat otomatis (peran dari Pusat, tanpa mapel/kelas — atur di Admin → Akun Guru). |
| Peran | Dari Pusat dipetakan: `guru, bk, tu, kepsek, admin, mgmp`. `wali_kelas` dan lainnya diabaikan — wali kelas & pasangan mapel tetap diatur di Jurnal. Peran `superadmin` **tidak pernah** diubah oleh Pusat. Peran diselaraskan tiap login. |
| Data induk | **Manual** lewat tombol (bawaan). Simulasi dan penerapan memakai logika yang sama; tidak ada data yang dihapus — siswa yang keluar/hilang di Pusat hanya diberi status `keluar`. Pengaman: Pusat kosong atau penonaktifan massal (> 20% / 10 siswa) ditolak kecuali dicentang *tetap terapkan*. |
| Webhook | Hanya **menandai** "ada perubahan menunggu" di halaman Integrasi Pusat. Satu pengecualian demi keamanan: bila akses akun dicabut di Pusat (nonaktif / tanpa peran), akun lokal langsung dinonaktifkan. |
| Sinkron otomatis | Opsional: `PUSAT_SINKRON_OTOMATIS=true` (+ cron `schedule:run`) menjalankan `pusat:sinkron --terapkan` tiap jam. |
| Keluar | Keluar dari Jurnal pada sesi yang masuk lewat Pusat juga keluar dari Pusat. |
| Tidak disinkron | Mapel, pasangan guru–mapel–kelas, wali kelas, absensi, nilai, catatan BK, surat. |

## Perintah

```
php artisan pusat:ekspor [folder]
php artisan pusat:sinkron                # simulasi
php artisan pusat:sinkron --terapkan [--paksa]
```

## Bila ada masalah

- *Pusat tidak dapat dihubungi* → pakai login biasa; Jurnal tetap berfungsi penuh tanpa Pusat.
- *Akun dinonaktifkan di Jurnal* setelah akses dicabut di Pusat lalu diberikan lagi → aktifkan manual di Admin → Akun Guru.
- Matikan seluruh penyambungan: `PUSAT_ENABLED=false` (data yang sudah ditautkan tetap utuh).
