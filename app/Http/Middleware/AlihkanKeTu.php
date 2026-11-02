<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Bila TU_URL diisi, modul Tata Usaha dan absen mandiri guru/staf sudah dipindah ke aplikasi TU mandiri:
 * semua alamatnya dialihkan ke sana. Bila kosong, modul lama tetap berjalan seperti biasa.
 */
class AlihkanKeTu
{
    public static function tujuan(?string $nama, ?string $arah = null): string
    {
        return match (true) {
            $nama === 'selfcheck', $nama === 'selfcheck.store' => '/absen-saya',
            str_starts_with((string) $nama, 'tu.surat') => '/surat/'.(in_array($arah, ['masuk', 'keluar'], true) ? $arah : 'keluar'),
            str_starts_with((string) $nama, 'tu.mutasi') => '/mutasi',
            str_starts_with((string) $nama, 'tu.staff') => '/absen-pegawai',
            str_starts_with((string) $nama, 'tu.print') => '/cetak',
            default => '',
        };
    }

    public function handle(Request $request, Closure $next)
    {
        $base = rtrim((string) config('absensi.tu_url'), '/');
        if ($base === '') {
            return $next($request);
        }

        return redirect()->away($base.self::tujuan($request->route()?->getName(), $request->route('arah')));
    }
}
