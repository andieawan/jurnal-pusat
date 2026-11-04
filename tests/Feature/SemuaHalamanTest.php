<?php

namespace Tests\Feature;

use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

/**
 * Jelajah semua halaman: mulai dari beranda tiap peran, ikuti setiap tautan di aplikasi, dan pastikan tidak ada
 * halaman yang galat (5xx) atau tautan yang mengarah ke halaman tidak ada (404) / terlarang (403) bagi peran itu.
 * Data: DemoSeeder. Set JELAJAH_SIMPAN=folder untuk menyimpan HTML tiap halaman (bahan tangkapan layar).
 */
class SemuaHalamanTest extends TestCase
{
    /** @return array{masalah: list<string>, halaman: array<string,string>} */
    private function jelajah($user, string $mulai, int $maks = 250): array
    {
        $antre = [[$mulai, '(awal)']];
        $lihat = [];
        $pola = [];
        $masalah = [];
        $halaman = [];
        while ($antre && count($lihat) < $maks) {
            [$url, $dari] = array_shift($antre);
            if (isset($lihat[$url])) {
                continue;
            }
            $lihat[$url] = true;
            $res = ($user ? $this->actingAs($user) : $this)->get($url);
            $s = $res->getStatusCode();
            if ($s >= 500 || in_array($s, [403, 404, 405], true)) {
                $e = $res->exception;
                $masalah[] = "$s $url (dari $dari)".($e ? ' — '.class_basename($e).': '.mb_substr($e->getMessage(), 0, 200).' @ '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine() : '');

                continue;
            }
            if ($s >= 300 && $s < 400) {
                $loc = $this->lokal((string) $res->headers->get('Location'));
                $loc && ! isset($lihat[$loc]) && $antre[] = [$loc, $url];

                continue;
            }
            if (! str_contains((string) $res->headers->get('Content-Type'), 'html')) {
                continue;
            }
            $html = (string) $res->getContent();
            $halaman[$url] = $html;
            preg_match_all('/<a\b[^>]*\bhref="([^"]+)"/i', $html, $m);
            foreach ($m[1] as $h) {
                $h = $this->lokal(html_entity_decode($h));
                if (! $h || isset($lihat[$h]) || preg_match('#/(logout|sso/|unduh|download)|^/keluar$#', $h)) {
                    continue;
                }
                $p = preg_replace(['/\d+/', '/\?.*/'], ['N', '?'], $h); // batasi variasi id/kueri per pola
                if (($pola[$p] = ($pola[$p] ?? 0) + 1) <= 3) {
                    $antre[] = [$h, $url];
                }
            }
        }

        return ['masalah' => $masalah, 'halaman' => $halaman];
    }

    private function lokal(string $h): ?string
    {
        $h = trim($h);
        foreach ([rtrim(url('/'), '/'), rtrim((string) config('app.url'), '/'), 'http://localhost', 'https://localhost'] as $akar) {
            if ($akar !== '' && str_starts_with($h, $akar)) {
                $h = substr($h, strlen($akar)) ?: '/';
                break;
            }
        }
        if ($h === '' || $h[0] !== '/' || str_starts_with($h, '//')) {
            return null;
        }

        return strtok($h, '#') ?: null;
    }

    private function simpan(string $peran, array $halaman): void
    {
        if (! ($dir = getenv('JELAJAH_SIMPAN'))) {
            return;
        }
        @mkdir("$dir/$peran", 0777, true);
        foreach ($halaman as $url => $html) {
            file_put_contents("$dir/$peran/".(trim(preg_replace('/[^a-z0-9]+/i', '_', $url), '_') ?: 'beranda').'.html', $html);
        }
    }

    public function test_semua_halaman_bisa_dibuka_tiap_peran(): void
    {
        Pairing::query()->delete();
        Student::query()->delete();
        SchoolClass::query()->delete();
        Subject::query()->delete();
        (new DemoSeeder)->run();
        $peran = ['admin' => $this->admin];
        foreach (['wali' => 'ibu.siti', 'guru' => 'pak.hendra', 'kepsek' => 'bu.ratna', 'bk' => 'bu.maya', 'tu' => 'bu.tata'] as $nama => $un) {
            $peran[$nama] = User::where('username', $un)->firstOrFail();
        }
        $semua = [];
        foreach ($peran as $nama => $u) {
            $h = $this->jelajah($u, '/');
            $this->simpan($nama, $h['halaman']);
            $this->assertGreaterThan(0, count($h['halaman']), "$nama tidak membuka satu halaman pun");
            foreach ($h['masalah'] as $m) {
                $semua[] = "[$nama] $m";
            }
        }
        $this->app["auth"]->forgetGuards();
        $h = $this->jelajah(null, '/login', 5); // halaman masuk untuk tamu
        $this->simpan('tamu', $h['halaman']);
        foreach ($h['masalah'] as $m) {
            $semua[] = "[tamu] $m";
        }
        $this->assertSame([], $semua, "Halaman bermasalah:\n".implode("\n", $semua));
    }
}
