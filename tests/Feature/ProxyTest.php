<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

/** (URL uji sengaja http: APP_URL di .env.example bisa https.) TRUSTED_PROXIES dibaca dari config (tetap berlaku setelah config:cache), bukan env() di bootstrap. */
class ProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_https_dan_ip_asli_terbaca_dari_proxy_tepercaya(): void
    {
        config(['app.trusted_proxies' => '10.9.9.9']);
        (new AppServiceProvider($this->app))->boot();

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->get('http://localhost/up', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])->assertOk();
        $this->assertTrue($this->app['request']->isSecure());
        $this->assertSame('203.0.113.7', $this->app['request']->ip());
    }

    public function test_proxy_tidak_tepercaya_diabaikan(): void
    {
        config(['app.trusted_proxies' => '10.9.9.9']);
        (new AppServiceProvider($this->app))->boot();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->get('http://localhost/up', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])->assertOk();
        $this->assertFalse($this->app['request']->isSecure());
        $this->assertSame('198.51.100.1', $this->app['request']->ip());
    }
}
