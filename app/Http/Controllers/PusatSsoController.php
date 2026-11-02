<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\PusatAkun;
use App\Services\PusatKlien;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Login lewat Pusat (authorization code + PKCE). Login lokal tetap tersedia sebagai cadangan. */
class PusatSsoController extends Controller
{
    public function mulai(Request $request)
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap()) {
            return redirect()->route('login')->with('error', 'Login lewat Pusat belum diaktifkan.');
        }
        if (! PusatKlien::hidup()) {
            return redirect()->route('login')->with('error', 'Pusat Sekolah sedang tidak bisa dihubungi. Masuk dengan username & password di bawah (akun Pusat memakai password Pusat).');
        }
        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('pusat_sso', ['state' => $state, 'verifier' => $verifier, 'waktu' => time()]);
        $tantangan = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away(config('pusat.url').'/sso/authorize?'.http_build_query([
            'client_id' => config('pusat.client_id'),
            'redirect_uri' => route('sso.callback'),
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $tantangan,
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request)
    {
        if (! PusatKlien::aktif() || ! PusatKlien::siap()) {
            return redirect()->route('login');
        }
        $sesi = $request->session()->pull('pusat_sso');
        $kode = (string) $request->query('code', '');
        if (! $sesi || $kode === '' || time() - ($sesi['waktu'] ?? 0) > 600
            || ! hash_equals((string) $sesi['state'], (string) $request->query('state', ''))) {
            return redirect()->route('login')->with('error', 'Sesi login lewat Pusat tidak valid atau kedaluwarsa. Coba lagi.');
        }

        try {
            $res = PusatKlien::http()->asForm()->post('/api/sso/token', [
                'grant_type' => 'authorization_code',
                'code' => $kode,
                'redirect_uri' => route('sso.callback'),
                'code_verifier' => $sesi['verifier'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', 'Pusat tidak dapat dihubungi. Gunakan login biasa untuk sementara.');
        }
        if (! $res->ok() || ! is_array($res->json('user'))) {
            return redirect()->route('login')->with('error', 'Pusat menolak login Anda. Coba lagi atau hubungi administrator.');
        }

        [$user, $galat] = PusatAkun::masuk($res->json('user'));
        if (! $user) {
            Audit::log('Login Pusat Ditolak', 'Akun Guru', (string) $res->json('user.username'), (string) $galat);

            return redirect()->route('login')->with('error', $galat);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('via_pusat', true);
        // Daftar aplikasi lain milik akun ini (menu "Pindah ke aplikasi lain"); hanya nama & tautan lewat Pusat.
        $request->session()->put('aplikasi_lain', collect((array) ($res->json('user.aplikasi_lain') ?? []))
            ->filter(fn ($a) => is_array($a) && isset($a['nama'], $a['url']))->map(fn ($a) => ['nama' => (string) $a['nama'], 'url' => (string) $a['url']])->take(20)->values()->all());
        Audit::log('Login', 'Akun Guru', $user->nama, 'Login berhasil lewat Pusat');

        return redirect()->intended(route('dashboard'));
    }
}
