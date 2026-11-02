<?php

namespace App\Http\Middleware;

use App\Services\PusatKlien;
use Closure;
use Illuminate\Http\Request;

/**
 * Data induk (jurusan, kelas, akun baru, kenaikan kelas, impor kelas/guru/siswa) dikelola di Pusat bila integrasi aktif:
 * perubahan lokal ditolak agar tidak bentrok/tertimpa sinkron berikutnya. Parameter = nama data untuk pesan.
 */
class IndukPusat
{
    public function handle(Request $request, Closure $next, string $apa = 'Data ini')
    {
        if (! PusatKlien::kelolaSiswa()) {
            return $next($request);
        }
        $mapelPusat = \App\Models\Subject::dariPusat();
        $pengampuPusat = \App\Services\PusatPengampu::aktif();
        if (($apa === 'Mapel' && ! $mapelPusat) || ($apa === 'Pasangan' && ! $pengampuPusat)) {
            return $next($request); // mapel & pasangan baru dikunci setelah diambil dari Pusat
        }
        $kunci = array_merge(['kelas', 'guru', 'siswa'], $mapelPusat ? ['mapel'] : [], $pengampuPusat ? ['pasangan'] : []);
        if ($request->route('type') !== null && ! in_array($request->route('type'), $kunci, true)) {
            return $next($request); // impor absensi/nilai (dan mapel/pasangan sebelum diambil dari Pusat) tetap di Absensi
        }
        $apa = ['Mapel' => 'Mata_pelajaran', 'Pasangan' => 'Pasangan_mapel (Pengampu Mapel)'][$apa] ?? $apa;

        return back()->with('error', str_replace('_', ' ', $apa).' dikelola di Pusat Sekolah. Ubah di Pusat; perubahan tersalin otomatis ke Absensi.');
    }
}
