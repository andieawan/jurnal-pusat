<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PusatAkun;
use App\Services\PusatKlien;
use App\Support\PusatTanda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Penerima webhook Pusat. Aman: tanda tangan HMAC + batas waktu 5 menit + idempoten (X-Pusat-Delivery).
 * Data induk TIDAK diubah di sini (sinkron manual lewat Admin → Integrasi Pusat); webhook hanya menandai
 * "ada perubahan menunggu". Satu pengecualian: pencabutan akses akun diterapkan langsung demi keamanan.
 */
class PusatWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap() || config('pusat.webhook_secret') === '') {
            return response()->json(['ok' => false], 404);
        }
        $badan = $request->getContent();
        $waktu = (int) $request->header('X-Pusat-Timestamp');
        if (! PusatTanda::verifikasi((string) config('pusat.webhook_secret'), $waktu, $badan, (string) $request->header('X-Pusat-Signature'))) {
            return response()->json(['ok' => false, 'error' => 'tanda_tangan_salah'], 401);
        }

        $id = substr((string) $request->header('X-Pusat-Delivery'), 0, 64);
        $event = substr((string) $request->header('X-Pusat-Event'), 0, 60);
        if ($id === '' || $event === '') {
            return response()->json(['ok' => false, 'error' => 'header_kurang'], 422);
        }
        // Catat nomor kiriman DAN proses dalam satu transaksi: bila proses gagal, catatan ikut batal sehingga
        // pengiriman ulang dari Pusat diproses lagi (bukan dianggap duplikat lalu hilang).
        $baru = DB::transaction(function () use ($id, $event, $request) {
            $baru = DB::table('pusat_webhook')->insertOrIgnore(['delivery_id' => $id, 'event' => $event, 'created_at' => now('UTC')->format('Y-m-d H:i:s')]);
            if (! $baru) {
                return false;
            }
            $data = (array) $request->json('data', []);
            if ($event === 'sambungan.diperbarui') {
                \App\Services\Sambungan::tarik(); // gagal → transaksi batal → Pusat mengirim ulang
            } elseif ($event === 'profil.diperbarui') {
                \App\Services\ProfilPusat::tarik();
            } elseif (in_array($event, ['akun.diperbarui', 'akses.diperbarui'], true)) {
                PusatAkun::cabutBilaPerlu($data);
                PusatAkun::tautPegawai($data);
                PusatAkun::terapkanSandi($data); // login cadangan
            } elseif (preg_match('/^(siswa|kelas|jurusan|tahun_ajaran|mapel|pengampu)\./', $event)) {
                if (str_starts_with($event, 'tahun_ajaran.')) {
                    \App\Services\PusatSync::terapkanSemester($data); // semester langsung berlaku, tanpa menunggu sinkron
                }
                DB::table('pusat_state')->insertOrIgnore(['kunci' => 'perubahan_menunggu', 'nilai' => '0', 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);
                DB::table('pusat_state')->where('kunci', 'perubahan_menunggu')->update(['nilai' => DB::raw('CAST(nilai AS UNSIGNED) + 1')]);
            }

            return true;
        });
        if (! $baru) {
            return response()->json(['ok' => true, 'duplikat' => true]); // pengiriman ulang: sudah diproses
        }
        // Simpan secukupnya (bersihkan entri lama agar tabel tidak membengkak).
        DB::table('pusat_webhook')->where('created_at', '<', now('UTC')->subDays(7)->format('Y-m-d H:i:s'))->delete();

        return response()->json(['ok' => true]);
    }
}
