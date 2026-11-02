<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Profil sekolah dari Pusat (Administrasi → Profil Sekolah): nama, kop surat, kepala sekolah, logo, guru BK.
 * Diambil lewat GET /api/v1/profil saat webhook profil.diperbarui dan setiap sinkron, disimpan di storage.
 * Isian yang kosong di Pusat tidak menimpa pengaturan aplikasi ini.
 */
class ProfilPusat
{
    public static function berkas(): string
    {
        return storage_path('app/profil-pusat.json');
    }

    public static function logoBerkas(): string
    {
        return storage_path('app/profil-logo');
    }

    public static function semua(): array
    {
        $f = self::berkas();
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

        return is_array($d) ? $d : [];
    }

    /** Nilai profil yang terisi di Pusat, atau null. */
    public static function nilai(string $kunci): ?string
    {
        $v = self::semua()[$kunci] ?? null;

        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    /** Profil sudah diisi di Pusat (kolom terkait dikunci di aplikasi ini). */
    public static function aktif(): bool
    {
        return ! empty(self::semua()['diisi']);
    }

    public static function adaLogo(): bool
    {
        return is_file(self::logoBerkas());
    }

    public static function tarik(): array
    {
        if (! PusatKlien::aktif()) {
            return [];
        }
        try {
            $res = PusatKlien::http()->get('/api/v1/profil');
        } catch (\Throwable $e) {
            throw new RuntimeException('Pusat tidak dapat dihubungi untuk mengambil profil sekolah.', 0, $e);
        }
        if ($res->status() === 404) {
            return self::semua(); // Pusat versi lama
        }
        if (! $res->successful() || ! is_array($d = $res->json('data'))) {
            throw new RuntimeException('Pusat menolak permintaan profil sekolah (HTTP '.$res->status().').');
        }
        $lama = self::semua();
        if (empty($d['logo_url'])) {
            @unlink(self::logoBerkas());
        } elseif (($d['logo_versi'] ?? null) !== ($lama['logo_versi'] ?? null) || ! self::adaLogo()) {
            try {
                $img = Http::withOptions(['verify' => config('pusat.ca_bundle') ?: (bool) config('pusat.verify_ssl')])->timeout(15)->get((string) $d['logo_url']);
                if ($img->successful() && str_starts_with((string) $img->header('Content-Type'), 'image/') && strlen($img->body()) < 3 * 1024 * 1024) {
                    file_put_contents(self::logoBerkas(), $img->body());
                }
            } catch (\Throwable $e) {
                report($e); // logo gagal diunduh: logo lama tetap dipakai
            }
        }
        @mkdir(dirname(self::berkas()), 0770, true);
        file_put_contents(self::berkas().'.tmp', (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename(self::berkas().'.tmp', self::berkas());
        self::terapkan();
        self::setelahTarik($d);

        return $d;
    }

    public static function tarikAman(): void
    {
        try {
            self::tarik();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Timpa config dari profil Pusat (dipanggil saat boot). */
    public static function terapkan(): void
    {
        // Absensi menyimpan profil di pengaturan sekolah (lihat setelahTarik).
    }

    private static function setelahTarik(array $d): void
    {
        $isi = array_filter(['school_name' => $d['nama'] ?? null, 'kepsek_nama' => $d['kepsek'] ?? null,
            'bk_nama' => ($d['guru_bk'][0] ?? null)], fn ($v) => is_string($v) && trim($v) !== '');
        if ($isi) {
            $set = \App\Models\SchoolSetting::current();
            if (array_diff_assoc($isi, array_intersect_key($set->toArray(), $isi))) {
                \App\Models\SchoolSetting::put($isi);
            }
        }
    }
}
