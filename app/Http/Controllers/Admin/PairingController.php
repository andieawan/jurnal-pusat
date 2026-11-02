<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Otorisasi pasangan guru – mapel – kelas. */
class PairingController extends Controller
{
    public function index()
    {
        return view('admin.pairings', [
            'pairs' => Pairing::all(), 'users' => User::where('is_active', true)->orderBy('nama')->get(),
            'subjects' => Subject::orderBy('name')->get(), 'classes' => SchoolClass::orderBy('name')->get(),
            'dariPusat' => \App\Services\PusatPengampu::aktif(),
        ]);
    }

    private function data(Request $r): array
    {
        return $r->validate(['user_id' => 'required|integer|exists:users,id', 'subject_id' => 'required|integer|exists:subjects,id', 'class_id' => 'required|integer|exists:classes,id']);
    }

    public function store(Request $request)
    {
        $d = $this->data($request);
        Pairing::firstOrCreate($d);
        Audit::log('Tambah Pasangan Mapel', 'Pasangan', $this->me()->nama, User::find($d['user_id'])->nama.' — '.Subject::find($d['subject_id'])->name.' — '.SchoolClass::find($d['class_id'])->name);

        return back()->with('success', 'Pasangan ditambahkan.');
    }

    public function destroy(Request $request)
    {
        $d = $this->data($request);
        if (\App\Services\PusatPengampu::aktif() && Pairing::where($d)->whereNotNull('pusat_uuid')->exists()) {
            return back()->with('error', 'Pasangan ini berasal dari Pusat. Hapus di Pusat → Pengampu Mapel, lalu Sinkron.');
        }
        Pairing::where($d)->delete();
        Audit::log('Hapus Pasangan Mapel', 'Pasangan', $this->me()->nama, "Guru #{$d['user_id']}, mapel #{$d['subject_id']}, kelas #{$d['class_id']}");

        return back()->with('success', 'Pasangan dihapus.');
    }

    /** CSV guru;mapel;kelas untuk Pusat → Pengampu Mapel → Impor CSV (satu baris per guru+mapel, kelas dipisah koma). */
    public function csv()
    {
        $u = User::all()->keyBy('id');
        $s = Subject::all()->keyBy('id');
        $c = SchoolClass::all()->keyBy('id');
        $baris = ["\u{FEFF}guru;mapel;kelas"];
        foreach (Pairing::all()->groupBy(fn ($p) => $p->user_id.'-'.$p->subject_id) as $grup) {
            $p = $grup->first();
            $guru = $u[$p->user_id] ?? null;
            $mapel = $s[$p->subject_id] ?? null;
            if (! $guru || ! $mapel) {
                continue;
            }
            $kelas = $grup->map(fn ($x) => $c[$x->class_id]->name ?? null)->filter()->unique()->implode(', ');
            $bersih = fn ($v) => str_replace([';', "\n", "\r"], [',', ' ', ' '], (string) $v);
            // akun dari Pusat: username sama dengan di Pusat (biasanya NIP); akun lokal: pakai nama
            $baris[] = implode(';', [$bersih($guru->pusat_uuid ? $guru->username : $guru->nama), $bersih($mapel->kode ?: $mapel->name), $bersih($kelas)]);
        }

        return response(implode("\r\n", $baris)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="pengampu-untuk-pusat.csv"',
        ]);
    }
}
