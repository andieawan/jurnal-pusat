<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Pindah data dari aplikasi Absensi ke Jurnal Guru (kode yang sama dipakai di kedua repo).
 *
 *  - ekspor(): di server Absensi → satu berkas .zip berisi data.json + berkas unggahan (surat BK/TU).
 *  - impor():  di server Jurnal  → data digabung, BUKAN ditimpa: data yang diisi di Jurnal selama uji coba tetap ada.
 *
 * Siswa, kelas, mapel, dan akun dicocokkan lewat UUID Pusat (cadangan: NIS / nama / username), sehingga nomor ID
 * yang berbeda di kedua aplikasi tidak masalah. Aman diulang: data yang sudah ada dilewati.
 */
final class PindahData
{
    public const VERSI = 1;

    /** Pengaturan sekolah yang ikut dipindah (identitas sekolah & tahun ajaran datang dari Pusat). */
    private const PENGATURAN = ['backup_retention_weeks', 'staff_mandiri', 'staff_jam_masuk', 'staff_lat', 'staff_lng', 'staff_radius',
        'pw_mode', 'pw_min', 'pw_huruf', 'pw_angka', 'pw_simbol', 'att_alpa', 'att_sakit', 'att_izin', 'att_total'];

    // ------------------------------------------------------------------ ekspor

