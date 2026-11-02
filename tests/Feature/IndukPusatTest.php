<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Data induk (jurusan, kelas, akun, kenaikan, tahun ajaran, wali) dikelola di Pusat bila integrasi aktif. */
class IndukPusatTest extends TestCase
{
    private function pusatAktif(): void
    {
        config(['pusat.enabled' => true, 'pusat.url' => 'https://pusat.test', 'pusat.client_id' => 'app_x', 'pusat.client_secret' => 'sec', 'pusat.webhook_secret' => 'whsec']);
    }

    public function test_tanpa_pusat_tetap_bisa_diubah_lokal(): void
    {
        $this->actingAs($this->admin)->post('/admin/kelas', ['name' => 'XI RPL 1', 'jurusan' => 'RPL'])->assertSessionMissing('error');
        $this->assertTrue(SchoolClass::where('name', 'XI RPL 1')->exists());
    }

    public function test_perubahan_data_induk_ditolak_dan_halaman_menunjuk_ke_pusat(): void
    {
        $this->pusatAktif();
        $this->actingAs($this->admin);
        $this->post('/admin/kelas', ['name' => 'XI RPL 1', 'jurusan' => 'RPL'])->assertSessionHas('error', fn ($m) => str_contains($m, 'Kelas dikelola di Pusat'));
        $this->put('/admin/kelas/1', ['name' => 'Ganti'])->assertSessionHas('error');
        $this->post('/admin/jurusan', ['kode' => 'AK', 'nama' => 'Akuntansi'])->assertSessionHas('error');
        $this->post('/admin/guru', ['nama' => 'Baru', 'username' => 'baru', 'password' => 'Rahasia12345'])->assertSessionHas('error');
        $this->post('/admin/kenaikan', ['dari' => 1, 'ke' => 'lulus'])->assertSessionHas('error');
        $this->post('/admin/tahun-ajaran', ['tahun_ajaran' => '2027/2028'])->assertSessionHas('error');
        $this->post('/admin/impor/kelas', [])->assertSessionHas('error');
        $this->post('/admin/impor/mapel', []);
        $this->assertStringNotContainsString('Pusat', (string) session('error')); // impor mapel tetap di Absensi
        $this->assertSame('X RPL 1', SchoolClass::find(1)->name);
        $this->assertFalse(User::where('username', 'baru')->exists());

        $this->get('/admin/kelas')->assertOk()->assertSee('Kelas dikelola di Pusat Sekolah')->assertSee('https://pusat.test/admin/data/kelas')->assertDontSee('Tambah Kelas');
        $this->get('/admin/jurusan')->assertOk()->assertSee('https://pusat.test/admin/data/jurusan')->assertDontSee('Tambah Jurusan');
        $this->get('/admin/kenaikan')->assertOk()->assertSee('https://pusat.test/admin/kenaikan')->assertDontSee('Mulai Tahun Ajaran Baru');
        $this->get('/admin/guru')->assertOk()->assertSee('https://pusat.test/admin/akun')->assertDontSee('Tambah Akun Guru');
        $this->get('/admin/impor')->assertOk()->assertSee('https://pusat.test/admin/impor');
        $this->get('/admin/pengaturan')->assertOk()->assertSee('Mengikuti tahun ajaran aktif di Pusat');
    }

    public function test_akun_pusat_nama_peran_password_dari_pusat_mapel_tetap_lokal(): void
    {
        $this->pusatAktif();
        $this->wali->update(['pusat_uuid' => 'u-w']);
        $this->actingAs($this->admin)->put('/admin/guru/'.$this->wali->id, ['nama' => 'Diubah', 'roles' => ['admin'], 'subjects' => [1, 2], 'classes' => [1], 'kelas_wali_id' => 1]);
        $w = $this->wali->fresh();
        $this->assertSame('Wali', $w->nama);
        $this->assertSame(['guru'], $w->roles);
        $this->assertSame([1, 2], $w->subjects);
        $this->actingAs($this->admin)->post('/admin/guru/'.$this->wali->id.'/reset', ['password' => 'Rahasia12345'])->assertSessionHas('error');
        // akun lokal (bukan dari Pusat) tetap bisa diatur penuh
        $this->actingAs($this->admin)->put('/admin/guru/'.$this->guru->id, ['nama' => 'Guru Baru', 'roles' => ['guru'], 'subjects' => [2]]);
        $this->assertSame('Guru Baru', $this->guru->fresh()->nama);
    }

    public function test_sinkron_menerapkan_wali_kelas_dan_tahun_ajaran_dari_pusat(): void
    {
        $this->pusatAktif();
        SchoolSetting::put(['school_name' => 'SMK', 'tahun_ajaran' => '2025/2026', 'semester' => 'Ganjil']);
        $this->guru->update(['pusat_uuid' => 'u-g', 'pegawai_uuid' => 'peg-guru']);
        $data = [
            'jurusan' => [['kode' => 'RPL', 'nama' => 'Rekayasa Perangkat Lunak']],
            'tahun-ajaran' => [['uuid' => 't-1', 'nama' => '2026/2027', 'aktif' => true]],
            'kelas' => [['uuid' => 'k-1', 'nama' => 'X RPL 1', 'tingkat' => 'X', 'jurusan_kode' => 'RPL', 'tahun_ajaran' => '2026/2027', 'wali_uuid' => 'peg-guru']],
            'siswa' => [['uuid' => 's-1', 'nis' => 'N-1', 'nama' => 'Siswa 1', 'jenis_kelamin' => 'L', 'status' => 'aktif', 'kelas_uuid' => 'k-1']],
        ];
        Http::fake(function (Request $r) use ($data) {
            $sumber = basename(parse_url($r->url(), PHP_URL_PATH));

            return isset($data[$sumber]) ? Http::response(['data' => $data[$sumber], 'meta' => ['halaman' => 1, 'halaman_terakhir' => 1]]) : Http::response([], 404);
        });
        $this->actingAs($this->admin)->post('/admin/pusat/terapkan', ['paksa' => 1])->assertSessionHas('success');

        $this->assertSame(1, (int) $this->guru->fresh()->kelas_wali_id); // wali dari Pusat
        $this->assertNull($this->wali->fresh()->kelas_wali_id);           // wali lama dilepas
        SchoolSetting::forget();
        $this->assertSame('2026/2027', SchoolSetting::current()->tahun_ajaran);
    }

    public function test_menu_bk_tu_disembunyikan_bila_aplikasi_terpisah(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('>TU<', false);
        config(['absensi.tu_url' => 'https://tu.test', 'absensi.bk_url' => 'https://bk.test']);
        $this->actingAs($this->admin)->get('/')->assertOk()->assertDontSee('>TU<', false)->assertDontSee('>BK<', false);
    }

    public function test_menu_admin_dikelompokkan_dan_halaman_pusat_disembunyikan(): void
    {
        $r = $this->actingAs($this->admin)->get('/admin/integrasi')->assertOk();
        $r->assertSee('Guru &amp; Mengajar', false)->assertSee('Sistem')->assertSee('Pusat Sekolah')->assertSee('Kunci API');
        $this->get('/admin/kelas')->assertOk()->assertSee('Jurusan')->assertSee('Kenaikan Kelas');
        $this->pusatAktif();
        $this->get('/admin/kelas')->assertOk()->assertDontSee('>Kenaikan Kelas<', false)->assertDontSee('>Jurusan</a>', false);
    }
}
