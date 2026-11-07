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
3. **Isi `.env` Jurnal**, lalu `php artisan config:clear`:
   ```
   PUSAT_ENABLED=true
   PUSAT_URL=https://pusat.sekolah.example
   PUSAT_CLIENT_ID=...
   PUSAT_CLIENT_SECRET=...
   PUSAT_WEBHOOK_SECRET=...
   ```
   Di balik proxy HTTPS, isi juga `TRUSTED_PROXIES` agar `redirect_uri` bersekema `https://` dan cocok dengan yang didaftarkan.
4. **Admin → Integrasi Pusat**: *Uji Koneksi* → *Periksa Perubahan (simulasi)* → baca hasilnya → *Terapkan*.
   Pada penerapan pertama, kelas dan siswa lama **ditautkan** ke data Pusat lewat nama kelas / NIS (tidak membuat ganda).
   Nama kembar atau NIS kembar dilewati dan dilaporkan.
5. **Coba login** dengan satu akun guru lewat tombol *Masuk lewat Pusat* (jendela lain tetap login lokal sebagai cadangan).

## Cara kerja

| Hal | Perilaku |
|---|---|
| Login | Tombol *Masuk lewat Pusat* → Pusat → kembali ke `/sso/callback`. Akun dicocokkan lewat UUID Pusat; **pertama kali** lewat username yang sama. Bila belum ada akun lokal, dibuat otomatis (peran dari Pusat, tanpa mapel/kelas — atur di Admin → Akun Guru). |
| Peran | Dari Pusat dipetakan: `guru, bk, tu, kepsek, admin, mgmp`. `wali_kelas` dan lainnya diabaikan — wali kelas & pasangan mapel tetap diatur di Jurnal. Peran `superadmin` **tidak pernah** diubah oleh Pusat. Peran diselaraskan tiap login. |
| Data induk | **Manual** lewat tombol (bawaan). Simulasi dan penerapan memakai logika yang sama; tidak ada data yang dihapus — siswa yang keluar/hilang di Pusat hanya diberi status `keluar`. Pengaman: Pusat kosong atau penonaktifan massal (> 20% / 10 siswa) ditolak kecuali dicentang *tetap terapkan*. |
| Webhook | Menandai "ada perubahan menunggu", lalu penjadwal menjalankan `pusat:sinkron --terapkan` sendiri dalam ±1 menit (admin tidak perlu menekan Sinkron). Event `sinkron.diminta` (tombol **Sinkronkan** di Pusat → Aplikasi, otomatis juga setelah impor/kenaikan kelas di Pusat) memicu hal yang sama. Akun yang dicabut di Pusat langsung dinonaktifkan. |
| Sinkron otomatis | Bawaan nyala (`PUSAT_SINKRON_OTOMATIS=true`, cron `schedule:run` dipasang install.sh): `pusat:sinkron --terapkan` tiap jam. Akun baru di Pusat juga langsung dibuat lewat webhook, jadi bisa login cadangan saat Pusat mati. |
| Keluar | Keluar dari Jurnal pada sesi yang masuk lewat Pusat juga keluar dari Pusat. |
| Tidak disinkron | Mapel, pasangan guru–mapel–kelas, wali kelas, absensi, nilai, jurnal mengajar, perangkat ajar. |

## Perintah

```
php artisan pusat:sinkron                # simulasi
php artisan pusat:sinkron --terapkan [--paksa]
```

## Bila ada masalah

- *Pusat tidak dapat dihubungi* → pakai login biasa; Jurnal tetap berfungsi penuh tanpa Pusat.
- *Akun dinonaktifkan di Jurnal* setelah akses dicabut di Pusat lalu diberikan lagi → aktifkan manual di Admin → Akun Guru.
- Matikan seluruh penyambungan: `PUSAT_ENABLED=false` (data yang sudah ditautkan tetap utuh).
