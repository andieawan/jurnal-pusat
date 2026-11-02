<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\PusatEkspor;
use App\Services\PusatKlien;
use App\Support\PusatTanda;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Penyambungan ke Pusat Sekolah: login tunggal, sinkron manual, webhook, ekspor. */
class PusatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pusat.enabled' => true, 'pusat.url' => 'https://pusat.test', 'pusat.client_id' => 'app_x',
            'pusat.client_secret' => 'sec', 'pusat.webhook_secret' => 'whsec']);
    }

    private function info(array $o = []): array
    {
        return array_merge(['sub' => 'u-1', 'username' => 'wali', 'nama' => 'Wali Pusat', 'peran' => ['guru']], $o);
    }

    private function loginViaPusat(array $info)
    {
        Http::swap(new \Illuminate\Http\Client\Factory); // buang tiruan sebelumnya
        Http::fake(['pusat.test/api/sso/token' => Http::response(['access_token' => 't', 'user' => $info]), 'pusat.test/api/v1/ping' => Http::response(['ok' => true])]);
        $this->get('/sso/login');
        $state = session('pusat_sso.state');

        return $this->get('/sso/callback?code=abc&state='.$state);
    }

    /** Pusat palsu untuk sinkron. */
    private function fakePusat(array $siswa, ?array $kelas = null, ?array $mapel = null, ?array $pengampu = null, ?array $akun = null): void
    {
        $kelas ??= [['uuid' => 'k-1', 'nama' => 'X RPL 1', 'tingkat' => 'X', 'jurusan_kode' => 'RPL', 'tahun_ajaran' => '2026/2027']];
        $data = [
            'jurusan' => [['kode' => 'RPL', 'nama' => 'Rekayasa Perangkat Lunak'], ['kode' => 'DKV', 'nama' => 'Desain Komunikasi Visual']],
            'tahun-ajaran' => [['uuid' => 't-1', 'nama' => '2026/2027', 'aktif' => true]],
            'kelas' => $kelas,
            'siswa' => $siswa,
        ];
        if ($mapel !== null) {
            $data['mapel'] = $mapel;
        }
        if ($pengampu !== null) {
            $data['pengampu'] = $pengampu;
        }
        if ($akun !== null) {
            $data['akun'] = $akun;
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function (Request $r) use ($data) {
            $sumber = basename(parse_url($r->url(), PHP_URL_PATH));
            if ($r->method() === 'PATCH') {
                return Http::response(['data' => ['ok' => true]]);
            }
            if (! isset($data[$sumber])) {
                return Http::response(['error' => ['pesan' => 'Izin tidak cukup']], 403);
            }

            return Http::response(['data' => $data[$sumber], 'meta' => ['halaman' => 1, 'halaman_terakhir' => 1, 'total' => count($data[$sumber])]]);
        });
    }

    private function siswaPusat(string $uuid, string $nis, array $o = []): array
    {
        return array_merge(['uuid' => $uuid, 'nis' => $nis, 'nama' => "Siswa $nis", 'jenis_kelamin' => 'L', 'status' => 'aktif', 'kelas_uuid' => 'k-1',
            'no_hp_ortu' => null, 'nama_ortu' => null], $o);
    }

    // ---- Login tunggal ----

    public function test_tombol_masuk_lewat_pusat_hanya_saat_aktif(): void
    {
        $this->get('/login')->assertOk()->assertSee('Masuk lewat Pusat');
        config(['pusat.enabled' => false]);
        $this->get('/login')->assertOk()->assertDontSee('Masuk lewat Pusat');
        $this->get('/sso/login')->assertRedirect('/login');
    }

    public function test_mulai_mengarah_ke_pusat_dengan_pkce_dan_state(): void
    {
        Http::fake(['pusat.test/api/v1/ping' => Http::response(['ok' => true])]);
        $r = $this->get('/sso/login');
        $loc = $r->headers->get('Location');
        $this->assertStringStartsWith('https://pusat.test/sso/authorize?', $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('app_x', $q['client_id']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(session('pusat_sso.state'), $q['state']);
        $this->assertSame(route('sso.callback'), $q['redirect_uri']);
    }

    public function test_callback_menautkan_akun_lokal_dan_memetakan_peran(): void
    {
        $this->loginViaPusat($this->info(['peran' => ['guru', 'bk', 'wali_kelas']]))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->wali->fresh());
        $this->assertSame('u-1', $this->wali->fresh()->pusat_uuid);
        $this->assertEqualsCanonicalizing(['guru', 'bk'], $this->wali->fresh()->roles); // wali_kelas diabaikan
        $this->assertSame(1, $this->wali->fresh()->kelas_wali_id);                      // penugasan wali tidak disentuh
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sso/token') && $r['code_verifier'] !== '' && $r['redirect_uri'] === route('sso.callback'));
    }

    public function test_callback_membuat_akun_baru_dengan_password_tak_bisa_dipakai(): void
    {
        $this->loginViaPusat($this->info(['sub' => 'u-9', 'username' => 'Guru.Baru', 'nama' => 'Guru Baru']))->assertRedirect(route('dashboard'));

        $u = User::where('pusat_uuid', 'u-9')->firstOrFail();
        $this->assertSame('guru.baru', $u->username);
        $this->assertNotEmpty($u->password_hash);
        $this->post('/logout');
        // Password acak yang tidak pernah diberitahukan: tebakan apa pun ditolak.
        $this->post('/login', ['username' => 'guru.baru', 'password' => 'tebakan-apa-saja-123'])->assertSessionHasErrors('username');
    }

    public function test_superadmin_lokal_tidak_diturunkan_oleh_pusat(): void
    {
        $this->loginViaPusat($this->info(['sub' => 'u-2', 'username' => 'admin', 'peran' => ['guru']]));

        $this->assertContains('superadmin', $this->admin->fresh()->roles);
        $this->assertContains('guru', $this->admin->fresh()->roles);
    }

    public function test_state_salah_atau_akun_nonaktif_atau_tanpa_peran_dikenal_ditolak(): void
    {
        Http::fake(['pusat.test/api/sso/token' => Http::response(['user' => $this->info()]), 'pusat.test/api/v1/ping' => Http::response(['ok' => true])]);
        $this->get('/sso/login');
        $this->get('/sso/callback?code=abc&state=salah')->assertRedirect('/login');
        $this->assertGuest();

        $this->wali->update(['is_active' => false]);
        $this->loginViaPusat($this->info())->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();

        $this->wali->update(['is_active' => true]);
        $this->loginViaPusat($this->info(['peran' => ['wali_kelas', 'siswa']]))->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_username_tertaut_ke_akun_pusat_lain_ditolak(): void
    {
        $this->wali->update(['pusat_uuid' => 'lain']);
        $this->loginViaPusat($this->info(['sub' => 'u-baru']))->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_logout_dari_sesi_pusat_keluar_juga_dari_pusat(): void
    {
        $this->loginViaPusat($this->info());
        $r = $this->post('/logout');
        $this->assertStringStartsWith('https://pusat.test/sso/logout?', $r->headers->get('Location'));
    }

    // ---- Sinkron manual ----

    public function test_simulasi_tidak_mengubah_apa_pun_dan_terapkan_menautkan_data(): void
    {
        $this->fakePusat([
            $this->siswaPusat('s-1', 'N-1', ['nama_ortu' => 'Pak Budi', 'no_hp_ortu' => '0812-3456-7890']),
            $this->siswaPusat('s-new', 'N-99'),
        ]);
        $this->actingAs($this->admin);

        $this->post('/admin/pusat/periksa')->assertSessionHas('hasil', fn ($h) => $h['siswa']['baru'] === 1 && $h['siswa']['ubah'] === 1 && ! $h['diterapkan']);
        $this->assertSame(6, Student::count());
        $this->assertNull(Student::find(1)->pusat_uuid);
        $this->assertNull(SchoolClass::find(1)->pusat_uuid);

        $this->post('/admin/pusat/terapkan')->assertSessionHas('success');
        $this->assertSame(7, Student::count());
        $this->assertSame('s-1', Student::find(1)->pusat_uuid);
        $this->assertSame('Pak Budi', Student::find(1)->nama_ortu);
        $this->assertSame('6281234567890', Student::find(1)->telp_ortu);
        $this->assertSame('k-1', SchoolClass::find(1)->pusat_uuid);           // kelas lama ditautkan lewat nama
        $this->assertSame(1, Student::where('nis', 'N-99')->value('class_id')); // siswa baru masuk ke kelas tertaut
        $this->assertNotNull(PusatKlien::baca('sinkron_terakhir'));
        $this->assertTrue(DB::table('jurusan')->where('kode', 'DKV')->exists());
    }

    public function test_mapel_dari_pusat_menautkan_lewat_nama_dan_mengunci_edit(): void
    {
        $this->actingAs($this->admin);
        $this->fakePusat([$this->siswaPusat('s-1', 'N-1')]);
        $this->post('/admin/pusat/terapkan')->assertSessionHas('hasil', fn ($h) => collect($h['peringatan'])->contains(fn ($p) => str_contains($p, 'Mata pelajaran')));
        $this->post(route('admin.subjects.store'), ['name' => 'Fisika'])->assertSessionHas('success'); // belum dari Pusat → masih bisa
        $this->get(route('admin.subjects.csv'))->assertOk()->assertSee('kode;nama;kelompok')->assertSee('BI;Bahasa Indonesia;umum');

        $this->fakePusat([$this->siswaPusat('s-1', 'N-1')], null, [
            ['uuid' => 'm-1', 'kode' => 'BIND', 'nama' => 'bahasa indonesia', 'kelompok' => 'umum', 'aktif' => true],
            ['uuid' => 'm-2', 'kode' => 'PAI', 'nama' => 'Pendidikan Agama Islam', 'kelompok' => 'umum', 'aktif' => true],
        ]);
        $this->post('/admin/pusat/terapkan')->assertSessionHas('hasil', fn ($h) => $h['mapel']['tertaut'] === 1 && $h['mapel']['baru'] === 1
            && in_array('Matematika', $h['mapel']['hanya_lokal'], true) && ! in_array('Bimbingan Konseling', $h['mapel']['hanya_lokal'], true));
        $bi = \App\Models\Subject::find(1);
        $this->assertSame('m-1', $bi->pusat_uuid);           // ID lokal tetap → riwayat aman
        $this->assertSame('bahasa indonesia', $bi->name);
        $this->assertSame('BIND', $bi->kode);
        $this->assertTrue(\App\Models\Subject::where('pusat_uuid', 'm-2')->exists());

        $this->post(route('admin.subjects.store'), ['name' => 'Kimia'])->assertSessionHas('error');
        $this->put(route('admin.subjects.update', 1), ['name' => 'Ganti'])->assertSessionHas('error');
        $this->assertFalse(\App\Models\Subject::where('name', 'Kimia')->exists());
        $this->get(route('admin.master'))->assertOk()->assertSee('Daftar mata pelajaran dikelola di Pusat');

        // mapel dihapus di Pusat → hanya dinonaktifkan
        $this->fakePusat([$this->siswaPusat('s-1', 'N-1')], null, [['uuid' => 'm-1', 'kode' => 'BIND', 'nama' => 'Bahasa Indonesia', 'kelompok' => 'umum', 'aktif' => true]]);
        $this->post('/admin/pusat/terapkan')->assertSessionHas('hasil', fn ($h) => $h['mapel']['nonaktif'] === 1);
        $pai = \App\Models\Subject::where('pusat_uuid', 'm-2')->first();
        $this->assertFalse($pai->aktif);
        $this->assertFalse(\App\Models\Subject::regular()->whereKey($pai->id)->exists());
    }

    public function test_pasangan_mapel_dari_pusat(): void
    {
        $this->actingAs($this->admin);
        $mapel = [['uuid' => 'm-1', 'kode' => 'BIND', 'nama' => 'Bahasa Indonesia', 'kelompok' => 'umum', 'aktif' => true],
            ['uuid' => 'm-2', 'kode' => 'MTK', 'nama' => 'Matematika', 'kelompok' => 'umum', 'aktif' => true]];
        $this->guru->update(['pegawai_uuid' => 'g-1']);
        \App\Models\Pairing::query()->insertOrIgnore(['user_id' => $this->guru->id, 'subject_id' => 2, 'class_id' => 1]);   // sama dengan Pusat → ditautkan
        \App\Models\Pairing::query()->insertOrIgnore(['user_id' => $this->wali->id, 'subject_id' => 1, 'class_id' => 1]);   // hanya lokal → dibiarkan
        $this->get(route('admin.pairings.csv'))->assertOk()->assertSee('guru;mapel;kelas')->assertSee('Matematika;X RPL 1');

        $pengampu = [
            ['uuid' => 'p-1', 'pegawai_uuid' => 'g-1', 'mapel_uuid' => 'm-2', 'kelas_uuid' => 'k-1'],
            ['uuid' => 'p-2', 'pegawai_uuid' => 'g-1', 'mapel_uuid' => 'm-1', 'kelas_uuid' => 'k-1'],
            ['uuid' => 'p-3', 'pegawai_uuid' => 'g-baru', 'mapel_uuid' => 'm-1', 'kelas_uuid' => 'k-1'], // guru belum pernah masuk
        ];
        $this->fakePusat([$this->siswaPusat('s-1', 'N-1')], null, $mapel, $pengampu);
        $this->post('/admin/pusat/terapkan')->assertSessionHas('hasil', fn ($h) => $h['pengampu']['baru'] === 1 && $h['pengampu']['tertaut'] === 1
            && $h['pengampu']['tertunda'] === 1 && $h['pengampu']['hanya_lokal'] === 1);
        $this->assertSame(3, \App\Models\Pairing::count());
        $this->assertSame('p-1', \App\Models\Pairing::where(['user_id' => $this->guru->id, 'subject_id' => 2])->value('pusat_uuid'));

        // terkunci: tambah ditolak, hapus pasangan dari Pusat ditolak, pasangan lokal masih bisa dihapus
        $this->post(route('admin.pairings.store'), ['user_id' => $this->guru->id, 'subject_id' => 1, 'class_id' => 2])->assertSessionHas('error');
        $this->delete(route('admin.pairings.destroy'), ['user_id' => $this->guru->id, 'subject_id' => 2, 'class_id' => 1])->assertSessionHas('error');
        $this->delete(route('admin.pairings.destroy'), ['user_id' => $this->wali->id, 'subject_id' => 1, 'class_id' => 1])->assertSessionHas('success');
        $this->get(route('admin.pairings'))->assertOk()->assertSee('Pasangan mapel dikelola di Pusat');
        $this->get(route('admin.import'))->assertOk()->assertSee('Sudah diimpor di Pusat')
            ->assertDontSee(route('admin.import.run', 'pasangan'))->assertDontSee(route('admin.import.run', 'mapel'))->assertDontSee(route('admin.import.run', 'siswa'))
            ->assertSee(route('admin.import.run', 'absensi'))->assertSee(route('admin.import.template', 'nilai'));

        // guru baru masuk lewat Pusat → pengampunya langsung terpasang
        $u = \App\Models\User::create(['username' => 'baru', 'nama' => 'Guru Baru', 'roles' => ['guru'], 'subjects' => [], 'classes' => [], 'password_hash' => 'x', 'is_active' => true, 'pusat_uuid' => 'a-9']);
        \App\Services\PusatAkun::tautPegawai(['uuid' => 'a-9', 'pegawai_uuid' => 'g-baru']);
        $this->assertTrue(\App\Models\Pairing::where(['user_id' => $u->id, 'subject_id' => 1, 'class_id' => 1])->exists());

        // dihapus di Pusat → hilang di Absensi
        $this->fakePusat([$this->siswaPusat('s-1', 'N-1')], null, $mapel, [$pengampu[0]]);
        $this->post('/admin/pusat/terapkan')->assertSessionHas('hasil', fn ($h) => $h['pengampu']['dihapus'] === 2);
        $this->assertSame(1, \App\Models\Pairing::count());
    }

    public function test_sinkron_membuat_akun_guru_dan_mapel_bk_memakai_id_bawaan(): void
    {
        $this->actingAs($this->admin);
        \App\Models\Subject::whereKey(5)->delete(); // mis. Absensi baru dikosongkan tanpa mapel BK
        \App\Models\Subject::create(['name' => 'Bimbingan Konseling', 'pusat_uuid' => 'm-bk']); // terlanjur dibuat dengan ID lain
        $mapel = [['uuid' => 'm-bk', 'kode' => 'BK', 'nama' => 'Bimbingan Konseling', 'kelompok' => 'umum', 'aktif' => true],
            ['uuid' => 'm-2', 'kode' => 'MTK', 'nama' => 'Matematika', 'kelompok' => 'umum', 'aktif' => true]];
        $akun = [
            ['uuid' => 'a-1', 'username' => 'G008', 'nama' => 'Abdul Rahman', 'aktif' => true, 'pegawai_uuid' => 'g-8', 'peran' => ['guru']],
            ['uuid' => 'a-2', 'username' => 'G099', 'nama' => 'Tanpa Peran', 'aktif' => true, 'pegawai_uuid' => 'g-9', 'peran' => []],
        ];
        $this->fakePusat([$this->siswaPusat('s-1', 'N-1')], null, $mapel, [['uuid' => 'p-1', 'pegawai_uuid' => 'g-8', 'mapel_uuid' => 'm-2', 'kelas_uuid' => 'k-1']], $akun);
        $this->post('/admin/pusat/terapkan')->assertSessionHas('hasil', fn ($h) => $h['akun']['baru'] === 1 && $h['pengampu']['baru'] === 1 && $h['pengampu']['tertunda'] === 0);
        $this->assertSame('m-bk', \App\Models\Subject::find(5)?->pusat_uuid);
        $u = \App\Models\User::where('pusat_uuid', 'a-1')->firstOrFail();
        $this->assertSame('g-8', $u->pegawai_uuid);
        $this->assertTrue(\App\Models\Pairing::where(['user_id' => $u->id, 'subject_id' => \App\Models\Subject::where('pusat_uuid', 'm-2')->value('id'), 'class_id' => 1])->exists());
        $this->assertFalse(\App\Models\User::where('pusat_uuid', 'a-2')->exists());
        $this->get('/admin/pusat')->assertOk()->assertSee('Akun guru &amp; staf', false);
    }

    public function test_ganti_akun_lewat_pusat_keluar_dari_pusat_lalu_masuk_lagi(): void
    {
        $this->actingAs($this->admin)->get('/ganti-akun')->assertOk()->assertSee('Ganti akun lewat Pusat');
        $r = $this->post('/ganti-akun/pusat');
        $r->assertRedirect();
        $this->assertStringStartsWith('https://pusat.test/sso/logout?', $r->headers->get('Location'));
        $this->assertStringContainsString(urlencode(route('sso.login')), $r->headers->get('Location'));
        $this->assertGuest();
        $this->post('/login', ['username' => 'g008', 'password' => 'salah'])->assertSessionHasErrors('username');
        $this->assertStringContainsString('Masuk lewat Pusat', session('errors')->first('username'));
    }

    public function test_login_cadangan_dengan_password_pusat(): void
    {
        $u = \App\Models\User::create(['username' => 'g008', 'nama' => 'Abdul', 'roles' => ['guru'], 'subjects' => [], 'classes' => [],
            'password_hash' => \App\Support\Passwords::make(\Illuminate\Support\Str::random(40)), 'is_active' => true, 'pusat_uuid' => 'a-8']);
        // masih wajib ganti password awal → login lokal tertutup
        $this->webhook('akses.diperbarui', ['uuid' => 'a-8', 'username' => 'g008', 'aktif' => true, 'peran' => ['guru'], 'password_hash' => null])->assertOk();
        $this->post('/login', ['username' => 'G008', 'password' => 'PasswordPusat123'])->assertSessionHasErrors('username');

        $this->webhook('akun.diperbarui', ['uuid' => 'a-8', 'username' => 'g008', 'aktif' => true, 'peran' => ['guru'], 'password_hash' => password_hash('PasswordPusat123', PASSWORD_BCRYPT)])->assertOk();
        $hashLokal = $u->fresh()->password_hash;
        $this->post('/login', ['username' => 'G008', 'password' => 'PasswordPusat123'])->assertRedirect();
        $this->assertAuthenticatedAs($u->fresh());
        $this->assertSame($hashLokal, $u->fresh()->password_hash); // hash lokal tidak diubah → sesi lain tidak terputus

        // ganti password diarahkan ke Pusat
        $this->get('/password')->assertOk()->assertSee('Ganti password di Pusat');
        $this->post('/password', ['old' => 'x', 'new' => 'y', 'new_confirmation' => 'y'])->assertRedirect('https://pusat.test/profil/password');

        // akun dinonaktifkan di Pusat → login cadangan juga tertutup
        $this->post('/logout');
        $this->webhook('akun.diperbarui', ['uuid' => 'a-8', 'username' => 'g008', 'aktif' => false, 'peran' => ['guru']])->assertOk();
        $this->post('/login', ['username' => 'g008', 'password' => 'PasswordPusat123'])->assertSessionHasErrors('username');
    }

    public function test_pusat_mati_kontak_diantrekan_lalu_terkirim_dan_login_pusat_memberi_pesan(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('mati'));
        $this->get('/sso/login')->assertRedirect('/login')->assertSessionHas('error', fn ($m) => str_contains($m, 'tidak bisa dihubungi'));

        $s = Student::find(1);
        $s->update(['pusat_uuid' => 's-1']);
        $this->actingAs($this->admin)->put(route('admin.students.update', $s), ['nama' => $s->nama, 'jk' => $s->jk, 'class_id' => $s->class_id, 'status' => 'aktif',
            'nama_ortu' => 'Bu Siti', 'telp_ortu' => '081234567890'])->assertSessionHas('warning');
        $this->assertSame('Bu Siti', $s->fresh()->nama_ortu);
        $this->assertTrue(\App\Services\PusatAntrean::ada('kontak', 's-1'));
        $this->get('/admin/pusat')->assertOk()->assertSee('Menunggu dikirim ke Pusat');

        // Pusat hidup lagi; sinkron mengirim antrean dulu dan tidak menimpa kontak dengan data lama Pusat
        $this->fakePusat([$this->siswaPusat('s-1', 'N-1', ['nama_ortu' => 'Lama', 'no_hp_ortu' => '0811111111'])]);
        $this->post('/admin/pusat/terapkan')->assertSessionHas('success');
        $this->assertFalse(\App\Services\PusatAntrean::ada('kontak', 's-1'));
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/api/v1/siswa/s-1/kontak') && $r['nama_ortu'] === 'Bu Siti');
    }

    public function test_sinkron_idempoten_dan_kontak_tidak_dihapus_bila_kosong_di_pusat(): void
    {
        Student::find(1)->update(['nama_ortu' => 'Bu Sari', 'telp_ortu' => '628111']);
        $this->fakePusat([$this->siswaPusat('s-1', 'N-1')]);
        $this->actingAs($this->admin)->post('/admin/pusat/terapkan');
        $this->assertSame('Bu Sari', Student::find(1)->nama_ortu);

        $this->post('/admin/pusat/terapkan')->assertSessionHas('hasil', fn ($h) => $h['siswa']['baru'] === 0 && $h['siswa']['ubah'] === 0 && $h['kelas']['ubah'] === 0);
    }

    public function test_siswa_hilang_di_pusat_diberi_status_keluar_bukan_dihapus(): void
    {
        Student::find(2)->update(['pusat_uuid' => 's-2']);
        Student::find(1)->update(['pusat_uuid' => 's-1']);
        $this->fakePusat([$this->siswaPusat('s-1', 'N-1')]); // s-2 tidak ada lagi
        $this->actingAs($this->admin)->post('/admin/pusat/terapkan')->assertSessionHas('success');

        $this->assertSame('keluar', Student::find(2)->status);
        $this->assertSame(6, Student::count());
    }

    public function test_pengaman_pusat_kosong_dan_penonaktifan_massal(): void
    {
        Student::all()->each(fn ($s) => $s->update(['pusat_uuid' => 'x'.$s->id]));
        $this->fakePusat([]);
        $this->actingAs($this->admin)->post('/admin/pusat/terapkan')->assertSessionHas('error', fn ($m) => str_contains($m, '0 siswa'));
        $this->assertSame(6, Student::where('status', 'aktif')->count());

        // 30 siswa tertaut, 12 hilang di Pusat -> melewati batas aman (maks(10, 20%)) tanpa "paksa".
        foreach (range(10, 39) as $i) {
            Student::create(['id' => $i, 'nis' => "B-$i", 'nama' => "B $i", 'jk' => 'L', 'class_id' => 1, 'status' => 'aktif', 'pusat_uuid' => "b-$i"]);
        }
        $ada = array_map(fn ($i) => $this->siswaPusat("b-$i", "B-$i"), range(10, 27));
        $ada[] = $this->siswaPusat('x1', 'N-1');
        $this->fakePusat($ada);
        $this->post('/admin/pusat/terapkan')->assertSessionHas('error')->assertSessionHas('butuh_paksa', true);
        $this->assertSame(0, Student::where('status', 'keluar')->count());
        $this->post('/admin/pusat/terapkan', ['paksa' => 1])->assertSessionHas('success');
        $this->assertGreaterThan(10, Student::where('status', 'keluar')->count());
    }

    public function test_halaman_admin_hanya_untuk_admin(): void
    {
        $this->actingAs($this->wali)->get('/admin/pusat')->assertForbidden();
        $this->actingAs($this->wali)->post('/admin/pusat/terapkan')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/pusat')->assertOk()->assertSee('Uji koneksi')->assertSee('Sinkron sekarang');
    }

    // ---- Webhook ----

    private function webhook(string $event, array $data, ?string $delivery = null, ?string $rahasia = null)
    {
        $badan = json_encode(['id' => 'd', 'event' => $event, 'data' => $data]);
        $waktu = time();

        return $this->call('POST', '/api/pusat/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PUSAT_EVENT' => $event, 'HTTP_X_PUSAT_DELIVERY' => $delivery ?? bin2hex(random_bytes(8)),
            'HTTP_X_PUSAT_TIMESTAMP' => (string) $waktu,
            'HTTP_X_PUSAT_SIGNATURE' => PusatTanda::buat($rahasia ?? 'whsec', $waktu, $badan),
        ], $badan);
    }

    public function test_webhook_tanda_tangan_salah_ditolak(): void
    {
        $this->webhook('ping', [], null, 'rahasia-lain')->assertStatus(401);
        $this->postJson('/api/pusat/webhook', [])->assertStatus(401);
    }

    public function test_webhook_idempoten_dan_data_induk_hanya_menandai_menunggu(): void
    {
        $this->webhook('siswa.diperbarui', ['uuid' => 's-1'], 'dl-1')->assertOk();
        $this->webhook('siswa.diperbarui', ['uuid' => 's-1'], 'dl-1')->assertOk()->assertJson(['duplikat' => true]);
        $this->assertSame('1', (string) PusatKlien::baca('perubahan_menunggu'));
        $this->webhook('kelas.diperbarui', ['uuid' => 'k-1'], 'dl-2')->assertOk();
        $this->assertSame('2', (string) PusatKlien::baca('perubahan_menunggu'));
        $this->assertSame(6, Student::count()); // tidak ada data yang diubah
    }

    public function test_webhook_pencabutan_akses_menonaktifkan_akun_langsung(): void
    {
        $this->wali->update(['pusat_uuid' => 'u-1']);
        $this->webhook('akun.diperbarui', ['uuid' => 'u-1', 'aktif' => true, 'peran' => ['guru']])->assertOk();
        $this->assertTrue($this->wali->fresh()->is_active);

        $this->webhook('akses.diperbarui', ['uuid' => 'u-1', 'aktif' => true, 'peran' => []])->assertOk();
        $this->assertFalse($this->wali->fresh()->is_active);

        $this->guru->update(['pusat_uuid' => 'u-3']);
        $this->webhook('akun.diperbarui', ['uuid' => 'u-3', 'aktif' => false, 'peran' => ['guru']])->assertOk();
        $this->assertFalse($this->guru->fresh()->is_active);
    }

    public function test_akses_yang_dicabut_pusat_pulih_saat_login_lagi_tetapi_nonaktif_manual_tidak(): void
    {
        $this->wali->update(['pusat_uuid' => 'u-1']);
        $this->webhook('akses.diperbarui', ['uuid' => 'u-1', 'aktif' => true, 'peran' => []])->assertOk();
        $this->assertFalse($this->wali->fresh()->is_active);

        $this->loginViaPusat($this->info(['peran' => ['guru']]))->assertRedirect(route('dashboard'));
        $this->assertTrue($this->wali->fresh()->is_active);

        $this->guru->update(['pusat_uuid' => 'u-3', 'is_active' => false]); // dinonaktifkan manual oleh admin
        auth()->logout(); // login berikutnya sebagai tamu
        $this->loginViaPusat($this->info(['sub' => 'u-3', 'username' => $this->guru->username, 'peran' => ['guru']]))->assertRedirect(route('login'));
        $this->assertFalse($this->guru->fresh()->is_active);
    }

    public function test_webhook_mati_bila_integrasi_nonaktif(): void
    {
        config(['pusat.enabled' => false]);
        $this->webhook('ping', [])->assertNotFound();
    }

    // ---- Ekspor ----

    public function test_ekspor_menghasilkan_empat_csv_format_pusat(): void
    {
        $dir = storage_path('framework/testing/pusat-ekspor');
        $hasil = PusatEkspor::tulis($dir);
        $this->assertCount(4, $hasil);

        $kelas = file($dir.'/2-kelas.csv', FILE_IGNORE_NEW_LINES);
        $this->assertSame("\xEF\xBB\xBFnama;tingkat;jurusan;tahun_ajaran;wali", $kelas[0]);
        $this->assertStringContainsString('"X RPL 1";X;RPL;;wali', implode("\n", $kelas)); // wali kelas -> username
        $siswa = file_get_contents($dir.'/3-siswa.csv');
        $this->assertStringContainsString('N-1;;"Siswa 1";L;"X RPL 1";aktif', $siswa);
        $akun = file_get_contents($dir.'/4-akun.csv');
        $this->assertStringContainsString('wali;Wali;;wali;guru|wali_kelas', $akun);
        $this->assertStringContainsString('admin;Admin;;admin;admin', $akun);
    }

    public function test_tingkat_dan_status_ekspor(): void
    {
        $this->assertSame('XII', PusatEkspor::tingkat('XII DKV 2'));
        $this->assertSame('X', PusatEkspor::tingkat('X RPL 1'));
        $this->assertSame('', PusatEkspor::tingkat('Kelas Khusus'));
        $this->assertSame('keluar', PusatEkspor::status('berhenti'));
        $this->assertSame('lulus', PusatEkspor::status('lulus'));
    }
}
