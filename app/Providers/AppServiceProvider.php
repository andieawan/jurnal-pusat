<?php

namespace App\Providers;

use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Folder kerja wajib: bila terhapus/tidak ikut terunggah (mis. folder kosong dilewati saat ekstrak zip), buat otomatis.
        foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/private', 'app/public'] as $d) {
            $path = storage_path($d);
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    public function boot(): void
    {
        // Perubahan yang tertunda karena Pusat mati dikirim ulang setelah respons selesai (tanpa perlu cron).
        if (config('pusat.enabled') && ! $this->app->runningInConsole()) {
            $this->app->terminating(fn () => \App\Services\PusatAntrean::kirimBilaPerlu());
        }

        // Proxy tepercaya dari config (lihat config/app.php: trusted_proxies).
        if ($proxi = trim((string) config('app.trusted_proxies'))) {
            \Illuminate\Http\Middleware\TrustProxies::at($proxi === '*' ? '*' : array_map('trim', explode(',', $proxi)));
        }
        if (config('absensi.trust_proxies')) {
            \Illuminate\Http\Middleware\TrustProxies::at('*'); // di balik Cloudflare / reverse proxy
        }

        // Alamat aset/tautan selalu https bila APP_URL https (produksi), walau header proxy tidak terbaca.
        // Matikan dengan APP_FORCE_HTTPS=false (mis. uji lewat http://IP-VM).
        $paksa = config('app.force_https');
        if ($paksa === null ? (app()->environment('production') && str_starts_with((string) config('app.url'), 'https://')) : filter_var($paksa, FILTER_VALIDATE_BOOLEAN)) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Gate::define('admin', fn (User $u) => $u->isAdmin());
        Gate::define('bk', fn (User $u) => $u->hasRole('bk', 'admin', 'superadmin'));
        Gate::define('tu', fn (User $u) => $u->hasRole('tu', 'admin', 'superadmin'));
        Gate::define('tu-lihat', fn (User $u) => $u->hasRole('tu', 'kepsek', 'admin', 'superadmin'));
        Gate::define('absen-mandiri', fn (User $u) => \App\Services\StaffService::selfEligible($u));
        Gate::define('bk-modul', fn (User $u) => \App\Services\BkService::canOpen($u));
        Gate::define('lihat-sekolah', fn (User $u) => $u->hasRole('kepsek', 'superadmin', 'admin', 'bk'));

        RateLimiter::for('publik', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));

        View::composer('*', function ($view) {
            $view->with('settings', SchoolSetting::current());
        });
    }
}
