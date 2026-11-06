<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Support\Dates;
use App\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Impor riwayat absensi lama: satu baris = satu siswa pada satu tanggal. */
class ImportAbsensiTest extends TestCase
{
    private const HDR = ['Tanggal', 'Kelas', 'Mapel', 'NIS', 'Nama Siswa', 'Status', 'Catatan', 'Guru'];

    private function csv(array $rows, array $hdr = self::HDR): UploadedFile
    {
        $lines = [implode(',', $hdr)];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $r));
        }

        return UploadedFile::fake()->createWithContent('absen.csv', implode("\n", $lines));
    }

    private function imp(UploadedFile $f, bool $dry = false)
    {
        return $this->actingAs($this->admin)->post('/admin/impor/absensi', ['file' => $f] + ($dry ? ['dry' => 1] : []));
    }

    public function test_impor_harian_dan_mapel_dengan_status_catatan_dan_guru(): void
    {
        $d1 = $this->daysAgo(10);
        $d2 = date('d/m/Y', Dates::parse($this->daysAgo(9)));
        $this->imp($this->csv([
            [$d1, 'X RPL 1', 'Matematika', 'N-1', 'Siswa 1', 'H', '', $this->guru->username],
            [$d1, 'x  rpl 1', 'matematika', 'N-2', '', 'Sakit', 'Demam', ''],
            [$d2, 'X RPL 1', '', 'N-1', '', 'a', '', ''],
        ]))->assertSessionHas('import_result');
        $r = session('import_result');
        $this->assertSame([3, []], [$r['ok'], $r['skip']]);
        $a = Attendance::where(['tanggal' => $d1, 'student_id' => 1])->first();
        $this->assertSame(['H', 2, $this->guru->id, 'impor'], [$a->status, $a->subject_id, $a->recorded_by, $a->recorded_via]);
        $s = Attendance::where(['tanggal' => $d1, 'student_id' => 2])->first();
        $this->assertSame(['S', 'Demam', $this->admin->id], [$s->status, $s->notes, $s->recorded_by]);
        $h = Attendance::where('tanggal', $this->daysAgo(9))->first();
        $this->assertSame(['A', null, 1], [$h->status, $h->subject_id, $h->class_id]);
    }

    public function test_periksa_saja_tidak_menyimpan_dan_data_lama_tidak_ditimpa(): void
    {
        $d = $this->daysAgo(5);
        $rows = [[$d, 'X RPL 1', 'Matematika', 'N-1', '', 'A', '', ''], [$d, 'X RPL 1', 'Matematika', 'N-1', '', 'H', '', '']];
        $this->imp($this->csv($rows), true);
        $this->assertSame(0, Attendance::count());
        $this->assertStringContainsString('sudah ada', implode(' ', session('import_result')['skip'])); // baris dobel di berkas

        $this->mark(1, $d, 'S', 2); // sudah ada: Sakit (Matematika)
        $this->imp($this->csv($rows));
        $this->assertSame('S', Attendance::where('student_id', 1)->where('tanggal', $d)->value('status'));
        $this->assertSame(0, session('import_result')['ok']);
    }

    public function test_baris_bermasalah_dilewati_dengan_alasan(): void
    {
        $this->imp($this->csv([
            ['bukan-tanggal', 'X RPL 1', '', 'N-1', '', 'H', '', ''],
            [date('Y-m-d', strtotime('+3 days')), 'X RPL 1', '', 'N-1', '', 'H', '', ''],
            [$this->daysAgo(3), 'Kelas Hantu', '', 'N-1', '', 'H', '', ''],
            [$this->daysAgo(3), 'X RPL 1', 'Kimia', 'N-1', '', 'H', '', ''],
            [$this->daysAgo(3), 'X RPL 1', '', 'N-5', '', 'H', '', ''], // N-5 di kelas lain
            [$this->daysAgo(3), 'X RPL 1', '', 'N-1', '', 'Telat', '', ''],
            [$this->daysAgo(3), 'X RPL 1', '', 'N-2', '', 'I', '', ''],
        ]));
        $r = session('import_result');
        $this->assertSame(1, $r['ok']);
        $teks = implode("\n", $r['skip']);
        foreach (['tidak valid', 'masa depan', "kelas 'Kelas Hantu'", "mapel 'Kimia'", "NIS 'N-5'", 'harus H/I/S/A'] as $p) {
            $this->assertStringContainsString($p, $teks);
        }
    }

    public function test_kolom_wajib_hilang_ditolak(): void
    {
        $this->imp($this->csv([['X RPL 1', 'N-1']], ['Kelas', 'NIS']))->assertSessionHas('error');
        $this->actingAs($this->wali)->post('/admin/impor/absensi', ['file' => $this->csv([])])->assertForbidden();
        $this->assertSame(0, Attendance::count());
    }

    public function test_template_berisi_daftar_siswa_dan_banyak_sheet_digabung(): void
    {
        $this->actingAs($this->admin)->get('/admin/impor')->assertOk()->assertSee('Riwayat Absensi');
        if (! Xlsx::available()) {
            $this->markTestSkipped('ZipArchive tidak tersedia.');
        }
        $res = $this->actingAs($this->admin)->get('/admin/impor/absensi/template')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $res->getContent());
        $sheets = Xlsx::readAllSheets($path, 'tpl.xlsx');
        $this->assertSame(self::HDR, $sheets[0][0]);
        $this->assertSame(['Kelas', 'NIS', 'Nama Siswa'], $sheets[2][0]);
        $this->assertContains(['X RPL 1', 'N-1', 'Siswa 1'], $sheets[2]);

        $d = $this->daysAgo(4);
        $file = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        file_put_contents($file, Xlsx::build([
            'Juli' => [self::HDR, [$d, 'X RPL 1', '', 'N-1', '', 'H', '', '']],
            'Agustus' => [self::HDR, [$d, 'X RPL 1', '', 'N-2', '', 'I', '', '']],
            'Daftar Siswa' => [['Kelas', 'NIS', 'Nama Siswa'], ['X RPL 1', 'N-1', 'Siswa 1']],
        ]));
        $this->imp(new UploadedFile($file, 'impor.xlsx', null, null, true));
        $this->assertSame(2, session('import_result')['ok']);
    }
}
