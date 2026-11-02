<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;

/**
 * Mengeluarkan data Absensi dalam format CSV impor Pusat (pengisian awal Pusat).
 * Urutan impor di Pusat: guru → kelas → siswa; lalu `php artisan pusat:impor-akun akun.csv` di Pusat.
 * Guru di Absensi tidak punya NIP, jadi `username` dipakai sebagai ID pegawai sementara
 * (ganti dengan NIP/NUPTK lewat menu Guru & Staf di Pusat bila perlu).
 */
class PusatEkspor
{
    private const PERAN = ['guru' => 'guru', 'bk' => 'bk', 'tu' => 'tu', 'kepsek' => 'kepsek', 'admin' => 'admin', 'superadmin' => 'admin'];

    /** @return array<string,int> nama berkas => jumlah baris */
    public static function tulis(string $dir): array
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $users = User::orderBy('nama')->get();
        $kelas = SchoolClass::orderBy('name')->get();
        $namaKelas = $kelas->pluck('name', 'id');
        $username = $users->pluck('username', 'id');

        $guru = $users->map(fn ($u) => [
            strtolower($u->username), $u->nama, in_array('tu', $u->roles ?? [], true) && ! in_array('guru', $u->roles ?? [], true) ? 'staf' : 'guru', $u->roleLabel(), '', '',
        ])->all();

        $akun = $users->map(function ($u) {
            $peran = [];
            foreach ($u->roles ?? [] as $r) {
                if (isset(self::PERAN[$r])) {
                    $peran[] = self::PERAN[$r];
                }
            }
            if ($u->kelas_wali_id) {
                $peran[] = 'wali_kelas';
            }

            return [strtolower($u->username), $u->nama, '', strtolower($u->username), implode('|', array_values(array_unique($peran ?: ['guru'])))];
        })->all();

        $wali = $users->whereNotNull('kelas_wali_id')->mapWithKeys(fn ($u) => [$u->kelas_wali_id => strtolower($u->username)]);
        $kelasBaris = $kelas->map(fn ($c) => [
            $c->name, self::tingkat($c->name), $c->jurusan ?? '', $c->tahun_ajaran ?? '', $wali[$c->id] ?? '',
        ])->all();

        $siswa = Student::orderBy('class_id')->orderBy('nama')->get()->map(fn ($s) => [
            $s->nis, '', $s->nama, $s->jk, $namaKelas[$s->class_id] ?? '', self::status($s->status), '', '', '', $s->telp_ortu ?? '', $s->nama_ortu ?? '',
        ])->all();

        return [
            self::csv("$dir/1-guru.csv", ['nip', 'nama', 'jenis', 'jabatan', 'no_hp', 'email'], $guru),
            self::csv("$dir/2-kelas.csv", ['nama', 'tingkat', 'jurusan', 'tahun_ajaran', 'wali'], $kelasBaris),
            self::csv("$dir/3-siswa.csv", ['nis', 'nisn', 'nama', 'jenis_kelamin', 'kelas', 'status', 'tempat_lahir', 'tanggal_lahir', 'no_hp', 'no_hp_ortu', 'nama_ortu'], $siswa),
            self::csv("$dir/4-akun.csv", ['username', 'nama', 'email', 'nip', 'peran'], $akun),
        ];
    }

    public static function tingkat(string $nama): string
    {
        return preg_match('/^\s*(XII|XI|X)\b/i', $nama, $m) ? strtoupper($m[1]) : '';
    }

    public static function status(?string $s): string
    {
        return match ($s) {
            'lulus' => 'lulus', 'pindah' => 'pindah', 'aktif' => 'aktif', default => 'keluar', // berhenti/nonaktif/keluar
        };
    }

    private static function csv(string $path, array $header, array $baris): array
    {
        $h = fopen($path, 'w');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $header, ';', '"', '');
        foreach ($baris as $b) {
            fputcsv($h, $b, ';', '"', '');
        }
        fclose($h);

        return [basename($path) => count($baris)];
    }
}
