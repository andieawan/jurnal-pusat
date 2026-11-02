<?php

namespace App\Console\Commands;

use App\Services\PusatKlien;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Periksa extends Command
{
    protected $signature = 'absensi:periksa {--cepat : Lewati pemeriksaan database dan jaringan}';

    protected $description = 'Periksa kesiapan produksi (.env, database, izin folder, sambungan ke Pusat). Aman dijalankan kapan saja.';

    private int $gagal = 0;

    private int $peringatan = 0;

    private function ok(string $t): void
    {
        $this->line("  <info>[OK]</info>     $t");
    }

    private function peringatkan(string $t, string $saran = ''): void
    {
        $this->peringatan++;
        $this->line("  <comment>[PERINGATAN]</comment> $t".($saran ? "\n             → $saran" : ''));
    }

    private function gagalkan(string $t, string $saran = ''): void
    {
        $this->gagal++;
        $this->line("  <error>[GAGAL]</error>  $t".($saran ? "\n             → $saran" : ''));
    }

    public function handle(): int
    {
        $prod = app()->environment('production');
        $url = (string) config('app.url');
        $this->info('Pemeriksaan Absensi ('.config('app.env').')');

        $this->newLine();
        $this->line('<options=bold>PHP & berkas</>');
        version_compare(PHP_VERSION, '8.3.0', '>=') ? $this->ok('PHP '.PHP_VERSION) : $this->gagalkan('PHP '.PHP_VERSION.' terlalu lama', 'Butuh PHP 8.3+');
        $ext = array_values(array_filter(['pdo_mysql', 'mbstring', 'openssl', 'curl', 'xml', 'zip'], fn ($e) => ! extension_loaded($e)));
        $ext ? $this->gagalkan('Ekstensi PHP belum ada: '.implode(', ', $ext), 'sudo apt install '.implode(' ', array_map(fn ($e) => 'php-'.str_replace('pdo_mysql', 'mysql', $e), $ext)).' lalu muat ulang php-fpm') : $this->ok('Ekstensi PHP lengkap');
        is_file(base_path('vendor/autoload.php')) ? $this->ok('vendor/ ada') : $this->gagalkan('vendor/ tidak ada', 'composer install --no-dev --optimize-autoloader');
        foreach (['storage', 'storage/logs', 'storage/framework', 'bootstrap/cache'] as $d) {
            if (! is_writable(base_path($d))) {
                $this->gagalkan("Folder $d tidak bisa ditulis user ini (".get_current_user().')', 'sudo chown -R $USER:www-data storage bootstrap/cache && sudo chmod -R ug+rwX storage bootstrap/cache');
            }
        }

        $this->newLine();
        $this->line('<options=bold>.env</>');
        config('app.key') ? $this->ok('APP_KEY terisi') : $this->gagalkan('APP_KEY kosong', 'php artisan key:generate --force');
        if ($prod) {
            config('app.debug') ? $this->gagalkan('APP_DEBUG=true di produksi', 'Ubah ke APP_DEBUG=false') : $this->ok('APP_DEBUG=false');
            str_starts_with($url, 'https://') ? $this->ok('APP_URL memakai https') : $this->peringatkan("APP_URL bukan https: $url", 'Pasang HTTPS lalu ubah APP_URL');
            if (str_starts_with($url, 'https://') && ! config('session.secure')) {
                $this->peringatkan('SESSION_SECURE_COOKIE belum true padahal HTTPS', 'Set SESSION_SECURE_COOKIE=true');
            }
            if (str_starts_with($url, 'http://') && config('session.secure')) {
                $this->gagalkan('SESSION_SECURE_COOKIE=true tetapi APP_URL http — login akan terus gagal', 'Pakai HTTPS atau set SESSION_SECURE_COOKIE=false');
            }
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test')) {
                $this->peringatkan('APP_URL menunjuk alamat lokal', 'Isi domain asli');
            }
        }
        config('app.trusted_proxies') ? $this->ok('TRUSTED_PROXIES = '.config('app.trusted_proxies')) : $this->peringatkan('TRUSTED_PROXIES kosong', 'Jika di belakang NPMplus, isi IP-nya agar HTTPS & IP pengunjung terbaca benar');

        if (! $this->option('cepat')) {
            $this->newLine();
            $this->line('<options=bold>Database</>');
            try {
                DB::connection()->getPdo();
                $this->ok('Terhubung ke database "'.DB::connection()->getDatabaseName().'"');
                if (! Schema::hasTable('migrations')) {
                    $this->gagalkan('Tabel belum dibuat', 'php artisan migrate --force');
                } else {
                    $belum = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'))->diff(DB::table('migrations')->pluck('migration'));
                    $belum->isEmpty() ? $this->ok('Semua migrasi sudah dijalankan') : $this->gagalkan($belum->count().' migrasi belum dijalankan', 'php artisan migrate --force');
                }
            } catch (\Throwable $e) {
                $this->gagalkan('Tidak bisa terhubung ke database: '.mb_substr($e->getMessage(), 0, 160), 'Periksa DB_* di .env (DB_PASSWORD tidak boleh kosong), lalu php artisan config:clear');
            }
        }

        $this->newLine();
        $this->line('<options=bold>Sambungan ke Pusat</>');
        if (! config('pusat.enabled')) {
            $this->line('  <comment>[INFO]</comment>   PUSAT_ENABLED=false — aplikasi berjalan mandiri (login lokal). Untuk menyambung: lihat docs/PUSAT.md');
        } else {
            foreach (['url' => 'PUSAT_URL', 'client_id' => 'PUSAT_CLIENT_ID', 'client_secret' => 'PUSAT_CLIENT_SECRET', 'webhook_secret' => 'PUSAT_WEBHOOK_SECRET'] as $k => $env) {
                config("pusat.$k") ? $this->ok("$env terisi") : $this->gagalkan("$env kosong", 'Salin dari halaman Pusat → Aplikasi');
            }
            if ($prod && ! config('pusat.verify_ssl')) {
                $this->gagalkan('PUSAT_VERIFY_SSL=false di produksi', 'Hapus baris itu; pakai sertifikat sah (Let\'s Encrypt/NPMplus)');
            }
            if ($prod && preg_match('~^http://|localhost|127\.0\.0\.1|\.test~', (string) config('pusat.url'))) {
                $this->peringatkan('PUSAT_URL menunjuk alamat lokal/http: '.config('pusat.url'), 'Isi alamat produksi Pusat (https)');
            }
            if (! $this->option('cepat') && PusatKlien::siap()) {
                try {
                    $r = PusatKlien::http()->timeout(10)->get('/api/v1/ping');
                    $r->successful() ? $this->ok('Pusat menjawab /api/v1/ping (HTTP '.$r->status().')') : $this->gagalkan('Pusat menjawab HTTP '.$r->status(), $r->status() === 401 ? 'Client ID/secret salah, atau nginx Pusat belum meneruskan HTTP_AUTHORIZATION' : 'Periksa PUSAT_URL & log Pusat');
                } catch (\Throwable $e) {
                    $m = $e->getMessage();
                    $this->gagalkan('Pusat tidak terjangkau: '.mb_substr($m, 0, 140), str_contains($m, 'error 60') ? 'Sertifikat tidak dipercaya: pakai sertifikat sah (produksi) atau PUSAT_CA_BUNDLE (lokal)' : (str_contains($m, 'error 28') ? 'Timeout: server Pusat mati/terblokir firewall, atau (lokal) PHP satu-pekerja saling menunggu — pakai domain/port terpisah' : 'Periksa PUSAT_URL, DNS, dan firewall'));
                }
            }
        }

        $this->newLine();
        if ($this->gagal) {
            $this->error("{$this->gagal} masalah harus diperbaiki, {$this->peringatan} peringatan.");

            return self::FAILURE;
        }
        $this->info($this->peringatan ? "Siap, dengan {$this->peringatan} peringatan." : 'Semua siap untuk produksi.');

        return self::SUCCESS;
    }
}
