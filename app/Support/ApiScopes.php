<?php

namespace App\Support;

/** Daftar izin (scope) kunci API. Data sensitif (catatan BK, nilai) tidak dibuka; satu-satunya izin tulis: kehadiran:tulis. */
class ApiScopes
{
    public const ALL = [
        'kelas:baca' => 'Baca daftar kelas',
        'mapel:baca' => 'Baca daftar mata pelajaran',
        'siswa:baca' => 'Baca data siswa (NIS, nama, JK, kelas, status)',
        'siswa:kontak' => 'Baca nama & No. HP orang tua (data pribadi — beri hanya bila perlu)',
        'kehadiran:baca' => 'Baca data kehadiran & rekap per siswa',
        'kehadiran:tulis' => 'Tulis: input absensi manual per siswa & kelola tautan delegasi Ketua Kelas (khusus aplikasi BK)',
    ];

    public static function valid(array $scopes): array
    {
        return array_values(array_intersect(array_keys(self::ALL), $scopes));
    }
}
