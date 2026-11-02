<?php

namespace App\Services;

use App\Models\User;
use App\Support\Passwords;
use Illuminate\Support\Str;

/** Mencocokkan profil dari Pusat (userinfo) dengan akun lokal Absensi. */
class PusatAkun
{
    /** true selama sinkron massal: wali kelas & pasangan mapel dipasang sekali di akhir, bukan per akun. */
    public static bool $tundaEfek = false;

    /**
     * @param  array  $info  objek "user" dari Pusat (sub, username, nama, peran[] ...)
     * @return array{0:?User,1:?string}  [akun, pesan galat]
     */
    public static function masuk(array $info): array
    {
        $uuid = (string) ($info['sub'] ?? '');
        $username = strtolower(trim((string) ($info['username'] ?? '')));
        if ($uuid === '' || $username === '') {
            return [null, 'Data akun dari Pusat tidak lengkap.'];
        }
        $peran = self::petakanPeran((array) ($info['peran'] ?? []));
        if (! $peran) {
            return [null, 'Akun Anda belum diberi peran yang dikenal Absensi. Hubungi administrator.'];
        }

        $user = User::where('pusat_uuid', $uuid)->first();
        if (! $user) {
            $kandidat = User::whereRaw('LOWER(username) = ?', [$username])->first();
            if ($kandidat && $kandidat->pusat_uuid !== null) {
                return [null, 'Username sudah tertaut ke akun Pusat lain. Hubungi administrator.'];
            }
            $user = $kandidat; // penautan pertama lewat username yang sama
        }

        if ($user && ! $user->is_active) {
            // Dinonaktifkan otomatis oleh Pusat lalu aksesnya dipulihkan di Pusat (login ini membuktikannya): aktifkan lagi.
            if ($user->pusat_uuid !== null && PusatKlien::baca('dc:'.$user->pusat_uuid) !== null) {
                $user->update(['is_active' => true]);
                \Illuminate\Support\Facades\DB::table('pusat_state')->where('kunci', 'dc:'.$user->pusat_uuid)->delete();
                Audit::log('Akses Dipulihkan Pusat', 'Akun Guru', $user->nama, 'Akun diaktifkan kembali karena akses dipulihkan di Pusat');
            } else {
                return [null, 'Akun Anda dinonaktifkan di Absensi. Hubungi administrator.'];
            }
        }

        $pegawai = is_array($info['pegawai'] ?? null) ? (string) ($info['pegawai']['uuid'] ?? '') : '';

        if (! $user) {
            $user = User::create([
                'username' => $username, 'nama' => (string) ($info['nama'] ?? $username), 'roles' => $peran, 'subjects' => [], 'classes' => [],
                'password_hash' => Passwords::make(Str::random(48)), // tak diketahui siapa pun: hanya login lewat Pusat
                'is_active' => true, 'pusat_uuid' => $uuid,
            ] + (self::adaKolomPegawai() ? ['pegawai_uuid' => $pegawai ?: null] : []));
            if (! self::$tundaEfek) {
                self::terapkanWali();
                if ($pegawai) {
                    PusatPengampu::terapkanSimpanan();
                }
            }
            Audit::log('Akun dari Pusat', 'Akun Guru', $user->nama, "Akun dibuat otomatis saat login pertama lewat Pusat, peran: ".implode(', ', $peran));

            return [$user, null];
        }

        $roles = $peran;
        if (in_array('superadmin', $user->roles ?? [], true)) {
            $roles[] = 'superadmin'; // superadmin hanya diatur di Absensi
            $roles = array_values(array_unique($roles));
        }
        $dulu = collect($user->roles ?? [])->sort()->values()->all();
        $baru = collect($roles)->sort()->values()->all();
        $perubahan = ['pusat_uuid' => $uuid];
        if (self::adaKolomPegawai() && $user->pegawai_uuid !== ($pegawai ?: null)) {
            $perubahan['pegawai_uuid'] = $pegawai ?: null;
        }
        if ($dulu !== $baru) {
            $perubahan['roles'] = $roles;
        }
        if (! empty($info['nama']) && $user->nama !== $info['nama']) {
            $perubahan['nama'] = (string) $info['nama'];
        }
        $user->update($perubahan);
        if (array_key_exists('pegawai_uuid', $perubahan) && ! self::$tundaEfek) {
            self::terapkanWali();
            PusatPengampu::terapkanSimpanan();
        }
        if (isset($perubahan['roles'])) {
            Audit::log('Peran dari Pusat', 'Akun Guru', $user->nama, 'Peran diselaraskan dengan Pusat: '.implode(', ', $dulu).' → '.implode(', ', $baru));
        }

        return [$user, null];
    }

    /** @return string[] peran lokal (unik); peran Pusat yang tidak dikenal diabaikan */
    public static function petakanPeran(array $peranPusat): array
    {
        $peta = (array) config('pusat.peran_peta');
        $hasil = [];
        foreach ($peranPusat as $p) {
            if (isset($peta[$p])) {
                $hasil[] = $peta[$p];
            }
        }

        return array_values(array_unique($hasil));
    }