    /** @return array{berkas:string, jumlah:array<string,int>} */
    public static function ekspor(string $tujuan): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi PHP zip belum terpasang (sudo apt install php-zip).');
        }
        $ambil = fn (string $t) => Schema::hasTable($t) ? DB::table($t)->get()->map(fn ($r) => (array) $r)->all() : [];
        $data = ['versi' => self::VERSI, 'dibuat' => now('UTC')->toIso8601String(), 'sumber' => (string) config('app.url')];
        foreach (['users', 'students', 'classes', 'subjects', 'attendance', 'grade_activities', 'grade_values', 'teacher_subject_class_pairing',
            'parent_access_tokens', 'class_board_tokens', 'bk_records', 'mutasi_siswa', 'tu_surat', 'staff_attendance', 'audit_log'] as $t) {
            $data[$t] = $ambil($t);
        }
        $data['school_settings'] = Schema::hasTable('school_settings') ? (array) (DB::table('school_settings')->first() ?? []) : [];

        @mkdir(dirname($tujuan), 0750, true);
        $zip = new ZipArchive;
        if ($zip->open($tujuan, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Berkas $tujuan tidak bisa dibuat.");
        }
        $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $berkas = 0;
        foreach (array_merge($data['bk_records'], $data['tu_surat']) as $r) {
            $p = (string) ($r['berkas_path'] ?? '');
            if ($p !== '' && Storage::disk('local')->exists($p)) {
                $zip->addFile(Storage::disk('local')->path($p), 'berkas/'.$p);
                $berkas++;
            }
        }
        $zip->close();
        @chmod($tujuan, 0640);

        $jumlah = array_map(fn ($v) => is_array($v) && array_is_list($v) ? count($v) : null, $data);

        return ['berkas' => $tujuan, 'jumlah' => array_filter($jumlah, fn ($v) => $v !== null) + ['berkas unggahan' => $berkas]];
    }

    // ------------------------------------------------------------------ impor

    private array $peta = ['students' => [], 'classes' => [], 'subjects' => [], 'users' => []];

    private array $hasil = [];

    private array $data = [];

    /** @return array<string,array{baru:int,ubah:int,lewat:int}> */
    public static function impor(string $berkas, bool $terapkan): array
    {
        $zip = new ZipArchive;
        if ($zip->open($berkas) !== true || ($json = $zip->getFromName('data.json')) === false) {
            throw new RuntimeException('Berkas bukan ekspor Absensi yang sah (data.json tidak ada).');
        }
        $data = json_decode($json, true);
        if (! is_array($data) || ($data['versi'] ?? null) !== self::VERSI) {
            throw new RuntimeException('Versi berkas ekspor tidak dikenal.');
        }
        $p = new self;
        $p->data = $data;
        DB::beginTransaction();
        try {
            $p->jalankan();
            if ($terapkan) {
                DB::commit();
                $p->salinBerkas($zip);
                Audit::log('Impor dari Absensi', 'Sistem', 'artisan', 'Sumber '.($data['sumber'] ?? '?').' dibuat '.($data['dibuat'] ?? '?'));
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            $zip->close();
        }

        return $p->hasil;
    }

    private function catat(string $t, string $jenis): void
    {
        $this->hasil[$t] ??= ['baru' => 0, 'ubah' => 0, 'lewat' => 0];
        $this->hasil[$t][$jenis]++;
    }

    private function jalankan(): void
    {
        foreach ($this->data['classes'] as $r) {
            $this->peta['classes'][$r['id']] = $this->kelas($r);
        }
        foreach ($this->data['subjects'] as $r) {
            $this->peta['subjects'][$r['id']] = $this->mapel($r);
        }
        foreach ($this->data['students'] as $r) {
            $this->peta['students'][$r['id']] = $this->siswa($r);
        }
        foreach ($this->data['users'] as $r) {
            $this->peta['users'][$r['id']] = $this->akun($r);
        }
        $this->kehadiran();
        $this->nilai();
        $this->sederhana();
        $this->pengaturan();
    }

    private function id(string $peta, $lama): ?int
    {
        return $lama === null || $lama === '' ? null : ($this->peta[$peta][$lama] ?? null);
    }

    private function kelas(array $r): int
    {
        $k = (! empty($r['pusat_uuid']) ? SchoolClass::where('pusat_uuid', $r['pusat_uuid'])->first() : null)
            ?? SchoolClass::whereRaw('LOWER(name) = ?', [mb_strtolower($r['name'])])->first();
        if ($k) {
            $this->catat('kelas', 'lewat');

            return $k->id;
        }
        $this->catat('kelas', 'baru');

        return SchoolClass::create(array_intersect_key($r, array_flip(['name', 'jurusan', 'angkatan', 'tahun_ajaran', 'semester', 'pusat_uuid', 'wali_pusat_uuid'])))->id;
    }

    private function mapel(array $r): int
    {
        $s = (! empty($r['pusat_uuid']) ? Subject::where('pusat_uuid', $r['pusat_uuid'])->first() : null)
            ?? Subject::whereRaw('LOWER(name) = ?', [mb_strtolower($r['name'])])->first()
            ?? ((int) $r['id'] === (int) config('absensi.bk_subject_id') ? Subject::find($r['id']) : null);
        if ($s) {
            $this->catat('mapel', 'lewat');

            return $s->id;
        }
        $this->catat('mapel', 'baru');
        $baru = array_intersect_key($r, array_flip(['name', 'pusat_uuid', 'kode', 'kelompok', 'aktif']));
        if ((int) $r['id'] === (int) config('absensi.bk_subject_id') && ! Subject::find($r['id'])) {
            $baru['id'] = $r['id']; // mapel BK tetap di ID bawaan
        }

        return Subject::create($baru)->id;
    }

    private function siswa(array $r): ?int
    {
        $s = (! empty($r['pusat_uuid']) ? Student::where('pusat_uuid', $r['pusat_uuid'])->first() : null)
            ?? Student::where('nis', $r['nis'])->first();
        if ($s) {
            // kontak orang tua yang hanya ada di Absensi ikut dilengkapi
            $isi = array_filter(['nama_ortu' => $s->nama_ortu ? null : ($r['nama_ortu'] ?? null), 'telp_ortu' => $s->telp_ortu ? null : ($r['telp_ortu'] ?? null)]);
            $isi ? $s->update($isi) : null;
            $this->catat('siswa', $isi ? 'ubah' : 'lewat');

            return $s->id;
        }
        $kelas = $this->id('classes', $r['class_id'] ?? null);
        if (! $kelas) {
            $this->catat('siswa', 'lewat');

            return null;
        }
        $this->catat('siswa', 'baru');

        return Student::create(['class_id' => $kelas] + array_intersect_key($r, array_flip(['nis', 'nama', 'jk', 'status', 'created_at', 'nama_ortu', 'telp_ortu', 'pusat_uuid'])))->id;
    }

    private function petakanDaftar(?string $json, string $peta): array
    {
        $ids = is_string($json) ? (json_decode($json, true) ?: []) : (array) $json;

        return array_values(array_filter(array_map(fn ($v) => $this->id($peta, (int) $v), $ids)));
    }

    private function akun(array $r): int
    {
        $u = (! empty($r['pusat_uuid']) ? User::where('pusat_uuid', $r['pusat_uuid'])->first() : null)
            ?? User::whereRaw('LOWER(username) = ?', [mb_strtolower($r['username'])])->first();
        $mapel = $this->petakanDaftar($r['subjects'] ?? null, 'subjects');
        $kelas = $this->petakanDaftar($r['classes'] ?? null, 'classes');
        if ($u) {
            $isi = [];
            if (! $u->subjects && $mapel) {
                $isi['subjects'] = $mapel;
            }
            if (! $u->classes && $kelas) {
                $isi['classes'] = $kelas;
            }
            if (! $u->kelas_wali_id && ($w = $this->id('classes', $r['kelas_wali_id'] ?? null))) {
                $isi['kelas_wali_id'] = $w;
            }
            $isi ? $u->update($isi) : null;
            $this->catat('akun', $isi ? 'ubah' : 'lewat');

            return $u->id;
        }
        $this->catat('akun', 'baru');
        $baru = array_intersect_key($r, array_flip(['username', 'password_hash', 'nama', 'foto_profil_url', 'is_active', 'created_at', 'pusat_uuid', 'pegawai_uuid', 'password_pusat']));
        $baru['roles'] = is_string($r['roles'] ?? null) ? (json_decode($r['roles'], true) ?: []) : (array) ($r['roles'] ?? []);
        $baru['subjects'] = $mapel;
        $baru['classes'] = $kelas;
        $baru['kelas_wali_id'] = $this->id('classes', $r['kelas_wali_id'] ?? null);
        foreach (['pegawai_uuid', 'password_pusat'] as $k) {
            if (! Schema::hasColumn('users', $k)) {
                unset($baru[$k]);
            }
        }

        return User::create($baru)->id;
    }

    private function kehadiran(): void
    {
        foreach ($this->data['attendance'] as $r) {
            $siswa = $this->id('students', $r['student_id']);
            $kelas = $this->id('classes', $r['class_id']);
            $mapel = $r['subject_id'] === null ? null : $this->id('subjects', $r['subject_id']);
            if (! $siswa || ! $kelas || ($r['subject_id'] !== null && ! $mapel)) {
                $this->catat('absensi', 'lewat');

                continue;
            }
            $isi = ['status' => $r['status'], 'recorded_by' => $this->id('users', $r['recorded_by'] ?? null), 'recorded_via' => $r['recorded_via'],
                'notes' => $r['notes'], 'updated_at' => $r['updated_at']];
            $ada = Attendance::where('student_id', $siswa)->where('tanggal', $r['tanggal'])
                ->when($mapel === null, fn ($q) => $q->whereNull('subject_id'), fn ($q) => $q->where('subject_id', $mapel))->first();
            if (! $ada) {
                Attendance::create($isi + ['student_id' => $siswa, 'class_id' => $kelas, 'subject_id' => $mapel, 'tanggal' => $r['tanggal'],
                    'created_at' => $r['created_at'], 'created_at_millis' => $r['created_at_millis']]);
                $this->catat('absensi', 'baru');
            } elseif ((string) $r['updated_at'] > (string) $ada->updated_at && ($ada->status !== $r['status'] || $ada->notes !== $r['notes'])) {
                $ada->update($isi); // yang lebih baru menang
                $this->catat('absensi', 'ubah');
            } else {
                $this->catat('absensi', 'lewat');
            }
        }
    }

    private function nilai(): void
    {
        $kegiatan = [];
        foreach ($this->data['grade_activities'] as $r) {
            $g = $this->id('users', $r['teacher_id']);
            $m = $this->id('subjects', $r['subject_id']);
            $k = $this->id('classes', $r['class_id']);
            if (! $g || ! $m || ! $k) {
                $this->catat('kegiatan nilai', 'lewat');

                continue;
            }
            $n = DB::table('grade_activities')->insertOrIgnore(['id' => $r['id'], 'teacher_id' => $g, 'subject_id' => $m, 'class_id' => $k] + array_intersect_key($r, array_flip(['nama_kegiatan', 'tanggal_kegiatan', 'tipe_skala', 'created_at'])));
            $kegiatan[$r['id']] = true;
            $this->catat('kegiatan nilai', $n ? 'baru' : 'lewat');
        }
        foreach ($this->data['grade_values'] as $r) {
            $s = $this->id('students', $r['student_id']);
            if (! $s || ! isset($kegiatan[$r['activity_id']])) {
                $this->catat('nilai siswa', 'lewat');

                continue;
            }
            $n = DB::table('grade_values')->insertOrIgnore(['activity_id' => $r['activity_id'], 'student_id' => $s, 'nilai' => $r['nilai']]);
            $this->catat('nilai siswa', $n ? 'baru' : 'lewat');
        }
    }

    /** Tabel kecil: dicocokkan lewat kunci alami lalu disisipkan bila belum ada. */
    private function sederhana(): void
    {
        $spek = [
            // tabel => [label, kolom peta => peta, kunci alami]
            'teacher_subject_class_pairing' => ['pasangan mapel', ['user_id' => 'users', 'subject_id' => 'subjects', 'class_id' => 'classes'], ['user_id', 'subject_id', 'class_id']],
            'parent_access_tokens' => ['tautan wali murid', ['student_id' => 'students', 'created_by' => 'users?'], ['token']],
            'class_board_tokens' => ['tautan papan kelas', ['class_id' => 'classes', 'created_by' => 'users?'], ['token']],
            'bk_records' => ['catatan BK', ['student_id' => 'students', 'class_id' => 'classes?', 'dicatat_oleh' => 'users?'], ['jenis', 'student_id', 'tanggal', 'judul']],
            'mutasi_siswa' => ['mutasi siswa', ['student_id' => 'students', 'class_id' => 'classes?', 'dicatat_oleh' => 'users?'], ['jenis', 'student_id', 'tanggal']],
            'tu_surat' => ['surat TU', ['student_id' => 'students?', 'dicatat_oleh' => 'users?'], ['arah', 'tanggal', 'nomor', 'perihal']],
            'staff_attendance' => ['absen pegawai', ['user_id' => 'users', 'dicatat_oleh' => 'users?'], ['user_id', 'tanggal']],
            'audit_log' => ['log aktivitas', [], ['timestamp', 'action', 'actor', 'details']],
        ];
        foreach ($spek as $t => [$label, $peta, $kunci]) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            $kolom = Schema::getColumnListing($t);
            foreach ($this->data[$t] ?? [] as $r) {
                $baris = array_intersect_key($r, array_flip($kolom));
                $sah = true;
                foreach ($peta as $k => $p) {
                    $wajib = ! str_ends_with($p, '?');
                    $baru = $this->id(rtrim($p, '?'), $r[$k] ?? null);
                    if ($wajib && ! $baru) {
                        $sah = false;
                    }
                    $baris[$k] = $baru;
                }
                if (! $sah) {
                    $this->catat($label, 'lewat');

                    continue;
                }
                if (! in_array($t, ['parent_access_tokens', 'class_board_tokens'], true)) {
                    unset($baris['id']); // ID baru dari database Jurnal
                }
                $q = DB::table($t);
                foreach ($kunci as $k) {
                    isset($baris[$k]) ? $q->where($k, $baris[$k]) : $q->whereNull($k);
                }
                if ($q->exists()) {
                    $this->catat($label, 'lewat');

                    continue;
                }
                DB::table($t)->insert($baris);
                $this->catat($label, 'baru');
            }
        }
    }

    private function pengaturan(): void
    {
        $lama = $this->data['school_settings'] ?? [];
        if (! $lama || ! Schema::hasTable('school_settings')) {
            return;
        }
        $isi = array_intersect_key($lama, array_flip(array_intersect(self::PENGATURAN, Schema::getColumnListing('school_settings'))));
        if ($isi) {
            SchoolSetting::put($isi);
            $this->catat('pengaturan', 'ubah');
        }
    }

    private function salinBerkas(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nama = (string) $zip->getNameIndex($i);
            if (! str_starts_with($nama, 'berkas/') || str_ends_with($nama, '/') || str_contains($nama, '..')) {
                continue;
            }
            $tujuan = substr($nama, 7);
            if (! Storage::disk('local')->exists($tujuan)) {
                Storage::disk('local')->put($tujuan, (string) $zip->getFromIndex($i));
                $this->catat('berkas unggahan', 'baru');
            }
        }
    }
}
