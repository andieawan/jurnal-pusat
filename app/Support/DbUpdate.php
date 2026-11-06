<?php

namespace App\Support;

/** Deteksi & jalankan migrasi yang belum terpasang (untuk hosting tanpa SSH, dari Admin → Pengaturan). */
class DbUpdate
{
    /** @return string[] nama migrasi yang belum dijalankan */
    public static function pending(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return [];
        }
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff($files, $ran));
    }

    public static function run(): void
    {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        foreach (['view:clear', 'route:clear', 'config:clear'] as $c) {
            try {
                \Illuminate\Support\Facades\Artisan::call($c);
            } catch (\Throwable) {
            }
        }
    }

    /** Kolom kontak orang tua sudah ada di tabel siswa. */
    public static function parentContactReady(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('students', 'telp_ortu');
    }

    /** Tautan info kehadiran kelas untuk wali murid siap dipakai. */
    public static function boardReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasTable('class_board_tokens');
        }

        return $ok;
    }

    /** Kunci API (integrasi aplikasi lain) siap dipakai. */
    public static function apiReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasTable('api_keys');
        }

        return $ok;
    }

    /** Kebijakan password (kolom pw_* di pengaturan sekolah) sudah ada. */
    public static function pwReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasColumn('school_settings', 'pw_mode');
        }

        return $ok;
    }

    /** Daftar jurusan (tabel `jurusan`) siap dipakai. */
    public static function jurusanReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasTable('jurusan');
        }

        return $ok;
    }

    /** Ambang "Perlu Perhatian" (kolom att_* di pengaturan sekolah) sudah ada. */
    public static function attReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasColumn('school_settings', 'att_alpa');
        }

        return $ok;
    }
}
