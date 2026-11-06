<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Rules;
use Illuminate\Http\Request;

/** Laporan kehadiran & nilai satu kelas per mapel, siap cetak (Ctrl+P → Simpan sebagai PDF). Surat BK ada di aplikasi BK. */
class LetterController extends Controller
{
    public function report(Request $request)
    {
        $class = SchoolClass::findOrFail((int) $request->query('class'));
        $subject = Subject::findOrFail((int) $request->query('subject'));
        $u = $this->me();
        abort_unless(Rules::teacherAuthorization($u, $subject->id, $class->id)['allowed'] || $u->hasRole('bk', 'kepsek') || $u->isWaliOf($class->id), 403);
        $students = Student::active()->where('class_id', $class->id)->orderBy('nama')->get();
        $records = Attendance::query()->scope($class->id, $subject->id)->get();
        $acts = GradeActivity::where(['class_id' => $class->id, 'subject_id' => $subject->id, 'tipe_skala' => 'angka'])->pluck('id');
        $vals = GradeValue::whereIn('activity_id', $acts)->get()->groupBy('student_id');
        $rows = $students->map(function ($s) use ($records, $vals) {
            $sr = $records->where('student_id', $s->id);
            $nums = ($vals[$s->id] ?? collect())->pluck('nilai')->filter(fn ($v) => is_numeric($v));

            return ['s' => $s, 'rate' => $sr->count() ? (int) round($sr->where('status', 'H')->count() / $sr->count() * 100) : 100,
                'avg' => $nums->count() ? number_format($nums->avg(), 1) : '-'];
        });
        $tot = $records->count();

        return view('letters.report', [
            'class' => $class, 'subject' => $subject, 'rows' => $rows, 'sessions' => $records->pluck('tanggal')->unique()->count(),
            'pct' => $tot ? number_format($records->where('status', 'H')->count() / $tot * 100, 1) : '100',
        ]);
    }
}
