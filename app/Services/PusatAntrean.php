<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean kiriman ke Pusat. Bila Pusat tidak bisa dihubungi, perubahan tetap disimpan di Absensi dan diantrekan di sini,
 * lalu dikirim otomatis (setelah request selesai paling sering tiap 2 menit, tiap 5 menit lewat cron, dan saat Sinkron).
 * Satu baris per (jenis, kunci): perubahan berikutnya untuk siswa yang sama menimpa yang belum terkirim.
 */
final class PusatAntrean
{
    public static function siap(): bool
    {
        static $ada = null;

        return $ada ??= Schema::hasTable('pusat_antrean');
    }

    public static function antre(string $jenis, string $kunci, array $data, ?string $galat = null): void
    {
        $now = now('UTC')->format('Y-m-d H:i:s');
        DB::table('pusat_antrean')->updateOrInsert(['jenis' => $jenis, 'kunci' => $kunci],
            ['data' => json_encode($data), 'percobaan' => 0, 'galat' => $galat ? mb_substr($galat, 0, 500) : null, 'dibuat' => $now, 'dicoba' => null]);
    }

    public static function ada(string $jenis, string $kunci): bool
    {
        return self::siap() && DB::table('pusat_antrean')->where(['jenis' => $jenis, 'kunci' => $kunci])->exists();
    }

    /** @return string[] kunci yang masih menunggu untuk satu jenis */
    public static function kunci(string $jenis): array
    {
        return self::siap() ? DB::table('pusat_antrean')->where('jenis', $jenis)->pluck('kunci')->all() : [];
    }

    public static function jumlah(): int
    {
        return self::siap() ? DB::table('pusat_antrean')->count() : 0;
    }

    /** @return array<int,object> */
    public static function daftar(int $batas = 20): array
    {
        return self::siap() ? DB::table('pusat_antrean')->orderBy('id')->limit($batas)->get()->all() : [];
    }

    /** Coba kirim semua; berhenti di kegagalan jaringan pertama (Pusat masih mati). @return array{terkirim:int, sisa:int} */
    public static function kirim(): array
    {
        $h = ['terkirim' => 0, 'sisa' => 0];
        if (! self::siap() || ! PusatKlien::aktif()) {
            return $h;
        }
        foreach (DB::table('pusat_antrean')->orderBy('id')->get() as $b) {
            $data = (array) json_decode((string) $b->data, true);
            $hasil = match ($b->jenis) {
                'kontak' => PusatKlien::kirimKontak((string) $b->kunci, $data),
                default => 'ditolak:jenis tidak dikenal',
            };
            if ($hasil === 'ok') {
                DB::table('pusat_antrean')->where('id', $b->id)->delete();
                $h['terkirim']++;

                continue;
            }
            DB::table('pusat_antrean')->where('id', $b->id)->update([
                'percobaan' => $b->percobaan + 1, 'dicoba' => now('UTC')->format('Y-m-d H:i:s'),
                'galat' => mb_substr($hasil === 'mati' ? 'Pusat belum bisa dihubungi' : substr($hasil, 9), 0, 500),
            ]);
            if ($hasil === 'mati') {
                break; // Pusat masih mati: tidak perlu mencoba sisanya sekarang
            }
        }
        $h['sisa'] = self::jumlah();
        if ($h['terkirim']) {
            Audit::log('Antrean Pusat', 'Integrasi Pusat', null, $h['terkirim'].' perubahan tertunda terkirim ke Pusat');
        }

        return $h;
    }

    /** Dipanggil setelah respons terkirim: coba antrean paling sering tiap 2 menit (tanpa perlu cron). */
    public static function kirimBilaPerlu(): void
    {
        try {
            if (self::jumlah() > 0 && Cache::add('pusat_antrean_coba', 1, 120)) {
                self::kirim();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
