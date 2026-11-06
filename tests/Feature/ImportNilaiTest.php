<?php

namespace Tests\Feature;

use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Impor riwayat nilai lama: satu baris = nilai satu siswa; baris sekegiatan digabung. */
class ImportNilaiTest extends TestCase
{
    private const HDR = ['Kelas', 'Mapel', 'Nama Kegiatan', 'Tanggal', 'Tipe', 'NIS', 'Nama Siswa', 'Nilai', 'Guru'];

    private function xlsx(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nl').'.xlsx';
        file_put_contents($path, Xlsx::build($sheets));

        return new UploadedFile($path, 'nilai.xlsx', null, null, true);
    }

    private function imp(array $rows, bool $dry = false)
    {
        return $this->actingAs($this->admin)->post('/admin/impor/nilai', ['file' => $this->xlsx(['Data' => array_merge([self::HDR], $rows)])] + ($dry ? ['dry' => 1] : []));
    }

    private function row(string $nis, string $nilai, array $over = []): array
    {
        return array_replace(['X RPL 1', 'Matematika', 'Tugas 1', $this->daysAgo(5), 'angka', $nis, '', $nilai, $this->guru->username], $over);
    }

    public function test_baris_sekegiatan_digabung_menjadi_satu_kegiatan(): void
    {
        if (! Xlsx::available()) {
            $this->markTestSkipped('ZipArchive tidak tersedia.');
        }
        $this->imp([
            $this->row('N-1', '80'), $this->row('N-2', '65'), $this->row('N-3', ''), // N-3 belum mengumpulkan
            $this->row('N-1', 'a', [2 => 'Ulangan 1', 4 => 'huruf', 8 => '']),
        ])->assertSessionHas('import_result');
        $this->assertSame([2, []], [session('import_result')['ok'], session('import_result')['skip']]);
        $t1 = GradeActivity::where('nama_kegiatan', 'Tugas 1')->first();
        $this->assertSame([$this->guru->id, 'angka', $this->daysAgo(5)], [$t1->teacher_id, $t1->tipe_skala, $t1->tanggal_kegiatan]);
        $this->assertSame(['80', '65'], GradeValue::where('activity_id', $t1->id)->orderBy('student_id')->pluck('nilai')->all());
        $u1 = GradeActivity::where('nama_kegiatan', 'Ulangan 1')->first();
        $this->assertSame(['huruf', $this->admin->id], [$u1->tipe_skala, $u1->teacher_id]);
        $this->assertSame('A', GradeValue::where('activity_id', $u1->id)->value('nilai'));
    }

    public function test_periksa_saja_dan_kegiatan_yang_sudah_ada_tidak_dobel(): void
    {
        if (! Xlsx::available()) {
            $this->markTestSkipped('ZipArchive tidak tersedia.');
        }
        $this->imp([$this->row('N-1', '80')], true);
        $this->assertSame([1, 0], [session('import_result')['ok'], GradeActivity::count()]);
        $this->imp([$this->row('N-1', '80')]);
        $this->imp([$this->row('N-1', '90')]);
        $this->assertSame(1, GradeActivity::count());
        $this->assertStringContainsString('sudah ada', session('import_result')['skip'][0]);
    }

    public function test_nilai_dan_data_tidak_valid_dilewati(): void
    {
        if (! Xlsx::available()) {
            $this->markTestSkipped('ZipArchive tidak tersedia.');
        }
        $this->imp([
            $this->row('N-1', '120'), $this->row('N-2', 'Z', [4 => 'huruf', 2 => 'Kuis']), $this->row('N-5', '70'),
            $this->row('N-1', '70', [0 => 'Kelas Hantu']), $this->row('N-1', '70', [1 => 'Kimia']), $this->row('N-1', '70', [4 => 'persen']),
            $this->row('N-2', '70'), $this->row('N-2', '75'),
        ]);
        $r = session('import_result');
        $this->assertSame(1, $r['ok']);
        $teks = implode("\n", $r['skip']);
        foreach (['bukan angka 0–100', 'bukan huruf A–E', "NIS 'N-5'", "kelas 'Kelas Hantu'", "mapel 'Kimia'", 'harus angka/huruf', 'muncul dua kali'] as $p) {
            $this->assertStringContainsString($p, $teks);
        }
        $this->assertSame('70', GradeValue::value('nilai'));
    }

    public function test_halaman_template_dan_hak_akses(): void
    {
        $this->actingAs($this->admin)->get('/admin/impor')->assertOk()->assertSee('Riwayat Nilai');
        $this->actingAs($this->admin)->get('/admin/impor/nilai/template')->assertOk();
        $this->actingAs($this->wali)->post('/admin/impor/nilai', ['file' => UploadedFile::fake()->createWithContent('n.csv', implode(',', self::HDR))])->assertForbidden();
    }
}
