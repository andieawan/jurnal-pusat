<?php

namespace Tests\Feature;

use App\Models\PerangkatAjar;
use App\Models\User;
use App\Support\Passwords;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class PerangkatAjarTest extends TestCase
{
    private User $guru2; // mengampu mapel 1 di kelas 2 (X TKJ 1), bukan MGMP

    protected function setUp(): void
    {
        parent::setUp();
        $this->wali->update(['roles' => ['guru', 'mgmp']]); // tim MGMP mapel 1
        $this->guru2 = User::create(['username' => 'guru2', 'nama' => 'Guru Dua', 'password_hash' => Passwords::make('password123'), 'roles' => ['guru'],
            'is_active' => true, 'subjects' => [1], 'classes' => [2]]);
    }

    private function isian(array $tambah = []): array
    {
        return $tambah + [
            'subject' => 1, 'tingkat' => 'X', 'topik' => 'Teks prosedur', 'tp' => "Menyusun teks prosedur\nMempresentasikan teks prosedur",
            'alokasi' => '2 x 45 menit', 'pertemuan' => 2, 'model' => 'Problem Based Learning (PBL)',
            'metode' => ['Diskusi kelompok'], 'dimensi' => ['Penalaran kritis', 'Komunikasi'], 'dokumen' => ['modul', 'lkpd', 'asesmen', 'bacaan', 'tindak'],
        ];
    }

    private function draf(array $tambah = []): PerangkatAjar
    {
        $this->actingAs($this->wali)->post('/perangkat', $this->isian($tambah))->assertRedirect();

        return PerangkatAjar::where('jenis', 'mgmp')->latest('id')->firstOrFail();
    }

    private function gemini(array $isi = ['tujuan' => ['TP dari AI'], 'kunci_asing' => 'x']): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($isi)]]]]]])]);
    }

    public function test_mgmp_menyusun_draf_lalu_guru_mapel_mengambil_untuk_kelasnya(): void
    {
        $this->actingAs($this->guru2)->get('/perangkat/baru')->assertForbidden(); // bukan MGMP
        $this->actingAs($this->wali)->get('/perangkat/baru')->assertOk()->assertSee('Draf MGMP Baru')->assertDontSee('name="pakai_ai"', false);
        $d = $this->draf();
        $this->assertNull($d->class_id);
        $this->assertSame(['X', 'draf', 'E', 'Tim MGMP Bahasa Indonesia'], [$d->tingkat, $d->status, $d->isian['fase'], $d->isian['guru']]);
        $this->assertCount(2, $d->hasil['modul']['pertemuan']);
        $this->get(route('perangkat.show', $d))->assertOk()->assertSee('MODUL AJAR')->assertSee('Orientasi pada masalah')->assertSee('Tandai siap dipakai guru');

        // Masih draf: guru lain belum melihatnya
        $this->actingAs($this->guru2)->get('/perangkat')->assertOk()->assertDontSee('Teks prosedur');
        $this->get(route('perangkat.show', $d))->assertForbidden();

        $this->actingAs($this->wali)->post(route('perangkat.status', $d))->assertSessionHas('success');
        $this->assertSame('siap', $d->fresh()->status);

        $this->actingAs($this->guru2)->get('/perangkat')->assertSee('Teks prosedur')->assertSee('Lihat & ambil', false);
        $this->get(route('perangkat.show', $d))->assertOk()->assertSee('Ambil jadi perangkat ajar saya')->assertSee('X TKJ 1')->assertDontSee('Ubah isi</a>', false);
        $this->get(route('perangkat.edit', [$d, 'modul']))->assertForbidden();
        $this->post(route('perangkat.ambil', $d), ['class' => 1])->assertSessionHas('error'); // kelas 1 bukan kelasnya
        $this->post(route('perangkat.ambil', $d), ['class' => 2])->assertRedirect();
        $milik = PerangkatAjar::where('jenis', 'guru')->firstOrFail();
        $this->assertSame([$this->guru2->id, 2, $d->id, 'Guru Dua', 'X TKJ 1'], [$milik->user_id, $milik->class_id, $milik->dari_id, $milik->isian['guru'], $milik->isian['kelas']]);
        $this->assertSame($d->hasil, $milik->hasil);
        $this->post(route('perangkat.ambil', $d), ['class' => 2])->assertSessionHas('info'); // tidak ganda
        $this->assertSame(1, PerangkatAjar::where('jenis', 'guru')->count());

        $this->get(route('perangkat.show', $milik))->assertOk()->assertSee('Guru Dua')->assertSee('Ubah isi');
        $this->put(route('perangkat.identitas', $milik), ['nip' => '1987', 'konsentrasi' => 'TKJ', 'alokasi' => '3 x 45 menit'])->assertSessionHas('success');
        $this->assertSame('1987', $milik->fresh()->isian['nip']);
        $this->get('/mengajar?subject=1&class=2')->assertSee('Teks prosedur');

        // Guru mapel lain & tidak ada akses; kepsek boleh melihat
        $this->actingAs($this->guru)->get(route('perangkat.show', $d))->assertForbidden();
        $this->actingAs($this->guru)->get(route('perangkat.show', $milik))->assertForbidden();
        $this->actingAs($this->kepsek)->get(route('perangkat.show', $milik))->assertOk()->assertDontSee('Ubah isi</a>', false);
        $this->actingAs($this->bk)->get('/perangkat')->assertForbidden();
    }

    public function test_ubah_unduh_word_dan_hapus(): void
    {
        $p = $this->draf();
        $this->get(route('perangkat.edit', [$p, 'asesmen']))->assertOk()->assertSee('Tambah baris baru');
        $this->put(route('perangkat.update', [$p, 'asesmen']), ['isi' => [
            'diagnostik' => [['pertanyaan' => 'Apa itu prosedur?', 'tindak_lanjut' => 'Contoh resep'], ['pertanyaan' => '', 'tindak_lanjut' => '']],
            'formatif' => [['indikator' => 'Aktif bertanya', 'dimensi' => 'Komunikasi']],
            'rubrik' => [['kriteria' => 'Struktur teks', 'baru_berkembang' => 'a', 'layak' => 'b', 'cakap' => 'c', 'mahir' => 'd', 'asing' => 'x']],
        ]])->assertRedirect();
        $a = $p->fresh()->hasil['asesmen'];
        $this->assertCount(1, $a['diagnostik']);
        $this->assertArrayNotHasKey('asing', $a['rubrik'][0]);
        $this->put(route('perangkat.update', [$p, 'modul']), ['isi' => ['tujuan' => "Satu\n\nDua", 'pertemuan' => [['judul' => 'P1', 'memahami' => "a\nb"]]]]);
        $this->assertSame(['Satu', 'Dua'], $p->fresh()->hasil['modul']['tujuan']);
        $this->assertSame(['a', 'b'], $p->fresh()->hasil['modul']['pertemuan'][0]['memahami']);

        $w = $this->get(route('perangkat.unduh', [$p, 'asesmen']))->assertOk();
        $this->assertStringContainsString('application/msword', $w->headers->get('Content-Type'));
        $this->assertStringContainsString('Struktur teks', $w->getContent());

        $this->delete(route('perangkat.destroy', $p))->assertRedirect('/perangkat');
        $this->assertSame(0, PerangkatAjar::count());
    }

    public function test_kunci_pribadi_mgmp_dipakai_bila_env_kosong_dan_tersimpan_terenkripsi(): void
    {
        config(['absensi.ai.key' => '']);
        $this->actingAs($this->guru2)->get('/perangkat/kunci')->assertForbidden();
        $this->actingAs($this->wali)->get('/perangkat/kunci')->assertOk()->assertSee('belum ada');
        $this->put('/perangkat/kunci', ['ai_key' => 'salah kunci'])->assertSessionHasErrors('ai_key');
        $this->put('/perangkat/kunci', ['ai_key' => 'AIzaKunciPribadiGuru_1234567890'])->assertSessionHas('success');
        $mentah = DB::table('users')->where('id', $this->wali->id)->value('ai_key');
        $this->assertStringNotContainsString('AIzaKunciPribadiGuru', (string) $mentah);
        $this->get('/perangkat/kunci')->assertSee('…7890');

        $this->gemini();
        $this->get('/perangkat/baru')->assertSee('name="pakai_ai"', false);
        $d = $this->draf(['pakai_ai' => 1]);
        $this->get(route('perangkat.show', ['perangkat' => $d, 'isi_ai' => 1]))->assertSee('data-ai-antre', false);
        $this->postJson(route('perangkat.ai', [$d, 'modul']))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(['TP dari AI'], $d->fresh()->hasil['modul']['tujuan']);
        $this->assertArrayNotHasKey('kunci_asing', $d->fresh()->hasil['modul']);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-goog-api-key', 'AIzaKunciPribadiGuru_1234567890') && ! str_contains($r->url(), 'key=')
            && str_contains(json_encode($r->data()), 'Teks prosedur') && ! str_contains(json_encode($r->data()), 'Wali'));

        // Guru biasa tidak memakai AI: draf hanya MGMP; salinan guru tanpa tombol AI
        $this->actingAs($this->guru2)->postJson(route('perangkat.ai', [$d, 'lkpd']))->assertForbidden();

        // Kunci sekolah di .env diutamakan
        config(['absensi.ai.key' => 'kunci-sekolah']);
        $this->actingAs($this->wali)->postJson(route('perangkat.ai', [$d, 'lkpd']))->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-goog-api-key', 'kunci-sekolah'));

        $this->put('/perangkat/kunci', ['hapus' => 1]);
        $this->assertNull($this->wali->fresh()->ai_key);
    }

    public function test_bahan_ajar_diunggah_dan_dipakai_ai(): void
    {
        Storage::fake('local');
        config(['absensi.ai.key' => 'kunci-uji']);
        $this->gemini();
        $docx = tempnam(sys_get_temp_dir(), 'dx');
        $z = new ZipArchive;
        $z->open($docx, ZipArchive::OVERWRITE);
        $z->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Langkah membuat teks prosedur dari buku sekolah</w:t></w:r></w:p></w:body></w:document>');
        $z->close();

        $d = $this->draf(['bahan' => [
            UploadedFile::fake()->createWithContent('modul.pdf', '%PDF-1.4 isi modul'),
            new UploadedFile($docx, 'ringkasan.docx', null, null, true),
        ]]);
        $this->assertSame(['modul.pdf', 'ringkasan.docx'], array_column($d->isian['bahan'], 'nama'));
        Storage::disk('local')->assertExists($d->isian['bahan'][0]['path']);
        $this->get(route('perangkat.show', $d))->assertSee('modul.pdf')->assertSee('ringkasan.docx');
        $this->get(route('perangkat.bahan.unduh', [$d, 0]))->assertOk();

        $this->postJson(route('perangkat.ai', [$d, 'bacaan']))->assertOk();
        Http::assertSent(function (HttpRequest $r) {
            $parts = $r->data()['contents'][0]['parts'];

            return ($parts[1]['inlineData']['mimeType'] ?? '') === 'application/pdf'
                && base64_decode($parts[1]['inlineData']['data']) === '%PDF-1.4 isi modul'
                && str_contains($parts[2]['text'], 'Langkah membuat teks prosedur dari buku sekolah');
        });

        $this->post(route('perangkat.bahan', $d), ['bahan' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors();
        $path = $d->isian['bahan'][0]['path'];
        $this->delete(route('perangkat.bahan.hapus', [$d, 0]))->assertSessionHas('success');
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(['ringkasan.docx'], array_column($d->fresh()->isian['bahan'], 'nama'));
        $this->actingAs($this->guru2)->delete(route('perangkat.bahan.hapus', [$d, 0]))->assertForbidden();
    }

    public function test_ai_gagal_memberi_pesan_jelas_dan_isi_lama_tetap(): void
    {
        config(['absensi.ai.key' => 'kunci-uji']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $d = $this->draf();
        $this->postJson(route('perangkat.ai', [$d, 'lkpd']))->assertStatus(422)->assertJsonFragment(['pesan' => 'Kuota Gemini habis atau terlalu banyak permintaan. Coba lagi beberapa menit lagi.']);
        $this->assertSame('LKPD: Teks prosedur', $d->fresh()->hasil['lkpd']['judul']);
        config(['absensi.ai.key' => '']);
        $this->post(route('perangkat.ai', [$d, 'lkpd']))->assertSessionHas('error');
    }
}
