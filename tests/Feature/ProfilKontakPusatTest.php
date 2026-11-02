<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use App\Models\Student;
use App\Services\ProfilPusat;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Profil sekolah & kontak orang tua satu sumber di Pusat. */
class ProfilKontakPusatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(ProfilPusat::berkas());
        config(['pusat.enabled' => true, 'pusat.url' => 'https://pusat.test', 'pusat.client_id' => 'app_x', 'pusat.client_secret' => 'sec', 'pusat.webhook_secret' => 'whsec']);
    }

    protected function tearDown(): void
    {
        @unlink(ProfilPusat::berkas());
        @unlink(ProfilPusat::logoBerkas());
        parent::tearDown();
    }

    private function kirim(string $event, array $data, string $id)
    {
        $badan = json_encode(['event' => $event, 'data' => $data]);
        $t = time();

        return $this->call('POST', '/api/pusat/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PUSAT_EVENT' => $event, 'HTTP_X_PUSAT_DELIVERY' => $id,
            'HTTP_X_PUSAT_TIMESTAMP' => $t, 'HTTP_X_PUSAT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $t.'.'.$badan, 'whsec'),
        ], $badan);
    }

    public function test_profil_dari_pusat_mengisi_dan_mengunci_pengaturan(): void
    {
        SchoolSetting::put(['school_name' => 'Lama', 'kepsek_nama' => 'Lama', 'bk_nama' => 'Lama', 'semester' => 'Ganjil']);
        Http::fake(['pusat.test/api/v1/profil' => Http::response(['data' => ['diisi' => true, 'nama' => 'SMK Islam Bustanul Ulum Pakusari',
            'kepsek' => 'MUHAMMAD MUSLIM, S.Pd., Gr', 'guru_bk' => ['Bu Rina'], 'logo_url' => null]])]);
        $this->kirim('profil.diperbarui', [], 'p1')->assertOk();
        SchoolSetting::forget();
        $s = SchoolSetting::current();
        $this->assertSame('SMK Islam Bustanul Ulum Pakusari', $s->school_name);
        $this->assertSame('MUHAMMAD MUSLIM, S.Pd., Gr', $s->kepsek_nama);
        $this->assertSame('Bu Rina', $s->bk_nama);

        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertSee('dari Profil Sekolah di Pusat')
            ->assertSee('akun lokal');
        // simpan pengaturan tidak menimpa nilai dari Pusat
        $this->put('/admin/pengaturan', ['school_name' => 'Coba', 'kepsek_nama' => 'Coba', 'bk_nama' => 'Coba', 'semester' => 'Ganjil', 'backup_retention_weeks' => 8]);
        SchoolSetting::forget();
        $this->assertSame('MUHAMMAD MUSLIM, S.Pd., Gr', SchoolSetting::current()->kepsek_nama);
    }

    public function test_kontak_orang_tua_dikirim_ke_pusat(): void
    {
        Student::whereKey(1)->update(['pusat_uuid' => '5d5f0b7e-0000-4000-8000-000000000001']);
        $s = Student::find(1);
        $kirim = [];
        Http::fake(function (Request $r) use (&$kirim) {
            $kirim[] = [$r->method(), $r->url(), $r->data()];

            return Http::response(['data' => []]);
        });
        $in = ['nama' => $s->nama, 'jk' => $s->jk, 'class_id' => $s->class_id, 'status' => 'aktif', 'telp_ortu' => '081234567890', 'nama_ortu' => 'Pak Budi'];
        $this->actingAs($this->admin)->put('/admin/siswa/1', $in)->assertSessionMissing('error');
        $this->assertSame('Pak Budi', $s->fresh()->nama_ortu);
        $this->assertSame('PATCH', $kirim[0][0]);
        $this->assertStringEndsWith('/api/v1/siswa/5d5f0b7e-0000-4000-8000-000000000001/kontak', $kirim[0][1]);
        $this->assertSame('Pak Budi', $kirim[0][2]['nama_ortu']);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['error' => ['kode' => 'scope_tidak_cukup']], 403)]);
        $this->actingAs($this->admin)->put('/admin/siswa/1', ['nama_ortu' => 'Bu Ani'] + $in)->assertSessionHas('error', fn ($m) => str_contains($m, 'siswa_kontak'));
        $this->assertSame('Pak Budi', $s->fresh()->nama_ortu);
    }

    public function test_absen_mandiri_disembunyikan_bila_tu_terpisah(): void
    {
        config(['pusat.enabled' => false]);
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertSee('Absen Mandiri Guru');
        config(['absensi.tu_url' => 'https://tu.test']);
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertDontSee('Absen Mandiri Guru')->assertSee('diatur di');
    }

    public function test_semester_mengikuti_pusat(): void
    {
        SchoolSetting::put(['school_name' => 'SMK', 'semester' => 'Ganjil']);
        $this->kirim('tahun_ajaran.diperbarui', ['uuid' => 't-1', 'nama' => '2026/2027', 'semester' => 'Genap', 'aktif' => true], 's1')->assertOk();
        SchoolSetting::forget();
        $this->assertSame('Genap', SchoolSetting::current()->semester);
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertSee('Diatur di Pusat → Tahun Ajaran');
        $this->put('/admin/pengaturan', ['school_name' => 'SMK', 'semester' => 'Ganjil', 'backup_retention_weeks' => 8]);
        SchoolSetting::forget();
        $this->assertSame('Genap', SchoolSetting::current()->semester);
    }
}
