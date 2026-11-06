<?php

use App\Models\User;
use App\Services\AdminService;
use App\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('absensi:backup', function () {
    $r = BackupService::run();
    $r['success'] ? $this->info('Backup dibuat: '.$r['file']) : $this->error($r['error']);

    return $r['success'] ? 0 : 1;
})->purpose('Buat backup database sekarang (MySQL: dump SQL; SQLite: salinan file)');

Artisan::command('absensi:admin {username} {--nama=Administrator}', function (string $username) {
    $username = strtolower(trim($username));
    if (User::whereRaw('LOWER(username) = ?', [$username])->exists()) {
        $this->error("Username $username sudah ada.");

        return 1;
    }
    $pw = $this->secret('Password (min. 10 karakter)');
    if (strlen((string) $pw) < 10) {
        $this->error('Password minimal 10 karakter.');

        return 1;
    }
    config(['absensi.admin.nama' => $this->option('nama')]);
    AdminService::createAdmin($username, $pw);
    $this->info("Akun Administrator $username dibuat.");
})->purpose('Buat akun Administrator (superadmin)');

// Backup harian — aktif bila cron menjalankan "php artisan schedule:run" tiap menit.
Schedule::command('absensi:backup')->dailyAt('02:00')->timezone(config('absensi.timezone'));


// ---- Penyambungan ke Pusat Sekolah (lihat docs/PUSAT.md) ----
Artisan::command('pusat:sinkron {--terapkan : Tulis perubahan (tanpa ini hanya simulasi)} {--paksa : Izinkan penonaktifan siswa dalam jumlah besar}', function () {
    if (! \App\Services\PusatKlien::aktif() || ! \App\Services\PusatKlien::siap()) {
        $this->error('Integrasi Pusat belum aktif (cek .env) atau migrasi belum dijalankan.');

        return 1;
    }
    try {
        $h = app(\App\Services\PusatSync::class)->jalankan((bool) $this->option('terapkan'), (bool) $this->option('paksa'));
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->line(sprintf('Kelas: +%d ~%d =%d | Siswa: +%d ~%d =%d (nonaktif %d)', $h['kelas']['baru'], $h['kelas']['ubah'], $h['kelas']['sama'], $h['siswa']['baru'], $h['siswa']['ubah'], $h['siswa']['sama'], $h['siswa']['nonaktif']));
    foreach ($h['peringatan'] as $w) {
        $this->warn($w);
    }
    foreach ($h['contoh'] as $c) {
        $this->line(' - '.$c);
    }
    $this->info($h['diterapkan'] ? 'Diterapkan.' : 'Simulasi saja. Tambahkan --terapkan untuk menulis.');

    return 0;
})->purpose('Tarik data induk (kelas & siswa) dari Pusat');

// Antrean ke Pusat (mis. kontak orang tua saat Pusat mati) dicoba tiap 5 menit bila cron schedule:run terpasang.
if (config('pusat.enabled')) {
    Schedule::call(fn () => \App\Services\PusatAntrean::kirim())->name('pusat-antrean')->everyFiveMinutes()->withoutOverlapping();
}

// Sinkron otomatis tiap jam (PUSAT_SINKRON_OTOMATIS, bawaan nyala; isi false untuk sinkron lewat tombol Admin saja).
if (config('pusat.enabled') && config('pusat.sinkron_otomatis')) {
    Schedule::command('pusat:sinkron --terapkan')->hourly()->withoutOverlapping();
}
