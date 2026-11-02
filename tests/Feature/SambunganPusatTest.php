<?php

namespace Tests\Feature;

use App\Services\Sambungan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sambungan antar-aplikasi diatur dari Pusat (Administrasi → Sambungan): diambil lewat API, disimpan terenkripsi, menimpa .env. */
class SambunganPusatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(Sambungan::berkas());
        config(['pusat.enabled' => true, 'pusat.url' => 'https://p.test', 'pusat.client_id' => 'c', 'pusat.client_secret' => 's', 'pusat.webhook_secret' => 'wh']);
    }

    protected function tearDown(): void
    {
        @unlink(Sambungan::berkas());
        parent::tearDown();
    }

    private function kirim(string $event, array $data, string $id)
    {
        $badan = json_encode(['event' => $event, 'data' => $data]);
        $t = time();

        return $this->call('POST', '/api/pusat/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PUSAT_EVENT' => $event, 'HTTP_X_PUSAT_DELIVERY' => $id,
            'HTTP_X_PUSAT_TIMESTAMP' => $t, 'HTTP_X_PUSAT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $t.'.'.$badan, 'wh'),
        ], $badan);
    }

    private function pusat(array $data, int $status = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory); // ganti balasan palsu sebelumnya
        Http::fake(['p.test/api/v1/sambungan' => Http::response(['data' => $data], $status)]);
    }

    private function s(string $jenis, bool $aktif, string $url = '', string $kunci = ''): array
    {
        return ['jenis' => $jenis, 'aktif' => $aktif, 'peran' => 'x', 'mitra' => 'm', 'url' => $url, 'kunci' => $kunci];
    }

    public function test_webhook_membuat_kunci_api_bk_dan_mematikannya(): void
    {
        $k = str_repeat('a', 40);
        $this->pusat([$this->s('absensi-bk', true, 'https://bk.test', $k)]);
        $this->kirim('sambungan.diperbarui', ['jenis' => 'absensi-bk'], 'd1')->assertOk();
        $key = \App\Models\ApiKey::where('name', Sambungan::NAMA_KUNCI_BK)->first();
        $this->assertNotNull($key);
        $this->assertTrue($key->is_active);
        $this->assertSame(['kehadiran:baca', 'kehadiran:tulis'], $key->scopes);
        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer '.$k])->assertOk();

        $this->pusat([$this->s('absensi-bk', false)]);
        $this->kirim('sambungan.diperbarui', ['jenis' => 'absensi-bk'], 'd2')->assertOk();
        $this->assertFalse($key->fresh()->is_active);
        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer '.$k])->assertStatus(401);
        $this->assertSame(1, \App\Models\ApiKey::count());
    }
}