    private static function adaKolomPegawai(): bool
    {
        static $ada = null;
        if ($ada === null || app()->runningUnitTests()) {
            $ada = \Illuminate\Support\Facades\Schema::hasColumn('users', 'pegawai_uuid') && \Illuminate\Support\Facades\Schema::hasColumn('classes', 'wali_pusat_uuid');
        }

        return $ada;
    }

    /** Webhook akun: catat pegawai milik akun (untuk wali kelas dari Pusat). */
    public static function tautPegawai(array $data): void
    {
        if (! self::adaKolomPegawai() || ! array_key_exists('pegawai_uuid', $data) || empty($data['uuid'])) {
            return;
        }
        $user = User::where('pusat_uuid', (string) $data['uuid'])->first();
        $pegawai = $data['pegawai_uuid'] ? (string) $data['pegawai_uuid'] : null;
        if ($user && $user->pegawai_uuid !== $pegawai) {
            $user->update(['pegawai_uuid' => $pegawai]);
            self::terapkanWali();
            PusatPengampu::terapkanSimpanan();
        }
    }

    /**
     * Wali kelas mengikuti Pusat: kelas yang punya wali di Pusat diberikan ke akun milik pegawai tersebut
     * (akun lain yang masih tercatat sebagai wali kelas itu dilepas). Kelas tanpa wali di Pusat, atau wali yang
     * belum punya akun di Absensi, dibiarkan seperti pengaturan lokal.
     *
     * @return int jumlah akun yang berubah
     */
    public static function terapkanWali(): int
    {
        if (! self::adaKolomPegawai()) {
            return 0;
        }
        $n = 0;
        foreach (\App\Models\SchoolClass::whereNotNull('wali_pusat_uuid')->get(['id', 'name', 'wali_pusat_uuid']) as $c) {
            $wali = User::where('pegawai_uuid', $c->wali_pusat_uuid)->first();
            if (! $wali || (int) $wali->kelas_wali_id === (int) $c->id) {
                continue;
            }
            User::where('kelas_wali_id', $c->id)->where('id', '!=', $wali->id)->update(['kelas_wali_id' => null]);
            $wali->update(['kelas_wali_id' => $c->id]);
            Audit::log('Wali Kelas dari Pusat', 'Akun Guru', $wali->nama, "Wali kelas {$c->name}");
            $n++;
        }

        return $n;
    }

    /** Webhook akun: hanya arah AMAN yang diterapkan otomatis (mencabut akses). Perubahan lain menunggu login/sinkron berikutnya. */
    public static function cabutBilaPerlu(array $data): bool
    {
        $uuid = (string) ($data['uuid'] ?? '');
        if ($uuid === '') {
            return false;
        }
        $user = User::where('pusat_uuid', $uuid)->first();
        if (! $user || ! $user->is_active) {
            return false;
        }
        $dicabut = (array_key_exists('aktif', $data) && $data['aktif'] === false)
            || (array_key_exists('peran', $data) && self::petakanPeran((array) $data['peran']) === []);
        if (! $dicabut) {
            return false;
        }
        $user->update(['is_active' => false]); // sesi aktif keluar pada request berikutnya (EnsureActiveUser)
        PusatKlien::simpan('dc:'.$uuid, 1); // penanda: boleh dipulihkan otomatis bila Pusat memberi akses lagi
        Audit::log('Akses Dicabut Pusat', 'Akun Guru', $user->nama, 'Akun dinonaktifkan karena akses dicabut di Pusat');

        return true;
    }

    /**
     * Login cadangan: simpan salinan hash password dari Pusat (izin "akun_sandi") dan selaraskan username,
     * agar akun Pusat tetap bisa masuk lewat login lokal saat Pusat tidak bisa dihubungi.
     * null = akun masih wajib ganti password awal → login lokal ditutup sampai password diganti di Pusat.
     */
    public static function terapkanSandi(array $data): void
    {
        if (empty($data['uuid']) || ! \Illuminate\Support\Facades\Schema::hasColumn('users', 'password_pusat')) {
            return;
        }
        $user = User::where('pusat_uuid', (string) $data['uuid'])->first();
        if (! $user) {
            return;
        }
        $ubah = [];
        if (array_key_exists('password_hash', $data)) {
            $h = is_string($data['password_hash']) && str_starts_with($data['password_hash'], '$') ? $data['password_hash'] : null;
            if ($user->password_pusat !== $h) {
                $ubah['password_pusat'] = $h;
            }
        }
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        if ($username !== '' && $username !== strtolower($user->username)
            && ! User::whereRaw('LOWER(username) = ?', [$username])->where('id', '!=', $user->id)->exists()) {
            $ubah['username'] = $username;
        }
        if ($ubah) {
            $user->forceFill($ubah)->save();
        }
    }

    /** Cocokkan password dengan salinan dari Pusat (login cadangan). */
    public static function cocokSandiPusat(User $user, string $plain): bool
    {
        $h = $user->pusat_uuid ? (string) ($user->password_pusat ?? '') : '';
        if ($h === '') {
            return false;
        }
        try {
            return password_verify($plain, $h);
        } catch (\Throwable) {
            return false;
        }
    }
}
