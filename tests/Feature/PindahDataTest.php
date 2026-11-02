<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PindahDataTest extends TestCase
{
    public function test_ekspor_lalu_impor_menggabung_data_dan_aman_diulang(): void
    {
        Storage::fake('local');
        $zip = storage_path('framework/testing/pindah.zip');
        $t = '2026-10-01';
        Attendance::create(['student_id' => 1, 'class_id' => 1, 'subject_id' => 1, 'tanggal' => $t, 'status' => 'S', 'recorded_by' => $this->wali->id, 'recorded_via' => 'guru', 'created_at' => '2026-10-01 07:00:00', 'updated_at' => '2026-10-01 07:00:00', 'created_at_millis' => 1]);
        Attendance::create(['student_id' => 2, 'class_id' => 1, 'subject_id' => 1, 'tanggal' => $t, 'status' => 'A', 'recorded_by' => $this->wali->id, 'recorded_via' => 'guru', 'created_at' => '2026-10-01 07:00:00', 'updated_at' => '2026-10-01 07:00:00', 'created_at_millis' => 1]);
        DB::table('grade_activities')->insert(['id' => 'ga-1', 'teacher_id' => $this->wali->id, 'subject_id' => 1, 'class_id' => 1, 'nama_kegiatan' => 'UH 1', 'tanggal_kegiatan' => $t, 'tipe_skala' => '100', 'created_at' => '2026-10-01 08:00:00']);
        DB::table('grade_values')->insert(['activity_id' => 'ga-1', 'student_id' => 1, 'nilai' => '88']);
        Storage::disk('local')->put('bk/surat-1.pdf', 'PDF');
        DB::table('bk_records')->insert(['jenis' => 'konseling', 'student_id' => 1, 'class_id' => 1, 'tanggal' => $t, 'judul' => 'Sesi 1', 'uraian' => 'x', 'status' => 'selesai', 'rahasia' => 0, 'berkas_path' => 'bk/surat-1.pdf', 'dicatat_oleh' => $this->bk->id]);

        $this->artisan('absensi:ekspor-jurnal', ['berkas' => $zip])->assertExitCode(0);

        // "Server Jurnal": ID siswa berbeda, data kegiatan belum ada, satu absensi diisi lebih baru di Jurnal
        DB::table('grade_values')->delete();
        DB::table('grade_activities')->delete();
        DB::table('bk_records')->delete();
        Storage::disk('local')->delete('bk/surat-1.pdf');
        Attendance::where('student_id', 1)->delete();
        Attendance::where('student_id', 2)->update(['status' => 'H', 'updated_at' => '2026-10-02 07:00:00']);
        Student::find(1)->update(['id' => 99]);

        $this->artisan('jurnal:impor-absensi', ['berkas' => $zip])->assertExitCode(0); // simulasi
        $this->assertSame(0, DB::table('grade_activities')->count());
        $this->assertSame(0, Attendance::where('student_id', 99)->count());

        $this->artisan('jurnal:impor-absensi', ['berkas' => $zip, '--terapkan' => true])->assertExitCode(0);
        $this->assertSame('S', Attendance::where('student_id', 99)->value('status'));
        $this->assertSame('H', Attendance::where('student_id', 2)->value('status')); // yang di Jurnal lebih baru, tetap
        $this->assertSame('88', (string) DB::table('grade_values')->where('student_id', 99)->value('nilai'));
        $this->assertSame(99, (int) DB::table('bk_records')->value('student_id'));
        $this->assertSame('PDF', Storage::disk('local')->get('bk/surat-1.pdf'));
        $this->assertSame(6, Student::count()); // tidak ada siswa kembar

        $n = [Attendance::count(), DB::table('bk_records')->count(), DB::table('audit_log')->count()];
        $this->artisan('jurnal:impor-absensi', ['berkas' => $zip, '--terapkan' => true])->assertExitCode(0);
        $this->assertSame([$n[0], $n[1]], [Attendance::count(), DB::table('bk_records')->count()]);
    }

    public function test_berkas_salah_ditolak(): void
    {
        $this->artisan('jurnal:impor-absensi', ['berkas' => '/tidak/ada.zip'])->assertExitCode(1);
        $p = storage_path('framework/testing/rusak.zip');
        file_put_contents($p, 'bukan zip');
        $this->artisan('jurnal:impor-absensi', ['berkas' => $p, '--terapkan' => true])->assertExitCode(1);
    }
}
