<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Bila BK_URL diisi, modul BK & surat di aplikasi ini sudah dipindah ke aplikasi BK mandiri:
 * semua alamatnya dialihkan ke sana. Bila kosong, modul lama tetap berjalan seperti biasa.
 */
class AlihkanKeBk
{
    public function handle(Request $request, Closure $next)
    {
        $base = rtrim((string) config('absensi.bk_url'), '/');
        if ($base === '') {
            return $next($request);
        }
        $nama = (string) $request->route()?->getName();
        $tujuan = match (true) {
            $nama === 'bk' => '/presensi',
            $nama === 'bk.records' => '/catatan/'.$request->route('jenis'),
            default => '',
        };

        return redirect()->away($base.$tujuan);
    }
}
