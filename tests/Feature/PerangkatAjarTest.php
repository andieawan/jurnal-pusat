<?php

namespace Tests\Feature;

use App\Models\PerangkatAjar;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerangkatAjarTest extends TestCase
{
    private function isian(array $tambah = []): array
    {
        return $tambah + [
            'subject' => 1, 'class' => 1, 'topik' => 'Teks prosedur', 'tp' => "Menyusun teks prosedur\nMempresentasikan teks prosedur",
            'alokasi' => '2 x 45 menit', 'pertemuan' => 2, 'model' => 'Problem Based Learning (PBL)',
            'metode' => ['Diskusi kelompok'], 'dimensi' => ['Penalaran kritis', 'Komunikasi'], 'dokumen' => ['modul', 'lkpd', 'asesmen', 'bacaan', 'tindak'],
        ];
    }

    public function test_guru_membuat_draf_templat_lengkap_dengan_identitas_otomatis(): void
    {
        $this->actingAs($this->wali)->get('/perangkat/baru')->assertOk()->assertSee('Buat Perangkat Ajar')->assertDontSee('Isi dengan AI');
        $r = $this->post('/perangkat', $this->isian(['nip' => '1987']));
        $p = PerangkatAjar::firstOrFail();
        $r->assertRedirect(route('perangkat.show', $p));
        $this->assertSame(['modul', 'lkpd', 'asesmen', 'bacaan', 'tindak'], array_keys($p->hasil));
        $this->assertCount(2, $p->hasil['modul']['pertemuan']);
        $this->assertSame(['Menyusun teks prosedur', 'Mempresentasikan teks prosedur'], $p->hasil['modul']['tujuan']);
        $this->assertSame('E', $p->isian['fase']); // X RPL 1
        $this->assertSame('Wali', $p->isian['guru']);

        $this->get(route('perangkat.show', $p))->assertOk()->assertSee('MODUL AJAR')->assertSee('Teks prosedur')->assertSee('Orientasi pada masalah')->assertSee('NIP/NUPTK 1987', false);
        foreach (['lkpd' => 'LEMBAR KERJA', 'asesmen' => 'Rubrik penilaian sumatif', 'bacaan' => 'Glosarium', 'tindak' => 'Remedial'] as $dok => $teks) {
            $this->get(route('perangkat.show', ['perangkat' => $p, 'dok' => $dok]))->assertOk()->assertSee($teks);
        }
        $this->get('/perangkat')->assertOk()->assertSee('Teks prosedur');
        // Isian berikutnya mengingat NIP terakhir
        $this->get('/perangkat/baru')->assertSee('value="1987"', false);
    }

    public function test_hak_akses(): void
    {
        $this->actingAs($this->wali)->post('/perangkat', $this->isian());
        $p = PerangkatAjar::firstOrFail();
        $this->actingAs($this->guru)->get(route('perangkat.show', $p))->assertForbidden();
        $this->actingAs($this->guru)->get(route('perangkat.unduh', [$p, 'modul']))->assertForbidden();
        $this->get('/perangkat')->assertOk()->assertDontSee('Teks prosedur');
        $this->actingAs($this->kepsek)->get(route('perangkat.show', $p))->assertOk()->assertDontSee('Ubah isi</a>', false);
        $this->actingAs($this->kepsek)->get('/perangkat')->assertOk()->assertSee('Teks prosedur')->assertDontSee('Buat perangkat ajar');
        $this->actingAs($this->kepsek)->delete(route('perangkat.destroy', $p))->assertForbidden();
        // Mapel 2 di kelas 2 tidak diampu wali
        $this->actingAs($this->wali)->post('/perangkat', $this->isian(['subject' => 2, 'class' => 2]))->assertSessionHas('error');
        $this->assertSame(1, PerangkatAjar::count());
        $this->actingAs($this->bk)->get('/perangkat/baru')->assertForbidden();
    }

    public function test_ubah_unduh_word_dan_hapus(): void
    {
        $this->actingAs($this->wali)->post('/perangkat', $this->isian());
        $p = PerangkatAjar::firstOrFail();
        $this->get(route('perangkat.edit', [$p, 'asesmen']))->assertOk()->assertSee('Tambah baris baru');
        $this->put(route('perangkat.update', [$p, 'asesmen']), ['isi' => [
            'diagnostik' => [['pertanyaan' => 'Apa itu prosedur?', 'tindak_lanjut' => 'Contoh resep'], ['pertanyaan' => '', 'tindak_lanjut' => '']],
            'formatif' => [['indikator' => 'Aktif bertanya', 'dimensi' => 'Komunikasi']],
            'rubrik' => [['kriteria' => 'Struktur teks', 'baru_berkembang' => 'a', 'layak' => 'b', 'cakap' => 'c', 'mahir' => 'd', 'asing' => 'x']],
        ]])->assertRedirect();
        $a = $p->fresh()->hasil['asesmen'];
        $this->assertCount(1, $a['diagnostik']);           // baris kosong dibuang
        $this->assertArrayNotHasKey('asing', $a['rubrik'][0]);
        $this->put(route('perangkat.update', [$p, 'modul']), ['isi' => ['tujuan' => "Satu\n\nDua", 'pertemuan' => [['judul' => 'P1', 'memahami' => "a\nb"]]]]);
        $m = $p->fresh()->hasil['modul'];
        $this->assertSame(['Satu', 'Dua'], $m['tujuan']);
        $this->assertSame(['a', 'b'], $m['pertemuan'][0]['memahami']);

        $w = $this->get(route('perangkat.unduh', [$p, 'asesmen']))->assertOk();
        $this->assertStringContainsString('application/msword', $w->headers->get('Content-Type'));
        $this->assertStringContainsString('Struktur teks', $w->getContent());

        $this->delete(route('perangkat.destroy', $p))->assertRedirect('/perangkat');
        $this->assertSame(0, PerangkatAjar::count());
    }

    public function test_ai_mengisi_dokumen_tanpa_data_pribadi(): void
    {
        config(['absensi.ai.key' => 'kunci-uji', 'absensi.ai.model' => 'gemini-uji']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'tujuan' => ['TP dari AI'], 'pemahaman_bermakna' => 'Bermakna', 'pertanyaan_pemantik' => ['Mengapa?'],
            'pertemuan' => [['judul' => 'Pertemuan 1', 'pendahuluan' => ['Salam'], 'memahami' => ['Baca'], 'mengaplikasi' => ['Tulis'], 'merefleksi' => ['Ulas'], 'penutup' => ['Doa']]],
            'asesmen' => 'Rubrik', 'kunci_asing' => 'dibuang',
        ])]]]]]])]);
        $this->actingAs($this->wali)->get('/perangkat/baru')->assertSee('Isi dengan AI');
        $this->post('/perangkat', $this->isian(['pakai_ai' => 1, 'nip' => '1987']))->assertRedirect();
        $p = PerangkatAjar::firstOrFail();
        $this->get(route('perangkat.show', ['perangkat' => $p, 'isi_ai' => 1]))->assertSee('data-ai-antre', false);

        $this->postJson(route('perangkat.ai', [$p, 'modul']))->assertOk()->assertJson(['ok' => true]);
        $p->refresh();
        $this->assertSame(['TP dari AI'], $p->hasil['modul']['tujuan']);
        $this->assertArrayNotHasKey('kunci_asing', $p->hasil['modul']);
        $this->assertTrue($p->dariAi('modul'));
        Http::assertSent(function (HttpRequest $r) {
            $body = json_encode($r->data());

            return $r->hasHeader('x-goog-api-key', 'kunci-uji') && ! str_contains($r->url(), 'key=')
                && str_contains($r->url(), 'gemini-uji') && str_contains($body, 'Teks prosedur')
                && ! str_contains($body, 'Wali') && ! str_contains($body, '1987');
        });
    }

    public function test_ai_gagal_memberi_pesan_jelas_dan_isi_lama_tetap(): void
    {
        config(['absensi.ai.key' => 'kunci-uji']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $this->actingAs($this->wali)->post('/perangkat', $this->isian());
        $p = PerangkatAjar::firstOrFail();
        $this->postJson(route('perangkat.ai', [$p, 'lkpd']))->assertStatus(422)->assertJsonPath('ok', false)->assertJsonFragment(['pesan' => 'Kuota Gemini habis atau terlalu banyak permintaan. Coba lagi beberapa menit lagi.']);
        $this->assertSame('LKPD: Teks prosedur', $p->fresh()->hasil['lkpd']['judul']);

        config(['absensi.ai.key' => '']);
        $this->post(route('perangkat.ai', [$p, 'lkpd']))->assertSessionHas('error');
    }
}
