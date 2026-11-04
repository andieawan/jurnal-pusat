<?php

namespace App\Http\Controllers;

use App\Models\SchoolSetting;

/** Manifest PWA dinamis (nama sekolah dari Pengaturan; path mengikuti lokasi instalasi). */
class PwaController extends Controller
{
    public function manifest()
    {
        $school = SchoolSetting::current()->school_name ?: config('absensi.school_name');
        $base = rtrim(url('/'), '/').'/';

        return response()->json([
            'id' => $base,
            'name' => 'Jurnal Guru — '.$school,
            'short_name' => 'Jurnal Guru',
            'description' => 'Absensi, nilai, dan pemantauan kehadiran siswa '.$school.'.',
            'lang' => 'id',
            'start_url' => $base,
            'scope' => $base,
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f1f4f0',
            'theme_color' => '#143f2a',
            'categories' => ['education', 'productivity'],
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Isi Absensi', 'url' => route('attendance'), 'icons' => [['src' => asset('icons/icon-192.png'), 'sizes' => '192x192']]],
                ['name' => 'Input Nilai', 'url' => route('grades'), 'icons' => [['src' => asset('icons/icon-192.png'), 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
