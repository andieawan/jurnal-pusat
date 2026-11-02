<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\AdminService;
use App\Services\DeleteService;
use Illuminate\Http\Request;

/** Data master: kelas & mata pelajaran. */
class MasterController extends Controller
{
    private const CLASS_RULES = ['name' => 'required|string|max:191', 'jurusan' => 'nullable|string|max:191', 'angkatan' => 'nullable|string|max:32', 'tahun_ajaran' => 'nullable|string|max:32', 'semester' => 'nullable|in:Ganjil,Genap'];

    public function index()
    {
        $majorOpts = \App\Support\DbUpdate::jurusanReady()
            ? \App\Models\Jurusan::orderBy('kode')->get()->mapWithKeys(fn ($m) => [$m->kode => $m->kode.($m->nama !== $m->kode ? ' — '.$m->nama : '')])->all()
            : null;

        return view('admin.master', ['classes' => SchoolClass::withCount('students')->orderBy('jurusan')->orderBy('name')->get(), 'subjects' => Subject::orderBy('name')->get(), 'majorOpts' => $majorOpts, 'mapelPusat' => Subject::dariPusat()]);
    }

    /** CSV daftar mapel (kode;nama;kelompok) untuk diimpor ke Pusat → Impor CSV → Mata pelajaran. */
    public function subjectsCsv()
    {
        $kode = [];
        $baris = ["\u{FEFF}kode;nama;kelompok"];
        foreach (Subject::orderBy('name')->get() as $s) {
            $k = $s->kode ?: self::kodeMapel($s->name);
            for ($i = 2, $asal = $k; isset($kode[$k]); $i++) {
                $k = mb_substr($asal, 0, 17).$i;
            }
            $kode[$k] = true;
            $baris[] = implode(';', [$k, str_replace([';', "\n", "\r"], [',', ' ', ' '], $s->name), $s->kelompok ?: 'umum']);
        }

        return response(implode("\r\n", $baris)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mapel-untuk-pusat.csv"',
        ]);
    }

    /** "Bahasa Indonesia" → "BI", "Matematika" → "MATEMATIKA" (maks 20). */
    private static function kodeMapel(string $nama): string
    {
        $kata = preg_split('/[^\pL\pN]+/u', mb_strtoupper(trim($nama)), -1, PREG_SPLIT_NO_EMPTY) ?: ['MAPEL'];
        $k = count($kata) === 1 ? $kata[0] : implode('', array_map(fn ($w) => preg_match('/^\pN+$/u', $w) ? $w : mb_substr($w, 0, 1), $kata));

        return mb_substr($k, 0, 20);
    }

    public function storeClass(Request $request)
    {
        AdminService::saveClass($this->me(), null, $request->validate(self::CLASS_RULES));

        return back()->with('success', 'Kelas ditambahkan.');
    }

    public function updateClass(Request $request, SchoolClass $class)
    {
        AdminService::saveClass($this->me(), $class, $request->validate(self::CLASS_RULES));

        return back()->with('success', 'Kelas diperbarui.');
    }

    public function storeSubject(Request $request)
    {
        AdminService::saveSubject($this->me(), null, $request->validate(['name' => 'required|string|max:191'])['name']);

        return back()->with('success', 'Mata pelajaran ditambahkan.');
    }

    public function updateSubject(Request $request, Subject $subject)
    {
        AdminService::saveSubject($this->me(), $subject, $request->validate(['name' => 'required|string|max:191'])['name']);

        return back()->with('success', 'Mata pelajaran diperbarui.');
    }

    public function destroyClass(SchoolClass $class)
    {
        DeleteService::deleteClass($this->me(), $class);

        return back()->with('success', "Kelas {$class->name} dihapus.");
    }

    public function destroySubject(Subject $subject)
    {
        DeleteService::deleteSubject($this->me(), $subject);

        return back()->with('success', "Mapel {$subject->name} dihapus.");
    }
}
