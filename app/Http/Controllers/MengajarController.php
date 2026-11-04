<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Jurnal;
use App\Models\Student;
use App\Services\Assignments;
use App\Services\JurnalService;
use App\Services\Rules;
use App\Support\Dates;
use Illuminate\Http\Request;

/** Mengajar: satu halaman untuk satu pertemuan — jurnal (materi, kegiatan, catatan, rencana) + absensi mapel. */
class MengajarController extends Controller
{
    /** Konteks (mapel → kelas, tanggal, jam ke-) dari query/body. */
    private function context(Request $r): array
    {
        $u = $this->me();
        $get = $r->isMethod('get');
        $subjects = Assignments::subjects($u);
        $want = (int) $r->input('subject');
        $subjectId = $want && (! $get || $subjects->contains('id', $want)) ? $want : (int) $subjects->first()?->id;
        $classes = $subjectId ? Assignments::classesForSubject($u, $subjectId) : collect();
        $want = (int) $r->input('class');
        $classId = $want && (! $get || $classes->contains('id', $want)) ? $want : (int) $classes->first()?->id;
        $tanggal = Dates::valid($r->input('date')) ? $r->input('date') : Dates::today();
        $jam = preg_replace('/\s+/', '', (string) $r->input('jam', ''));

        return [
            'subjects' => $subjects, 'classes' => $classes, 'classMap' => Assignments::classMap($u, $subjects),
            'subjectId' => $subjectId ?: null, 'classId' => $classId ?: null, 'tanggal' => $tanggal,
            'jam' => preg_match('/^\d{1,2}(-\d{1,2})?$/', $jam) ? $jam : '',
        ];
    }

    private function balik(array $c)
    {
        return redirect()->route('mengajar', array_filter(['subject' => $c['subjectId'], 'class' => $c['classId'], 'date' => $c['tanggal'], 'jam' => $c['jam']]));
    }

    public function index(Request $request)
    {
        $c = $this->context($request);
        $u = $this->me();
        $auth = $c['classId'] && $c['subjectId'] ? Rules::teacherAuthorization($u, $c['subjectId'], $c['classId'])
            : ['allowed' => false, 'error' => 'Belum ada mapel/kelas yang Anda ampu. Hubungi admin (Pengampu Mapel di Pusat).'];
        $data = $c + ['auth' => $auth, 'class' => $c['classes']->firstWhere('id', $c['classId']), 'subject' => $c['subjects']->firstWhere('id', $c['subjectId'])];
        if ($c['classId'] && $c['subjectId']) {
            $data['jurnal'] = Jurnal::where(['class_id' => $c['classId'], 'subject_id' => $c['subjectId'], 'tanggal' => $c['tanggal'], 'jam_ke' => $c['jam']])->first();
            $data['lalu'] = Jurnal::sebelumnya($c['classId'], $c['subjectId'], $c['tanggal'], $c['jam']);
            $data['lainHariIni'] = Jurnal::where(['class_id' => $c['classId'], 'subject_id' => $c['subjectId'], 'tanggal' => $c['tanggal']])
                ->where('jam_ke', '!=', $c['jam'])->orderBy('jam_ke')->get();
            $data['students'] = Student::active()->where('class_id', $c['classId'])->orderBy('nama')->get();
            $data['existing'] = Attendance::query()->scope($c['classId'], $c['subjectId'])->where('tanggal', $c['tanggal'])->get()->keyBy('student_id');
            $data['perangkat'] = \App\Models\PerangkatAjar::where(['jenis' => 'guru', 'subject_id' => $c['subjectId'], 'class_id' => $c['classId'], 'user_id' => $u->id])
                ->orderByDesc('updated_at')->limit(3)->get(['id', 'topik']);
            $data['riwayat'] = Jurnal::with('guru')->where(['class_id' => $c['classId'], 'subject_id' => $c['subjectId']])
                ->orderByDesc('tanggal')->orderByDesc('jam_ke')->limit(12)->get();
        }

        return view('mengajar.index', $data);
    }

    public function store(Request $request)
    {
        $c = $this->context($request);
        $d = $request->validate([
            'materi' => 'required|string|max:2000', 'kegiatan' => 'nullable|string|max:2000',
            'catatan' => 'nullable|string|max:1000', 'rencana' => 'nullable|string|max:1000',
        ], ['materi.required' => 'Materi / tujuan pembelajaran wajib diisi.']);
        $r = JurnalService::simpan($this->me(), (int) $c['classId'], (int) $c['subjectId'], $c['tanggal'], (string) $request->input('jam', ''), $d, PublicController::entries($request));
        $c['jam'] = $r['jurnal']->jam_ke;
        $msg = ($r['baru'] ? 'Jurnal tersimpan' : 'Jurnal diperbarui').($r['absen']['created'] + $r['absen']['updated'] ? ' bersama absensi '.($r['absen']['created'] + $r['absen']['updated']).' siswa' : '').'.';
        if ($r['absen']['alerts'] ?? []) {
            $msg .= ' Peringatan kehadiran di bawah 85%: '.implode('; ', array_slice($r['absen']['alerts'], 0, 5)).(count($r['absen']['alerts']) > 5 ? '; …' : '');
        }

        return $this->balik($c)->with(($r['absen']['alerts'] ?? []) ? 'warning' : 'success', $msg);
    }

    public function destroy(Request $request, Jurnal $jurnal)
    {
        JurnalService::hapus($this->me(), $jurnal);

        return redirect()->route('mengajar', ['subject' => $jurnal->subject_id, 'class' => $jurnal->class_id, 'date' => $jurnal->tanggal])
            ->with('success', 'Jurnal '.Dates::human($jurnal->tanggal).' dihapus (absensi siswa tidak ikut terhapus).');
    }
}
