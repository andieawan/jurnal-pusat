<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use App\Models\ApiKey;
use App\Support\DbUpdate;
use RuntimeException;

/**
 * Sambungan antar-aplikasi yang diatur di Pusat (Administrasi → Sambungan). Kunci & alamat diambil dari
 * GET /api/v1/sambungan, disimpan terenkripsi di storage, lalu menimpa nilai .env. Sambungan yang belum
 * pernah diatur dari Pusat tidak menyentuh .env.
 */
class Sambungan
{
    public static function berkas(): string
    {
        return storage_path('app/sambungan-pusat.json');
    }

    /** @return array<string,array{aktif:bool,peran:string,mitra:?string,url:string,kunci:string}> jenis => data */
    public static function semua(): array
    {
        $f = self::berkas();
        if (! is_file($f)) {
            return [];
        }
        try {
            $d = json_decode(Crypt::decryptString((string) file_get_contents($f)), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($d) ? $d : [];
    }

    /** Ambil dari Pusat, simpan, terapkan. Melempar RuntimeException bila Pusat gagal. */
    public static function tarik(): array
    {
        if (! PusatKlien::aktif()) {
            return [];
        }
        try {
            $res = PusatKlien::http()->get('/api/v1/sambungan');
        } catch (\Throwable $e) {
            throw new RuntimeException('Pusat tidak dapat dihubungi untuk mengambil sambungan.', 0, $e);
        }
        if ($res->status() === 404) {
            return self::semua(); // Pusat versi lama: belum punya menu Sambungan
        }
        if (! $res->successful()) {
            throw new RuntimeException('Pusat menolak permintaan sambungan (HTTP '.$res->status().').');
        }
        $data = [];
        foreach ((array) $res->json('data', []) as $s) {
            if (! is_array($s) || ! is_string($s['jenis'] ?? null) || ! preg_match('/^[a-z0-9-]{1,30}$/', $s['jenis'])) {
                continue;
            }
            $url = rtrim((string) ($s['url'] ?? ''), '/');
            $data[$s['jenis']] = [
                'aktif' => (bool) ($s['aktif'] ?? false),
                'peran' => (string) ($s['peran'] ?? ''),
                'mitra' => isset($s['mitra']) ? (string) $s['mitra'] : null,
                'url' => preg_match('#^https?://#i', $url) ? $url : '',
                'kunci' => (string) ($s['kunci'] ?? ''),
            ];
        }
        $f = self::berkas();
        @mkdir(dirname($f), 0770, true);
        if (file_put_contents($f.'.tmp', Crypt::encryptString((string) json_encode($data))) === false || ! rename($f.'.tmp', $f)) {
            throw new RuntimeException('Tidak bisa menyimpan sambungan ke '.$f.'.');
        }
        @chmod($f, 0640);
        self::terapkan();
        self::kunciBk($data['absensi-bk'] ?? null);

        return $data;
    }

    /** Untuk sinkron: kegagalan dicatat saja, tidak menggagalkan sinkron data induk. */
    public static function tarikAman(): void
    {
        try {
            self::tarik();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Daftar sambungan aktif untuk ditampilkan: jenis => mitra. */
    public static function aktif(): array
    {
        return collect(self::semua())->filter(fn ($s) => $s['aktif'])->map(fn ($s) => (string) $s['mitra'])->all();
    }

    public const NAMA_KUNCI_BK = 'BK (Sambungan Pusat)';

    /** Sambungan Absensi → BK: kunci dari Pusat dicatat sebagai Kunci API berizin kehadiran (hash saja). */
    public static function kunciBk(?array $s): void
    {
        if ($s === null || ! DbUpdate::apiReady()) {
            return;
        }
        $key = ApiKey::where('name', self::NAMA_KUNCI_BK)->first();
        if (! $s['aktif'] || strlen($s['kunci']) < 16) {
            $key?->update(['is_active' => false]);

            return;
        }
        $isi = ['key_prefix' => substr($s['kunci'], 0, 11), 'key_hash' => ApiKey::hashOf($s['kunci']), 'scopes' => ['kehadiran:baca', 'kehadiran:tulis'], 'is_active' => true];
        if ($key) {
            $key->update($isi);
        } else {
            ApiKey::create($isi + ['name' => self::NAMA_KUNCI_BK, 'rate_limit' => 600, 'created_by' => null, 'created_at' => now('UTC')->format('Y-m-d H:i:s')]);
        }
    }

    /** Timpa config dari sambungan Pusat (dipanggil saat boot). */
    public static function terapkan(): void
    {
        $s = self::semua();
        // Absensi tidak memakai .env untuk sambungan: kunci BK disimpan sebagai Kunci API (lihat kunciBk()).
    }
}
