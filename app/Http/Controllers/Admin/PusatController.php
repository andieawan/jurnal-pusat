<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Audit;
use App\Services\PusatKlien;
use App\Services\PusatSync;
use Illuminate\Http\Request;

/** Admin → Integrasi Pusat: uji koneksi dan sinkron data induk (tampilan sama dengan aplikasi lain). */
class PusatController extends Controller
{
    public function index()
    {
        $siap = PusatKlien::siap();

        return view('admin.pusat', [
            'p' => [
                'aktif' => PusatKlien::aktif(), 'siap' => $siap, 'url' => config('pusat.url'),
                'callback' => route('sso.callback'), 'webhook' => url('/api/pusat/webhook'),
                'tahun' => $siap ? PusatKlien::baca('tahun_aktif') : null,
                'terakhir' => $siap ? PusatKlien::baca('sinkron_terakhir') : null,
                'jumlah' => ['kelas' => SchoolClass::count(), 'siswa aktif' => Student::active()->count(),
                    'siswa belum tertaut Pusat' => $siap ? Student::active()->whereNull('pusat_uuid')->count() : 0],
                'perluSinkron' => $siap && (int) PusatKlien::baca('perubahan_menunggu', 0) > 0,
                'antrean' => \App\Services\PusatAntrean::daftar(),
                'antreanJumlah' => \App\Services\PusatAntrean::jumlah(),
                'otomatis' => (bool) config('pusat.sinkron_otomatis'),
                'ruteUji' => route('admin.pusat.uji'), 'ruteSinkron' => route('admin.pusat.terapkan'),
                'catatan' => 'Tidak ada data yang dihapus: siswa yang keluar/hilang di Pusat hanya diberi status "keluar", riwayat absensi tetap ada. Mapel yang hilang di Pusat hanya dinonaktifkan; presensi dan nilai tidak pernah dihapus oleh sinkron.',
            ],
        ]);
    }

    public function uji()
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap()) {
            return back()->with('error', 'Integrasi Pusat belum aktif atau database belum diperbarui.');
        }
        try {
            $res = PusatKlien::http()->get('/api/v1/ping');
        } catch (\Throwable $e) {
            return back()->with('error', 'Pusat tidak dapat dihubungi: '.mb_substr($e->getMessage(), 0, 200));
        }
        if ($res->status() === 401) {
            return back()->with('error', 'Pusat menolak kredensial (client_id/secret salah atau aplikasi nonaktif).');
        }

        return $res->ok()
            ? back()->with('success', 'Terhubung ke Pusat. Kredensial benar.')
            : back()->with('error', 'Pusat membalas HTTP '.$res->status().'.');
    }

    public function periksa()
    {
        return $this->jalankan(false, false);
    }

    public function terapkan(Request $request)
    {
        return $this->jalankan(true, $request->boolean('paksa'));
    }

    private function jalankan(bool $terapkan, bool $paksa)
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap()) {
            return back()->with('error', 'Integrasi Pusat belum aktif atau database belum diperbarui.');
        }
        try {
            $hasil = app(PusatSync::class)->jalankan($terapkan, $paksa);
        } catch (\Throwable $e) {
            if (! $e instanceof \RuntimeException) {
                report($e);
            }

            return back()->with('error', 'Sinkron gagal, tidak ada data yang berubah: '.mb_substr($e->getMessage(), 0, 300))
                ->with('butuh_paksa', $terapkan && ! $paksa && str_contains($e->getMessage(), 'Tetap terapkan'));
        }
        if ($terapkan) {
            Audit::log('Terapkan Sinkron Pusat', 'Integrasi Pusat', $this->me()->nama, 'Dijalankan manual dari Admin');
        }

        // Catatan rinci yang dulu tampil di tabel → jadi peringatan agar kartu hasil sama di semua aplikasi.
        $s = $hasil['siswa'] ?? [];
        $k = $hasil['kelas'] ?? [];
        foreach (array_filter([
            ! empty($k['ambigu']) ? 'Nama kelas kembar di Absensi (dilewati): '.implode(', ', $k['ambigu']).'.' : null,
            ! empty($k['hilang']) ? 'Kelas tidak ada lagi di Pusat (dibiarkan): '.implode(', ', $k['hilang']).'.' : null,
            ! empty($s['ambigu']) ? 'NIS kembar di Absensi (dilewati): '.implode(', ', array_slice($s['ambigu'], 0, 10)).'.' : null,
            ! empty($s['hanya_lokal']) ? $s['hanya_lokal'].' siswa aktif di Absensi belum ada di Pusat (tidak diubah).' : null,
        ]) as $w) {
            $hasil['peringatan'][] = $w;
        }

        return back()->with('success', $terapkan ? 'Sinkron selesai.' : 'Simulasi selesai — belum ada data yang diubah.')->with('hasil', $hasil);
    }
}
