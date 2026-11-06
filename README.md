# Jurnal Guru (jurnal-pusat)

Aplikasi kehadiran sekolah, pengganti Absensi Siswa: **Jurnal Guru**, satu halaman **Mengajar** per pertemuan berisi jurnal (materi, kegiatan, catatan, rencana berikutnya) dan absensi mapel. Fitur absensi, nilai, dan integrasi Pusat Sekolah tetap sama.

Pemasangan di VM baru: [`docs/PASANG-VM-JURNAL.md`](docs/PASANG-VM-JURNAL.md).


> **Produksi:** urutan lengkap Pusat → aplikasi ada di [runbook Pusat](https://github.com/andieawan/pusat-sekolah/blob/main/docs/PRODUKSI.md). Cek kesiapan server kapan saja: `php artisan absensi:periksa`.

Dibangun dengan **Laravel 13** (PHP 8.3+). Halaman dirender di server dengan Blade; JavaScript hanya sedikit
(tanpa npm/Vite, tanpa langkah build). Database MariaDB/MySQL (produksi) atau SQLite (tes).

## Catatan untuk pengembang: nama "absensi" di dalam kode
Jurnal Guru adalah kelanjutan aplikasi **Absensi Siswa**. Supaya data, backup, dan skrip lama tetap cocok, beberapa nama
internal sengaja **tidak diganti**. Ini bukan kesalahan:

| Yang masih bernama "absensi" | Alasan |
|---|---|
| `config/absensi.php`, `config('absensi.*')` | pengaturan aplikasi; mengganti nama berarti mengubah ratusan pemanggilan |
| perintah `absensi:backup`, `absensi:admin`, `absensi:periksa`, `absensi:kosongkan-data` | dipakai cron, `deploy/*.sh`, dan panduan |
| berkas backup `storage/app/backups/absensi_*.sql` | dikenali `deploy/restore.sh` dan aplikasi Backup Master |
| `deploy/nginx-absensi.conf` | contoh konfigurasi Nginx untuk pemasangan manual |

Kata "absensi" di menu/tampilan berarti **fitur** absensi (kehadiran siswa), bukan nama aplikasi.

## Fitur
| Menu | Isi |
|---|---|
| **Login** | Sesi Laravel, batas 10 gagal / 15 menit, **Ganti Akun** (verifikasi ulang password), ganti password (sesi di perangkat lain otomatis keluar) |
| **Dashboard** | Wali Kelas · Per Mapel · Sekolah (Kepsek). KPI H/I/S/A, ringkasan & saran otomatis, tren, pola absen berkala (hari sama, jarak 10–18 hari), daftar "Perlu Perhatian" + sinyal nilai turun. Wali kelas melihat status "sudah/belum diisi hari ini"; Kepsek/BK/Admin melihat "x/y kelas sudah absen" |
| **Pantau** | Kelas mana yang sudah/belum mengisi absen harian pada suatu tanggal (filter "Belum mengisi"), lengkap dengan wali kelas, jumlah H/I/S/A, jam terakhir diisi |
| **Rekap Rapor** | Jumlah Sakit / Izin / Tanpa Keterangan per siswa per semester (absen harian), rentang tanggal bisa diubah, unduh Excel & cetak — siap disalin ke e-Rapor. Wali kelas: kelasnya; BK/Kepsek/Admin: semua kelas |
| **Absensi** | Harian (wali) / per mapel, tombol H·I·S·A + catatan, simpan ulang = update, peringatan 85%, riwayat sesi (hapus ≤ 7 hari), **tautan delegasi Ketua Kelas** (maks. 24 jam), unduh Excel |
| **Nilai** | Input/edit kegiatan (angka 0–100 / huruf A–E), daftar kegiatan (edit/hapus ≤ 7 hari sejak diinput; setelah itu **nilai susulan** tetap bisa diisi untuk siswa yang belum punya nilai). Nilai kosong = belum mengumpulkan (tidak dihitung rata-rata), rekap + rata-rata, Excel, laporan cetak |
| **Riwayat Siswa** | Profil, kontak orang tua + ringkasan via WhatsApp, kehadiran, pola, uji pengesahan 85% (dispensasi Admin/Kepsek), log absen, nilai, **Portal Wali Murid** (buat/cabut) |
| **WhatsApp gratis** | Tombol `wa.me` — tanpa gateway/API berbayar: pesan disiapkan aplikasi, WhatsApp di HP/laptop guru terbuka, guru tinggal menekan kirim. Ada di halaman Absensi ("Kabari Orang Tua" untuk siswa I/S/A), Riwayat Siswa, dan tautan ketua kelas/portal wali |
| **Admin Panel** | Akun guru · Data siswa (+ nama & No. HP orang tua, impor xlsx/csv) · Kelas & Mapel · Impor Data (template Excel, termasuk riwayat absensi & nilai lama) · Upload Hardcopy · Pasangan Guru–Mapel–Kelas · **Kenaikan Kelas** (naik/tinggal/lulus per siswa + tahun ajaran baru, backup otomatis) · Log Aktivitas · Pengaturan & Backup |
| **Publik** | `/presensi/{token}` (Ketua Kelas, tanpa login) dan `/wali/{token}` (Wali Murid, baca-saja). Tautan lama `/?token=` & `/?wali=` tetap berfungsi |

Aturan bisnis: batas ubah/hapus 7 hari (non-admin; absensi dihitung dari tanggal sesi, nilai dari waktu input), satu kelas satu wali kelas, syarat kehadiran 85%, otorisasi guru (wali kelas untuk absen harian;
pasangan atau kelas+mapel yang diampu untuk absen mapel/nilai), token delegasi ≤ 24 jam, audit log 500 baris terakhir.

**BK dan TU adalah aplikasi sendiri.** Catatan BK, surat siswa, register surat, mutasi, dan absensi guru/staf ada di
aplikasi BK dan TU (dibuka lewat menu akun → *Pindah ke aplikasi lain*). Aplikasi BK membaca dan menulis kehadiran
siswa ke Jurnal lewat API (lihat [docs/API.md](docs/API.md)).

**Pilih mapel dulu, lalu kelas.** Di form Absensi (mode mapel) & Nilai, guru memilih **Mata Pelajaran** (hanya mapel yang
diajarnya), lalu daftar **Kelas** hanya berisi kelas tempat ia mengajar mapel itu. Bila mapelnya berbeda tiap kelas
(mis. B. Indonesia di X DKV 1, B. Daerah di X DKV 2), atur di Admin → **Pasangan Mapel**; mapel/kelas yang dicentang di
akun guru berlaku silang (semua mapel di semua kelas itu).

**Riwayat Siswa dibatasi.** Guru mapel hanya melihat kelas & siswa yang diajarnya (plus kelas perwaliannya); membuka siswa
kelas lain ditolak (403). Admin, Kepala Sekolah, BK, dan TU tetap melihat semua kelas.

**Info Kehadiran untuk Wali Murid (Absensi → Riwayat, mode Wali Kelas).** Wali kelas membuat satu tautan tetap per kelas lalu mengirimnya ke grup WhatsApp wali murid (tombol *Kirim via WhatsApp*). Di halaman publik `/kelas/…` orang tua mengisi **NIS ananda + 4 digit terakhir No. HP orang tua yang terdaftar**, lalu hanya melihat kehadiran anaknya sendiri (absen harian wali kelas, 14 hari terakhir); daftar siswa lain tidak pernah tampil. Pesan gagal dibuat sama untuk NIS/PIN salah (tidak membocorkan data) dan dibatasi 8 percobaan per menit. Siswa tanpa No. HP orang tua tidak bisa dicek (isi dulu di data siswa). Tautan bisa dicabut kapan saja. Perlu *Perbarui Database Sekarang* (atau `php artisan migrate --force`) sekali.

**Hapus data salah input (Admin).** Tombol **Hapus** tersedia di Data Siswa (satuan, centang banyak, atau semua siswa satu kelas), Kelas & Mapel, dan Akun Guru. Aman: hanya data yang **belum punya riwayat** (presensi, nilai, jurnal) yang terhapus; yang sudah punya riwayat ditolak dengan alasan — ubah statusnya menjadi Nonaktif/Pindah/Keluar, atau nonaktifkan akun guru. Kelas hanya bisa dihapus bila kosong; mapel BK, akun sendiri, dan satu-satunya Administrator tidak bisa dihapus. Hapus massal membuat cadangan otomatis dan semuanya tercatat di Log Aktivitas.

**Aturan password (Admin → Pengaturan → Aturan Password Pengguna).** Pilih *Sesuai aturan* (panjang minimal 1–64, plus wajib huruf / angka / simbol sesuai centang) atau *Bebas* (asal tidak kosong). Berlaku untuk akun guru baru, reset, ganti password sendiri, dan impor guru. Perlu klik *Perbarui Database Sekarang* (atau `php artisan migrate --force`) sekali setelah unggah versi ini; sebelum itu aturan tetap minimal 8 karakter.

**Impor Data (Admin → Impor Data).** Unduh template Excel lalu unggah untuk memasukkan **Kelas, Mapel, Guru & Staf, Siswa,
Pasangan Mapel, Riwayat Absensi, dan Riwayat Nilai** sekaligus. Bila Jurnal tersambung ke Pusat, data induk (kelas, guru,
siswa) diisi di Pusat; impor riwayat absensi & nilai tetap di sini. Urutan yang disarankan: Kelas → Mapel → Guru & Staf → Siswa → Pasangan Mapel. Centang
"Cek dulu" untuk melihat hasilnya tanpa menyimpan; baris bermasalah dilewati dan dijelaskan alasannya. Akun guru tanpa
kolom Password dibuatkan password acak yang ditampilkan sekali. Peran admin tidak bisa diimpor. Impor Siswa menerima kolom opsional **Status** (aktif/nonaktif/pindah/…); spasi ganda pada nama dirapikan otomatis.

**Keamanan (audit Okt 2026).** Dashboard guru dibatasi ke kelas & mapel yang diajarnya (hanya Kepsek/BK/Admin melihat semua
kelas); pengesahan akademik hanya oleh Wali Kelas siswa, BK, Kepsek, atau Admin; upload berkas divalidasi jenisnya
(JPG/PNG/WEBP/HEIC/PDF); header Content-Security-Policy dasar aktif.

## Struktur kode
```
app/
├─ Http/Controllers/        Mengajar (jurnal + absensi mapel), PerangkatAjar, Dashboard, Attendance, Grade, Student,
│                           Monitor, Recap, Letter (laporan cetak), Export, Public, Auth, PusatSso (login lewat Pusat)
│  └─ Admin/                Teacher, Student, Master (kelas & mapel), Pairing, Hardcopy, Import, Promotion, Log, Setting
├─ Models/                  User, SchoolClass, Subject, Student, Attendance, GradeActivity, GradeValue, Pairing, …
├─ Services/                Rules (aturan bisnis), Analytics, AttendanceService, GradeService, AccessService,
│                           AdminService, BackupService, Sequence, Audit, Assignments, SchoolReports, ImportService,
│                           Perangkat & PerangkatAi & BahanAjar (perangkat ajar), PusatKlien & PusatSync (sambungan ke Pusat)
└─ Support/                 Dates, Passwords, Xlsx (baca/tulis .xlsx tanpa library luar), WhatsApp, TahunAjaran
config/absensi.php          pengaturan khusus aplikasi
database/migrations/        skema (tabel yang sudah ada dilewati → DB versi lama langsung terpakai)
database/seeders/           DatabaseSeeder (admin pertama), DemoSeeder (data contoh)
resources/views/            Blade: layouts, dashboard, attendance, grades, students, mengajar, admin, public, letters
routes/web.php, console.php rute web; perintah absensi:backup, absensi:admin; jadwal backup harian
tests/                      Tes PHPUnit (fitur & aturan bisnis)
```

## Pemasangan
- **VM sekolah (disarankan):** [`docs/PASANG-VM-JURNAL.md`](docs/PASANG-VM-JURNAL.md) — satu skrip `deploy/install.sh`.
- **Manual di Ubuntu** (tanpa skrip): [`docs/UBUNTU.md`](docs/UBUNTU.md).
- **Coba di komputer Windows:** [`docs/PENYIAPAN-LOKAL.md`](docs/PENYIAPAN-LOKAL.md).
- Pembaruan: `sudo bash deploy/update.sh`. Folder `vendor/` ikut di repo, jadi server tidak perlu `composer install`.

## Aplikasi di HP (PWA)
Aplikasi bisa dipasang ke layar utama HP/laptop seperti aplikasi biasa (ikon sendiri, layar penuh, pintasan "Isi Absensi" & "Input Nilai"):
- **Android / Chrome / Edge:** buka situs → tombol **Pasang Aplikasi** (di halaman login atau menu atas), atau menu ⋮ → *Instal aplikasi*.
- **iPhone / iPad (Safari):** tombol Bagikan → *Tambahkan ke Layar Utama*.

Syarat: situs memakai **HTTPS**. Halaman selalu diambil langsung dari server (data siswa tidak disimpan di HP); bila tidak ada
internet, muncul halaman "Tidak ada koneksi". Nama aplikasi mengikuti nama sekolah di Pengaturan. Setelah mengubah
`public/sw.js`, naikkan `VERSION` di dalamnya agar HP mengambil versi baru.

## Impor siswa
Kolom wajib: **NIS, Nama Siswa, JK, Kelas**. Kolom opsional: **Nama Ortu**, **No HP Ortu** (format bebas: `0812…`,
`+62 812…`, `812…` — disimpan sebagai `62812…`). Nomor orang tua juga bisa diisi/diubah satu per satu di Admin → Data Siswa.

## Akhir tahun ajaran
Admin → **Kenaikan Kelas**: luluskan kelas XII dulu, lalu naikkan XI → XII dan X → XI (hilangkan centang siswa yang
tinggal kelas), kemudian klik **Mulai Tahun Ajaran Baru**. Riwayat absensi dan nilai tetap tersimpan. Setelah itu atur ulang Wali Kelas di Akun Guru bila berganti.

## Perintah berguna
Semua perintah dijalankan **di folder proyek** (yang berisi file `artisan`). Di Windows/envkit: buka terminal proyek lalu ketik
persis seperti di bawah.

**Pertama kali / pasang**
| Perintah | Fungsi |
|---|---|
| `php artisan key:generate` | Buat `APP_KEY` di `.env` (wajib sekali; jika error 500 "No application encryption key") |
| `php artisan migrate --force` | Buat/perbarui tabel database. Aman diulang; dipakai setelah mengganti ke versi baru |
| `php artisan migrate:status` | Lihat migrasi mana yang sudah/belum jalan |
| `php artisan absensi:admin nama.user` | Buat akun Administrator baru (password ditanya, min. 10 karakter) |
| `php artisan db:seed --class=DemoSeeder` | Isi **data contoh** (hanya jika belum ada kelas) — mencetak password akun contoh |
| `php artisan serve` | Jalankan server lokal di `http://127.0.0.1:8000` (untuk mencoba tanpa Apache/Nginx) |

**Sehari-hari**
| Perintah | Fungsi |
|---|---|
| `php artisan absensi:backup` | Backup database sekarang → `storage/app/backups` (MySQL `.sql`, SQLite salinan file) |
| `sudo bash deploy/restore.sh` | **Pulihkan (restore)** database dari backup: pilih berkas, backup pengaman otomatis, lalu impor (ketik `PULIHKAN` untuk konfirmasi) |
| `php artisan down --secret=rahasia` / `php artisan up` | Mode pemeliharaan saat update (admin tetap bisa masuk lewat `/rahasia`) / hidupkan lagi |
| `php artisan route:list` | Daftar semua alamat halaman (berguna untuk memeriksa) |

**Bila tampilan/pengaturan tidak berubah setelah update** (cache lama)
| Perintah | Fungsi |
|---|---|
| `php artisan optimize:clear` | Bersihkan semua cache sekaligus (config, route, view, cache) — coba ini dulu |
| `php artisan config:clear` | Bersihkan cache konfigurasi saja (setelah mengubah `.env`) |
| `php artisan view:clear` | Bersihkan cache tampilan (Blade) |
| `php artisan cache:clear` | Bersihkan cache aplikasi (termasuk hitungan batas login yang terkunci) |

**Pengembangan**
| Perintah | Fungsi |
|---|---|
| `vendor/bin/phpunit` | Jalankan seluruh tes otomatis (SQLite in-memory, tanpa menyentuh data asli) |
| `vendor/bin/phpunit --filter NamaTes` | Jalankan satu tes saja |

Beberapa juga bisa dari browser: *Perbarui database* / *Backup* / *Isi Data Contoh* = Admin → Pengaturan; *Reset password* = Admin → Akun Guru.

> Lupa password Administrator dan tidak ada admin lain? Buat akun baru: `php artisan absensi:admin admin2`.

## Keamanan
CSRF & escaping bawaan Laravel, password bcrypt, sesi dicabut saat password diganti/direset atau akun dinonaktifkan,
pembatasan laju login & halaman publik, token delegasi/wali acak 192-bit, header keamanan (X-Frame-Options, nosniff).
Gunakan **HTTPS** dan `APP_DEBUG=false` di produksi. Di balik Cloudflare/proxy set `TRUST_PROXIES=true`.

## Integrasi API (untuk aplikasi lain)
API v1 hanya-baca dengan kunci per aplikasi: Admin → **Integrasi API**. Scope: `kelas:baca`, `mapel:baca`, `siswa:baca`, `siswa:kontak`, `kehadiran:baca`; batas permintaan per kunci; OpenAPI di `/api/v1/openapi.json`. Panduan lengkap: [docs/API.md](docs/API.md). Nilai & catatan BK tidak diekspos.

## Jurusan
Admin → **Jurusan**: daftar kode + nama jurusan (mis. RPL — Rekayasa Perangkat Lunak), pembuatan kelas sekaligus (tingkat × jurusan × jumlah rombel → `X RPL 1…n`, yang sudah ada dilewati), dan tombol *Daftarkan otomatis* untuk kode jurusan yang sudah dipakai kelas. Di Kelas & Mapel, kolom Jurusan menjadi pilihan dari daftar ini. Butuh *Perbarui Database Sekarang* (tabel `jurusan`).

## Impor data lama (riwayat absensi & nilai)
Admin → Impor Data → **Riwayat Absensi** / **Riwayat Nilai**. Unduh template: lembar *Data* (isi di sini), *Petunjuk*, dan
*Daftar Siswa* (Kelas, NIS, Nama semua siswa aktif, untuk disalin). Kelas, mapel, dan siswa harus sudah ada dulu.

| Template | Satu baris = | Kolom |
|---|---|---|
| Riwayat Absensi | satu siswa pada satu tanggal | Tanggal · Kelas · Mapel (kosong = absen harian) · NIS · Nama Siswa · Status (H/I/S/A) · Catatan · Guru |
| Riwayat Nilai | nilai satu siswa pada satu kegiatan | Kelas · Mapel · Nama Kegiatan · Tanggal · Tipe (angka/huruf) · NIS · Nama Siswa · Nilai · Guru |

Baris nilai dengan Kelas + Mapel + Nama Kegiatan + Tanggal yang sama digabung menjadi satu kegiatan. Absensi/kegiatan yang
sudah ada tidak ditimpa, jadi aman diulang. Guru = username atau nama guru (kosong = atas nama yang mengimpor). Berkas
.xlsx boleh banyak sheet (mis. satu per bulan). Centang *Cek dulu* untuk melihat hasilnya tanpa menyimpan.

## Ambang “Perlu Perhatian”
Admin → Pengaturan → *Ambang “Perlu Perhatian”*: alpa/sakit/izin tinggi bila jumlahnya ≥ ambang (bawaan 2) dan *jarang masuk (gabungan)* bila alpa+izin+sakit ≥ ambang gabungan (bawaan 3). Berlaku di Dashboard, profil siswa, dan info wali. Butuh *Perbarui Database Sekarang*; sebelum itu dipakai bawaan.

**Penyambungan ke Pusat Sekolah (opsional, bawaan mati).** Login tunggal + data induk kelas/siswa dari aplikasi Pusat, sinkron lewat tombol di Admin → *Integrasi Pusat*. Lihat [docs/PUSAT.md](docs/PUSAT.md).
