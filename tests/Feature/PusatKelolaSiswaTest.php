<?php

namespace Tests\Feature;

use App\Exceptions\UserError;
use App\Models\Student;
use App\Services\AdminService;
use App\Services\DeleteService;
use App\Services\TuService;
use Tests\TestCase;

/** Bila tersambung ke Pusat, data induk siswa tidak boleh diubah lokal (agar semua aplikasi sama & tidak tertimpa sinkron). */
class PusatKelolaSiswaTest extends TestCase
{
    private Student $s;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pusat.enabled' => true, 'pusat.url' => 'https://pusat.test', 'pusat.client_id' => 'app_x', 'pusat.client_secret' => 'sec', 'pusat.webhook_secret' => 'wh']);
        $this->s = Student::find(1);
        $this->s->update(['pusat_uuid' => 'p-1']);
    }

    private function gagal(callable $f, string $pesan): void
    {
        try {
            $f();
            $this->fail('Seharusnya ditolak: '.$pesan);
        } catch (UserError $e) {
            $this->assertStringContainsString($pesan, $e->getMessage());
        }
    }

    public function test_tambah_impor_dan_ubah_data_induk_ditolak(): void
    {
        $this->gagal(fn () => AdminService::saveStudent($this->admin, null, ['nis' => 'X1', 'nama' => 'Baru', 'jk' => 'L', 'class_id' => 1, 'status' => 'aktif']), 'dikelola Pusat');
        $this->gagal(fn () => AdminService::importStudents($this->admin, ['nis', 'nama siswa', 'jk', 'kelas'], [['X2', 'Baru', 'L', 'X RPL 1']]), 'dikelola Pusat');
        $this->gagal(fn () => AdminService::saveStudent($this->admin, $this->s, ['nama' => $this->s->nama, 'jk' => $this->s->jk, 'class_id' => 2, 'status' => 'aktif']), 'dikelola Pusat');
        $this->gagal(fn () => TuService::mutasiKeluar($this->admin, ['student_id' => 1, 'status' => 'pindah', 'tanggal' => $this->daysAgo(0), 'sekolah' => 'SMK Lain']), 'aplikasi TU');
        $r = DeleteService::deleteStudents($this->admin, [1]);
        $this->assertSame(0, $r['deleted']);
        $this->assertStringContainsString('Pusat', $r['skipped'][0]);
        $this->assertSame(1, (int) $this->s->fresh()->class_id);
    }

    public function test_kontak_orang_tua_tetap_bisa_dilengkapi(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['data' => []])]); // diteruskan ke Pusat
        AdminService::saveStudent($this->admin, $this->s, ['nama' => $this->s->nama, 'jk' => $this->s->jk, 'class_id' => 1, 'status' => 'aktif',
            'nama_ortu' => 'Bu Siti', 'telp_ortu' => '081234567890']);
        $this->assertSame('Bu Siti', $this->s->fresh()->nama_ortu);
    }

    public function test_tanpa_pusat_tetap_seperti_biasa(): void
    {
        config(['pusat.enabled' => false]);
        $s = AdminService::saveStudent($this->admin, null, ['nis' => 'X9', 'nama' => 'Lokal', 'jk' => 'P', 'class_id' => 1, 'status' => 'aktif']);
        $this->assertSame('Lokal', $s->nama);
    }

    public function test_halaman_siswa_menampilkan_keterangan(): void
    {
        $this->actingAs($this->admin)->get(route('admin.students'))->assertOk()->assertSee('dikelola')->assertDontSee('Impor Siswa (xlsx');
    }
}
