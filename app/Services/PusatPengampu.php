<?php

namespace App\Services;

use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pasangan Mapel mengikuti Pengampu Mapel di Pusat (guru – mapel – kelas).
 * Pusat menyebut guru lewat UUID pegawai; baris yang gurunya belum punya akun di Absensi disimpan dan
 * dipasang otomatis saat guru itu pertama kali masuk lewat Pusat. Pasangan buatan lokal tidak disentuh.
 */
final class PusatPengampu
{
    public static function siap(): bool
    {
        static $ada = null;

        return $ada ??= Schema::hasColumn('teacher_subject_class_pairing', 'pusat_uuid') && Schema::hasColumn('users', 'pegawai_uuid');
    }

    /** Pasangan Mapel sudah diambil dari Pusat → tambah/hapus pasangan dari Pusat dikunci di Absensi. */
    public static function aktif(): bool
    {
        return PusatKlien::kelolaSiswa() && (bool) PusatKlien::baca('pengampu_pusat');
    }

    /**
     * @param  array<int,array>  $baris  hasil /api/v1/master/pengampu
     * @return array{baru:int, dihapus:int, tertaut:int, tertunda:int, tanpa_mapel:int, tanpa_kelas:int, hanya_lokal:int}
     */
    public static function terapkan(array $baris): array
    {
        $h = ['baru' => 0, 'dihapus' => 0, 'tertaut' => 0, 'tertunda' => 0, 'tanpa_mapel' => 0, 'tanpa_kelas' => 0, 'hanya_lokal' => 0];
        if (! self::siap()) {
            return $h;
        }
        $guru = User::whereNotNull('pegawai_uuid')->pluck('id', 'pegawai_uuid');
        $mapel = Subject::whereNotNull('pusat_uuid')->pluck('id', 'pusat_uuid');
        $kelas = SchoolClass::whereNotNull('pusat_uuid')->pluck('id', 'pusat_uuid');

        $ingin = []; // pusat_uuid => [user, subject, class]
        foreach ($baris as $p) {
            if (! empty($p['dihapus']) || empty($p['uuid'])) {
                continue;
            }
            $u = $guru[$p['pegawai_uuid'] ?? ''] ?? null;
            $s = $mapel[$p['mapel_uuid'] ?? ''] ?? null;
            $c = $kelas[$p['kelas_uuid'] ?? ''] ?? null;
            if (! $s) {
                $h['tanpa_mapel']++;
            } elseif (! $c) {
                $h['tanpa_kelas']++; // kelas tahun ajaran lain / belum tersinkron
            } elseif (! $u) {
                $h['tertunda']++;    // guru belum pernah masuk Absensi lewat Pusat
            } else {
                $ingin[$p['uuid']] = ['user_id' => (int) $u, 'subject_id' => (int) $s, 'class_id' => (int) $c];
            }
        }

        DB::transaction(function () use ($ingin, &$h) {
            $kunci = fn ($r) => $r['user_id'].'-'.$r['subject_id'].'-'.$r['class_id'];
            $ada = Pairing::all()->keyBy(fn ($p) => $kunci($p->toArray()));
            $dipakai = [];
            foreach ($ingin as $uuid => $r) {
                $k = $kunci($r);
                $dipakai[$k] = true;
                $lama = $ada->get($k);
                if (! $lama) {
                    Pairing::insert($r + ['pusat_uuid' => $uuid]);
                    $ada->put($k, new Pairing($r + ['pusat_uuid' => $uuid]));
                    $h['baru']++;
                } elseif ($lama->pusat_uuid === null) {
                    Pairing::where($r)->update(['pusat_uuid' => $uuid]); // pasangan lokal yang sama → ditautkan
                    $lama->pusat_uuid = $uuid;
                    $h['tertaut']++;
                }
            }
            foreach ($ada as $k => $p) {
                if ($p->pusat_uuid !== null && ! isset($dipakai[$k])) {
                    Pairing::where(['user_id' => $p->user_id, 'subject_id' => $p->subject_id, 'class_id' => $p->class_id])->delete();
                    $h['dihapus']++;
                } elseif ($p->pusat_uuid === null) {
                    $h['hanya_lokal']++;
                }
            }
        });

        return $h;
    }

    public static function berkas(): string
    {
        return storage_path('app/pusat-pengampu.json');
    }

    /** Simpan salinan ringkas pengampu terakhir dari Pusat (berkas, bukan tabel: bisa ribuan baris). */
    public static function simpanData(array $baris): void
    {
        $ringkas = [];
        foreach ($baris as $p) {
            if (empty($p['dihapus']) && ! empty($p['uuid'])) {
                $ringkas[] = array_intersect_key($p, array_flip(['uuid', 'pegawai_uuid', 'mapel_uuid', 'kelas_uuid']));
            }
        }
        @mkdir(dirname(self::berkas()), 0775, true);
        file_put_contents(self::berkas(), json_encode($ringkas), LOCK_EX);
    }

    /** Pasang ulang dari data terakhir yang diambil saat sinkron (dipanggil setelah akun guru tertaut ke pegawai Pusat). */
    public static function terapkanSimpanan(): void
    {
        if (! self::aktif()) {
            return;
        }
        try {
            $baris = is_file(self::berkas()) ? (json_decode((string) file_get_contents(self::berkas()), true) ?: []) : [];
            if ($baris) {
                $h = self::terapkan($baris);
                if ($h['baru'] || $h['dihapus']) {
                    Audit::log('Pasangan dari Pusat', 'Integrasi Pusat', null, "Pasangan mapel +{$h['baru']} −{$h['dihapus']} setelah akun guru tertaut");
                }
            }
        } catch (\Throwable $e) {
            report($e); // jangan menggagalkan login
        }
    }
}
