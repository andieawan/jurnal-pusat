<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\BackupService;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        $needsInstall = rescue(fn () => ! \Illuminate\Support\Facades\Schema::hasTable('users') || ! User::query()->exists(), true, false);

        return view('auth.login', ['switching' => false, 'needsInstall' => $needsInstall]);
    }

    public function showSwitch()
    {
        return view('auth.login', ['switching' => true]);
    }

    public function login(Request $request)
    {
        $user = $this->attempt($request);
        Auth::login($user);
        $request->session()->regenerate();
        Audit::log('Login', 'Akun Guru', $user->nama, 'Login berhasil');
        rescue(fn () => BackupService::runIfDue(24), report: true); // backup otomatis tiap 24 jam

        return redirect()->intended(route('dashboard'));
    }

    /** Ganti akun: wajib verifikasi ulang username + password akun tujuan. */
    public function switch(Request $request)
    {
        $user = $this->attempt($request);
        Auth::logout();
        $request->session()->invalidate();
        Auth::login($user);
        $request->session()->regenerate();
        Audit::log('Ganti Akun', 'Akun Guru', $user->nama, 'Pindah akun dengan verifikasi ulang password');

        return redirect()->route('dashboard');
    }

    /**
     * Ganti ke akun dari Pusat: keluar dari Absensi DAN dari Pusat, lalu langsung ke halaman masuk Pusat
     * sehingga akun tujuan bisa dipilih (akun Pusat tidak punya password di Absensi).
     */
    public function switchPusat(Request $request)
    {
        Audit::log('Ganti Akun', 'Akun Guru', $this->me()->nama, 'Keluar untuk masuk dengan akun lain lewat Pusat');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if (! \App\Services\PusatKlien::aktif()) {
            return redirect()->route('login');
        }

        return redirect()->away(rtrim((string) config('pusat.url'), '/').'/sso/logout?'.http_build_query([
            'client_id' => config('pusat.client_id'),
            'post_logout_redirect_uri' => route('sso.login'),
        ]));
    }

    private function attempt(Request $request): User
    {
        $data = $request->validate(['username' => 'required|string|max:191', 'password' => 'required|string|max:255']);
        $username = strtolower(trim($data['username']));
        $key = 'login:'.sha1($request->ip().'|'.$username);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['username' => 'Terlalu banyak percobaan login gagal. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.']);
        }
        $user = User::whereRaw('LOWER(username) = ?', [$username])->first();
        $lewatPusat = $user && \App\Services\PusatAkun::cocokSandiPusat($user, $data['password']); // login cadangan akun Pusat
        if (! $user || ! $user->is_active || (! $lewatPusat && ! Passwords::check($data['password'], $user->password_hash))) {
            RateLimiter::hit($key, 15 * 60);
            throw ValidationException::withMessages(['username' => 'Username atau password salah, atau akun nonaktif.'
                .(\App\Services\PusatKlien::aktif() ? ' Akun dari Pusat Sekolah memakai password Pusat; akun yang belum mengganti password awal masuk dulu lewat tombol “Masuk lewat Pusat”.' : '')]);
        }
        RateLimiter::clear($key);
        if (! $lewatPusat && Passwords::needsRehash($user->password_hash)) {
            $user->forceFill(['password_hash' => Passwords::make($data['password'])])->save(); // hash lama → bcrypt
        }

        return $user;
    }

    public function logout(Request $request)
    {
        $viaPusat = (bool) $request->session()->get('via_pusat');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($viaPusat && \App\Services\PusatKlien::aktif()) {
            // Keluar juga dari Pusat (mencabut token), lalu kembali ke halaman login Absensi.
            return redirect()->away(config('pusat.url').'/sso/logout?'.http_build_query([
                'client_id' => config('pusat.client_id'),
                'post_logout_redirect_uri' => route('login'),
            ]));
        }

        return redirect()->route('login')->with('success', 'Anda sudah keluar.');
    }

    public function showPassword()
    {
        return view('auth.password', ['pusat' => $this->akunPusat()]);
    }

    /** Akun dari Pusat: password diganti di Pusat (lalu tersalin ke semua aplikasi), bukan di sini. */
    private function akunPusat(): ?string
    {
        return $this->me()->pusat_uuid && \App\Services\PusatKlien::aktif() ? rtrim((string) config('pusat.url'), '/').'/profil/password' : null;
    }

    public function password(Request $request)
    {
        if ($url = $this->akunPusat()) {
            return redirect()->away($url);
        }
        $data = $request->validate([
            'old' => 'required|string',
            'new' => ['required', 'string', 'confirmed', 'different:old', \App\Support\PasswordPolicy::rule()],
        ], [], ['old' => 'password lama', 'new' => 'password baru']);
        $user = $this->me();
        if (! Passwords::check($data['old'], $user->password_hash)) {
            throw ValidationException::withMessages(['old' => 'Password lama tidak sesuai.']);
        }
        $user->forceFill(['password_hash' => Passwords::make($data['new'])])->save();
        // Sesi di perangkat lain otomatis keluar (middleware auth.session); sesi ini tetap berlaku.
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->getAuthPassword());
        Audit::log('Ganti Password Mandiri', 'Akun Guru', $user->nama, 'Pengguna mengganti password akunnya sendiri');

        return redirect()->route('dashboard')->with('success', 'Password berhasil diganti. Sesi di perangkat lain otomatis keluar.');
    }
}
