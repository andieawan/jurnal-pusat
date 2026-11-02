<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Akses HTTP ke Pusat (server-ke-server, Basic client_id:client_secret). */
class PusatKlien
{
    public static function aktif(): bool
    {
        return config('pusat.enabled') && config('pusat.url') !== '' && config('pusat.client_id') !== '' && config('pusat.client_secret') !== '';
    }

    /** Data siswa dikelola Pusat (integrasi aktif): Absensi tidak boleh menambah/mengubah data induk siswa sendiri. */
    public static function kelolaSiswa(): bool
    {
        return self::aktif() && self::siap();
    }

    /** Kolom/tabel penaut sudah ada (migrasi sudah dijalankan). */
    public static function siap(): bool
    {
        return Schema::hasColumn('users', 'pusat_uuid') && Schema::hasTable('pusat_state');
    }

    /**
     * Kontak orang tua diubah di Absensi → diteruskan ke Pusat (izin "siswa_kontak") agar semua aplikasi memakai nomor yang sama.
     * Melempar UserError berpesan jelas bila gagal (perubahan tidak disimpan).
     */
    /**
     * Kontak orang tua diubah di Absensi → diteruskan ke Pusat (izin "siswa_kontak").
     * Pusat tidak bisa dihubungi → disimpan di Absensi dan diantrekan, dikirim otomatis nanti. @return bool true = langsung terkirim
     */
    public static function kontakOrtu(string $uuid, ?string $telp, ?string $nama, string $petugas): bool
    {
        $data = ['no_hp_ortu' => $telp, 'nama_ortu' => $nama, 'petugas' => $petugas];
        $hasil = self::kirimKontak($uuid, $data);
        if ($hasil === 'ok') {
            DB::table('pusat_antrean')->where(['jenis' => 'kontak', 'kunci' => $uuid])->delete();

            return true;
        }
        if ($hasil === 'mati' && PusatAntrean::siap()) {
            PusatAntrean::antre('kontak', $uuid, $data, 'Pusat belum bisa dihubungi');

            return false;
        }
        if ($hasil === 'mati') {
            throw new \App\Exceptions\UserError('Pusat Sekolah tidak dapat dihubungi, kontak orang tua belum disimpan. Coba lagi sebentar lagi.');
        }
        throw new \App\Exceptions\UserError(substr($hasil, 9));
    }

    /** @return string 'ok' | 'mati' (jaringan/5xx, layak dicoba lagi) | 'ditolak:<pesan>' */
    public static function kirimKontak(string $uuid, array $data): string
    {
        try {
            $res = self::http()->patch('/api/v1/siswa/'.$uuid.'/kontak', $data);
        } catch (\Throwable $e) {
            report($e);

            return 'mati';
        }
        if ($res->successful()) {
            return 'ok';
        }
        if ($res->status() >= 500 || in_array($res->status(), [408, 429], true)) {
            return 'mati';
        }
        if ($res->status() === 403) {
            return 'ditolak:Absensi belum diberi izin mengubah kontak orang tua di Pusat. Di Pusat → Aplikasi → Absensi, centang izin "siswa_kontak", lalu coba lagi.';
        }

        return 'ditolak:Pusat menolak perubahan kontak orang tua (HTTP '.$res->status().'): '.($res->json('error.pesan') ?? 'coba lagi');
    }

    /** Cek cepat Pusat hidup (dipakai sebelum mengarahkan ke login Pusat). */
    public static function hidup(): bool
    {
        try {
            return self::http()->timeout(4)->connectTimeout(3)->get('/api/v1/ping')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function http(): PendingRequest
    {
        return Http::baseUrl(config('pusat.url'))
            ->withOptions(['verify' => config('pusat.ca_bundle') ?: (bool) config('pusat.verify_ssl')])
            ->withBasicAuth(config('pusat.client_id'), config('pusat.client_secret'))
            ->acceptJson()
            ->timeout(config('pusat.timeout'));
    }

    /**
     * Ambil SEMUA baris sebuah sumber (halaman demi halaman).
     *
     * @return array<int,array>
     */
    public static function semua(string $sumber, ?string $jalur = null): array
    {
        $jalur ??= '/api/v1/master/'.$sumber;
        $baris = [];
        $hal = 1;
        do {
            $res = self::http()->get($jalur, ['per_page' => 500, 'page' => $hal]);
            if (! $res->successful()) {
                $pesan = $res->json('error.pesan') ?: 'HTTP '.$res->status();
                throw new RuntimeException("Pusat menolak permintaan {$sumber}: {$pesan}");
            }
            array_push($baris, ...($res->json('data') ?? []));
            $terakhir = (int) $res->json('meta.halaman_terakhir', 1);
            $total = (int) $res->json('meta.total', count($baris));
            $hal++;
        } while ($hal <= $terakhir);

        if (count($baris) !== $total) {
            throw new RuntimeException("Data {$sumber} dari Pusat tidak lengkap ({$total} diharapkan, ".count($baris).' diterima). Coba lagi.');
        }

        return $baris;
    }

    public static function simpan(string $kunci, mixed $nilai): void
    {
        \Illuminate\Support\Facades\DB::table('pusat_state')->updateOrInsert(
            ['kunci' => $kunci],
            ['nilai' => is_scalar($nilai) || $nilai === null ? $nilai : json_encode($nilai), 'updated_at' => now('UTC')->format('Y-m-d H:i:s')],
        );
    }

    public static function baca(string $kunci, mixed $bawaan = null): mixed
    {
        $row = \Illuminate\Support\Facades\DB::table('pusat_state')->where('kunci', $kunci)->first();

        return $row ? $row->nilai : $bawaan;
    }
}
