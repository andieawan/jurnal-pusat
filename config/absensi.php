<?php

// Konfigurasi Jurnal Guru (nama file tetap "absensi" dari aplikasi asalnya; lihat README).
return [
    'school_name' => env('SCHOOL_NAME', 'SMK Islam Bustanul Ulum Pakusari'),
    'timezone' => env('ABSENSI_TIMEZONE', 'Asia/Jakarta'), // untuk "hari ini" & tanggal surat; data disimpan UTC
    'auto_backup' => (bool) env('ABSENSI_AUTO_BACKUP', true),
    'backup_dir' => env('ABSENSI_BACKUP_DIR', storage_path('app/backups')),
    'admin' => [
        'username' => env('ADMIN_USERNAME', ''),
        'password' => env('ADMIN_PASSWORD', ''),
        'nama' => env('ADMIN_NAMA', 'Administrator'),
    ],
    'trust_proxies' => (bool) env('TRUST_PROXIES', false),
    'edit_window_days' => 7,     // batas ubah/hapus non-admin
    'min_attendance' => 85.0,    // syarat kehadiran minimal (%)
    // Identitas sekolah pada kop dokumen cetak (bisa diubah lewat .env)
    'surat' => [
        'yayasan' => env('SURAT_YAYASAN', 'YAYASAN PENDIDIKAN ISLAM'),
        'yayasan2' => env('SURAT_YAYASAN2', '“ BUSTANUL ULUM ”'),
        'nama' => env('SURAT_NAMA', 'SMK ISLAM BUSTANUL ULUM PAKUSARI'),
        'nss' => env('SURAT_NSS', '342052423288'),
        'npsn' => env('SURAT_NPSN', '20570966'),
        'bidang' => env('SURAT_BIDANG', 'Kelompok Bisnis Manajemen dan Teknologi Informasi Komunikasi'),
        'alamat' => env('SURAT_ALAMAT', 'Jl. Himalaya No. 17 Telp. (0331) 7255753 Kode Pos. 68181 Pakusari – Jember'),
        'kota' => env('SURAT_KOTA', 'Pakusari'),
        'kepsek' => env('SURAT_KEPSEK', 'MUHAMMAD MUSLIM, S.Pd., Gr'),
        'kepsek_nuptk' => env('SURAT_KEPSEK_NUPTK', '7951767668130072'),
    ],
    // Asisten AI untuk menu Perangkat Ajar (Google Gemini). Kosong = guru tetap bisa membuat draf dari templat.
    'ai' => [
        'key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 50),
        // Batas dokumen yang diisi AI per mapel (MGMP) per semester untuk kunci sekolah / kunci MGMP. 0 = tanpa batas.
        'kuota' => (int) env('AI_KUOTA_MGMP', 0),
        // Batas dokumen AI per akun guru per semester untuk perangkat miliknya sendiri. 0 = tanpa batas.
        'kuota_guru' => (int) env('AI_KUOTA_GURU', 0),
    ],
    'bk_subject_id' => 5,        // mapel "Bimbingan Konseling" disembunyikan dari daftar mapel reguler
];
