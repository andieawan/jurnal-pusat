<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\PasswordPolicy;
use App\Support\TahunAjaran;
use Illuminate\Support\Facades\DB;

/**
 * Impor massal dari Excel/CSV untuk Admin: kelas, mapel, siswa, guru & staf, pasangan mapel,
 * serta riwayat absensi & nilai dari catatan lama (buku/Excel).
 * Tiap baris diproses sendiri-sendiri: baris bermasalah dilewati dan dilaporkan, sisanya tetap masuk.
 * Mode "cek dulu" menjalankan semuanya lalu membatalkan (rollback) sehingga tidak ada yang tersimpan.
 */
class ImportService
{
    /** Definisi tiap jenis data: judul, kolom, contoh isi, dan petunjuk (dipakai untuk template & halaman). */
    public static function types(): array
    {
        $cls = SchoolClass::orderBy('name')->pluck('name')->all();
        $sub = Subject::regular()->orderBy('name')->pluck('name')->all();
        $k1 = $cls[0] ?? 'X RPL 1';
        $k2 = $cls[1] ?? 'X RPL 2';
        $m1 = $sub[0] ?? 'Bahasa Indonesia';
        $m2 = $sub[1] ?? 'Matematika';

        return [
            'kelas' => [
                'title' => 'Kelas', 'icon' => '🏫', 'file' => 'Template_Impor_Kelas.xlsx',
                'cols' => ['Nama Kelas', 'Jurusan', 'Angkatan', 'Tahun Ajaran', 'Semester'],
                'example' => [['X RPL 1', 'Rekayasa Perangkat Lunak', 'X', TahunAjaran::label(), TahunAjaran::semester()], ['X DKV 1', 'Desain Komunikasi Visual', 'X', TahunAjaran::label(), TahunAjaran::semester()]],
                'notes' => ['Wajib: Nama Kelas. Kolom lain boleh kosong (Tahun Ajaran & Semester otomatis memakai pengaturan sekolah).', 'Kelas yang namanya sudah ada dilewati.'],
            ],
            'mapel' => [
                'title' => 'Mata Pelajaran', 'icon' => '📚', 'file' => 'Template_Impor_Mapel.xlsx',
                'cols' => ['Nama Mapel'],
                'example' => [['Bahasa Indonesia'], ['Matematika'], ['Bahasa Daerah']],
                'notes' => ['Satu mapel per baris. Mapel yang namanya sudah ada dilewati.'],
            ],
            'siswa' => [
                'title' => 'Siswa', 'icon' => '🎓', 'file' => 'Template_Impor_Siswa.xlsx',
                'cols' => ['NIS', 'Nama Siswa', 'JK', 'Kelas', 'Nama Ortu', 'No HP Ortu'],
                'example' => [['2025001', 'Ahmad Fauzi', 'L', $k1, 'Bpk. Slamet', '081234567890'], ['2025002', 'Siti Aisyah', 'P', $k1, '', '']],
                'notes' => ['Wajib: NIS, Nama Siswa, JK (L/P), Kelas.', 'Kelas harus sudah ada & ditulis persis seperti di data kelas (impor Kelas dulu bila perlu).', 'NIS yang sudah terdaftar dilewati. Nama Ortu & No HP Ortu opsional (untuk tombol WhatsApp).', 'Kolom Status opsional (aktif, nonaktif, pindah, lulus, berhenti, keluar); kosong = aktif.'],
            ],
            'guru' => [
                'title' => 'Guru & Staf', 'icon' => '👩‍🏫', 'file' => 'Template_Impor_Guru_Staf.xlsx',
                'cols' => ['Username', 'Nama', 'Peran', 'Password', 'Wali Kelas', 'Mapel', 'Kelas'],
                'example' => [['ibu.siti', 'Siti Rahmawati, S.Pd', 'guru', '', $k1, $m1.', '.$m2, $k1.', '.$k2], ['pak.budi', 'Budi Santoso', 'tu', '', '', '', '']],
                'notes' => ['Wajib: Username (huruf kecil/angka/titik/strip, min. 3), Nama.',
                    'Peran: guru, kepsek, bk, tu (pisahkan koma bila lebih dari satu, mis. "guru, bk"). Kosong = guru. Peran admin tidak bisa diimpor.',
                    'Password kosong = dibuatkan otomatis (ditampilkan SEKALI setelah impor — catat atau cetak). Bila diisi, harus memenuhi aturan password sekolah (Admin → Pengaturan).',
                    'Wali Kelas: satu nama kelas (opsional). Mapel & Kelas: boleh banyak, pisahkan koma; berlaku silang (semua mapel di semua kelas itu).',
                    'Mapel berbeda tiap kelas? Pakai impor "Pasangan Mapel". Username yang sudah ada dilewati.'],
            ],
            'absensi' => [
                'title' => 'Riwayat Absensi', 'icon' => '🗂️', 'file' => 'Template_Impor_Riwayat_Absensi.xlsx',
                'cols' => ['Tanggal', 'Kelas', 'Mapel', 'NIS', 'Nama Siswa', 'Status', 'Catatan', 'Guru'],
                'example' => [[date('Y-m-d', strtotime('-1 day')), $k1, '', '2025001', 'Ahmad Fauzi', 'H', '', ''], [date('Y-m-d', strtotime('-1 day')), $k1, $m1, '2025002', 'Siti Aisyah', 'S', 'Demam', 'ibu.siti']],
                'notes' => ['Untuk memasukkan absensi lama (dari buku/Excel). Satu baris = satu siswa pada satu tanggal.',
                    'Wajib: Tanggal (YYYY-MM-DD atau DD/MM/YYYY), Kelas, NIS, Status (H/I/S/A atau Hadir/Izin/Sakit/Alpa).',
                    'Mapel kosong = absen harian (wali kelas). Bila diisi, nama mapel harus persis seperti di aplikasi.',
                    'Kelas harus sudah ada dan NIS harus siswa di kelas itu — lihat lembar "Daftar Siswa". Nama Siswa hanya untuk memudahkan membaca, tidak diperiksa.',
                    'Guru (opsional): username atau nama guru pencatat; kosong/tidak dikenal = dicatat atas nama Anda.',
                    'Absensi yang SUDAH ada (siswa + tanggal + mapel sama) tidak ditimpa. Tanggal masa depan dilewati. Berkas .xlsx boleh banyak sheet (mis. satu sheet per bulan) dengan judul kolom yang sama.'],
            ],
            'nilai' => [
                'title' => 'Riwayat Nilai', 'icon' => '📈', 'file' => 'Template_Impor_Riwayat_Nilai.xlsx',
                'cols' => ['Kelas', 'Mapel', 'Nama Kegiatan', 'Tanggal', 'Tipe', 'NIS', 'Nama Siswa', 'Nilai', 'Guru'],
                'example' => [[$k1, $m1, 'Tugas 1', date('Y-m-d', strtotime('-7 days')), 'angka', '2025001', 'Ahmad Fauzi', '80', 'ibu.siti'], [$k1, $m1, 'Tugas 1', date('Y-m-d', strtotime('-7 days')), 'angka', '2025002', 'Siti Aisyah', '75', 'ibu.siti']],
                'notes' => ['Untuk memasukkan nilai lama (dari buku/Excel). Satu baris = nilai satu siswa pada satu kegiatan (tugas/ulangan).',
                    'Baris dengan Kelas + Mapel + Nama Kegiatan + Tanggal yang sama digabung menjadi satu kegiatan.',
                    'Wajib: Kelas, Mapel, Nama Kegiatan, Tanggal, NIS. Tipe: angka (0–100) atau huruf (A–E); kosong = angka. Nilai kosong = belum mengumpulkan.',
                    'Kelas & Mapel harus sudah ada persis seperti di aplikasi; NIS harus siswa di kelas itu — lihat lembar "Daftar Siswa".',
                    'Guru (opsional): username atau nama guru pemilik kegiatan; kosong/tidak dikenal = atas nama Anda.',
                    'Kegiatan yang sudah ada (kelas, mapel, nama, dan tanggal sama) dilewati agar tidak dobel. Berkas .xlsx boleh banyak sheet dengan judul kolom yang sama.'],
            ],
            'pasangan' => [
                'title' => 'Pasangan Mapel', 'icon' => '🔗', 'file' => 'Template_Impor_Pasangan_Mapel.xlsx',
                'cols' => ['Username', 'Mapel', 'Kelas'],
                'example' => [['ibu.siti', $m1, $k1], ['ibu.siti', $m2, $k2]],
                'notes' => ['Satu baris = satu guru mengajar satu mapel di satu kelas. Pakai ini bila mapel guru berbeda tiap kelas.', 'Username, Mapel, dan Kelas harus sudah ada. Pasangan yang sudah ada dilewati.'],
            ],
        ];
    }

    /** Lembar-lembar berkas template (Data + Petunjuk). */
    public static function templateSheets(string $type): array
    {
        $t = self::types()[$type] ?? throw new UserError('Jenis impor tidak dikenal.');
        $notes = [['PETUNJUK — '.$t['title']], ['']];
        foreach ($t['notes'] as $i => $n) {
            $notes[] = [($i + 1).'. '.$n];
        }
        $notes[] = [''];
        $notes[] = ['Hapus baris contoh di lembar "Data", isi data Anda mulai baris ke-2 dengan judul kolom tetap di baris 1, lalu unggah di Admin → Impor Data.'];

        $sheets = ['Data' => array_merge([$t['cols']], $t['example']), 'Petunjuk' => $notes];
        if (in_array($type, ['absensi', 'nilai'], true)) {
            $daftar = [['Kelas', 'NIS', 'Nama Siswa']];
            foreach (\App\Models\Student::active()->with('schoolClass')->get()->sortBy(fn ($s) => [$s->schoolClass?->name, $s->nama]) as $st) {
                $daftar[] = [$st->schoolClass?->name ?? '', $st->nis, $st->nama];
            }
            $sheets['Daftar Siswa'] = $daftar;
        }

        return $sheets;
    }

    /**
     * @return array{ok:int, skip:string[], passwords:array<string,string>, dry:bool}
     */
    public static function run(User $actor, string $type, array $hdr, array $rows, bool $dry): array
    {
        if (! isset(self::types()[$type])) {
            throw new UserError('Jenis impor tidak dikenal.');
        }
        if (count($rows) > 5000) {
            throw new UserError('Maksimal 5.000 baris per impor. Pecah berkas menjadi beberapa bagian.');
        }
        $out = ['ok' => 0, 'skip' => [], 'passwords' => [], 'dry' => $dry];
        DB::beginTransaction();
        try {
            match ($type) {
                'kelas' => self::kelas($actor, $hdr, $rows, $out),
                'mapel' => self::mapel($actor, $hdr, $rows, $out),
                'siswa' => self::siswa($actor, $hdr, $rows, $out),
                'guru' => self::guru($actor, $hdr, $rows, $out),
                'pasangan' => self::pasangan($actor, $hdr, $rows, $out),
                'absensi' => self::absensi($actor, $hdr, $rows, $out),
                'nilai' => self::nilai($actor, $hdr, $rows, $out),
            };
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $dry) {
            Audit::log('Impor '.self::types()[$type]['title'], 'Impor', $actor->nama, "{$out['ok']} ditambahkan, ".count($out['skip']).' dilewati');
        }

        return $out;
    }

    private static function cell(array $r, ?int $i): string
    {
        return $i === null ? '' : trim((string) ($r[$i] ?? ''));
    }

    private static function need(array $hdr, array $names, string $label): int
    {
        return \App\Support\Xlsx::col($hdr, $names) ?? throw new UserError("Kolom \"$label\" tidak ditemukan di baris pertama berkas. Unduh template dan pakai judul kolom yang sama.");
    }

    private static function blank(array $r): bool
    {
        return trim(implode('', array_map('strval', $r))) === '';
    }

    private static function kelas(User $a, array $hdr, array $rows, array &$out): void
    {
        $iN = self::need($hdr, ['nama kelas', 'kelas', 'nama'], 'Nama Kelas');
        $iJ = \App\Support\Xlsx::col($hdr, ['jurusan']);
        $iA = \App\Support\Xlsx::col($hdr, ['angkatan', 'tingkat']);
        $iT = \App\Support\Xlsx::col($hdr, ['tahun ajaran']);
        $iS = \App\Support\Xlsx::col($hdr, ['semester']);
        $have = SchoolClass::pluck('name')->map(fn ($n) => mb_strtolower($n))->flip()->all();
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $name = self::cell($r, $iN);
            $row = 'Baris '.($n + 2);
            if ($name === '' || mb_strlen($name) > 191) {
                $out['skip'][] = "$row: nama kelas kosong/terlalu panjang";
            } elseif (isset($have[mb_strtolower($name)])) {
                $out['skip'][] = "$row ($name): kelas sudah ada";
            } else {
                $sem = self::cell($r, $iS);
                SchoolClass::create(['name' => $name, 'jurusan' => self::cell($r, $iJ) ?: null, 'angkatan' => self::cell($r, $iA) ?: null,
                    'tahun_ajaran' => self::cell($r, $iT) ?: TahunAjaran::label(), 'semester' => in_array(ucfirst(strtolower($sem)), ['Ganjil', 'Genap'], true) ? ucfirst(strtolower($sem)) : TahunAjaran::semester()]);
                $have[mb_strtolower($name)] = true;
                $out['ok']++;
            }
        }
    }

    private static function norm(string $v): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)));
    }

    /** YYYY-MM-DD, DD/MM/YYYY, atau nomor seri tanggal Excel → YYYY-MM-DD (null bila tidak valid). */
    private static function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            $v = substr($v, 0, 10);
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $v, $m)) {
            $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } elseif (preg_match('/^\d{5}(\.\d+)?$/', $v) && (int) $v > 30000 && (int) $v < 80000) {
            $v = gmdate('Y-m-d', ((int) $v - 25569) * 86400);
        }

        return \App\Support\Dates::valid($v) ? $v : null;
    }

    /** Guru pencatat dari kolom Guru (username atau nama); tidak dikenal = yang mengimpor. */
    private static function pencatat(array $users, string $v, User $actor): int
    {
        return ($users['u:'.self::norm($v)] ?? $users['n:'.self::norm($v)] ?? null)?->id ?? $actor->id;
    }

    private static function userIndex(): array
    {
        $idx = [];
        foreach (User::get(['id', 'username', 'nama']) as $u) {
            $idx['n:'.self::norm($u->nama)] ??= $u;
            $idx['u:'.self::norm($u->username)] = $u;
        }

        return $idx;
    }

    /** NIS (dinormalkan) → id siswa, per kelas. */
    private static function roster(array &$cache, int $classId): array
    {
        return $cache[$classId] ??= \App\Models\Student::where('class_id', $classId)->get(['id', 'nis'])->mapWithKeys(fn ($s) => [self::norm($s->nis) => $s->id])->all();
    }

    /** Riwayat absensi: satu baris = satu siswa pada satu tanggal (harian atau per mapel). */
    private static function absensi(User $actor, array $hdr, array $rows, array &$out): void
    {
        $iT = self::need($hdr, ['tanggal'], 'Tanggal');
        $iK = self::need($hdr, ['kelas'], 'Kelas');
        $iN = self::need($hdr, ['nis'], 'NIS');
        $iS = self::need($hdr, ['status', 'kehadiran'], 'Status');
        $iM = \App\Support\Xlsx::col($hdr, ['mapel', 'mata pelajaran']);
        $iC = \App\Support\Xlsx::col($hdr, ['catatan', 'keterangan']);
        $iG = \App\Support\Xlsx::col($hdr, ['guru', 'pencatat']);
        $kode = ['h' => 'H', 'hadir' => 'H', 'i' => 'I', 'izin' => 'I', 's' => 'S', 'sakit' => 'S', 'a' => 'A', 'alpa' => 'A', 'alfa' => 'A', 'tanpa keterangan' => 'A'];
        $classes = SchoolClass::get()->keyBy(fn ($c) => self::norm($c->name));
        $subjects = Subject::get()->keyBy(fn ($s) => self::norm($s->name));
        $users = self::userIndex();
        $roster = [];
        $seen = [];
        $now = now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $row = 'Baris '.($n + 2);
            $tgl = self::parseDate(self::cell($r, $iT));
            $cls = $classes[self::norm(self::cell($r, $iK))] ?? null;
            $nis = self::cell($r, $iN);
            $st = $kode[self::norm(self::cell($r, $iS))] ?? null;
            $mp = self::cell($r, $iM);
            $sub = $mp === '' || in_array(self::norm($mp), ['harian', '-'], true) ? null : ($subjects[self::norm($mp)] ?? false);
            $sid = $cls ? (self::roster($roster, $cls->id)[self::norm($nis)] ?? null) : null;
            $catatan = self::cell($r, $iC);
            $galat = match (true) {
                ! $tgl => "tanggal '".self::cell($r, $iT)."' tidak valid",
                \App\Support\Dates::isFuture($tgl) => "tanggal $tgl di masa depan",
                ! $cls => "kelas '".self::cell($r, $iK)."' tidak ditemukan",
                $sub === false => "mapel '$mp' tidak ditemukan",
                ! $sid => "NIS '$nis' tidak ada di kelas {$cls->name}",
                ! $st => "status '".self::cell($r, $iS)."' harus H/I/S/A",
                mb_strlen($catatan) > 500 => 'catatan lebih dari 500 karakter',
                default => null,
            };
            if ($galat) {
                $out['skip'][] = "$row: $galat";
                continue;
            }
            $key = $sid.'|'.($sub?->id ?? '-').'|'.$tgl;
            $ada = isset($seen[$key]) || \App\Models\Attendance::where(['student_id' => $sid, 'tanggal' => $tgl])
                ->when($sub, fn ($q) => $q->where('subject_id', $sub->id), fn ($q) => $q->whereNull('subject_id'))->exists();
            if ($ada) {
                $out['skip'][] = "$row (NIS $nis, $tgl): absensi sudah ada — tidak ditimpa";
                continue;
            }
            $seen[$key] = true;
            \App\Models\Attendance::create(['student_id' => $sid, 'class_id' => $cls->id, 'subject_id' => $sub?->id, 'tanggal' => $tgl, 'status' => $st, 'notes' => $catatan ?: null,
                'recorded_by' => self::pencatat($users, self::cell($r, $iG), $actor), 'recorded_via' => 'impor', 'created_at' => $now, 'updated_at' => $now, 'created_at_millis' => \App\Support\Dates::parse($tgl) * 1000]);
            $out['ok']++;
        }
    }

    /** Riwayat nilai: satu baris = nilai satu siswa; baris dengan kelas+mapel+kegiatan+tanggal sama menjadi satu kegiatan. */
    private static function nilai(User $actor, array $hdr, array $rows, array &$out): void
    {
        $iK = self::need($hdr, ['kelas'], 'Kelas');
        $iM = self::need($hdr, ['mapel', 'mata pelajaran'], 'Mapel');
        $iA = self::need($hdr, ['nama kegiatan', 'kegiatan'], 'Nama Kegiatan');
        $iT = self::need($hdr, ['tanggal', 'tanggal kegiatan'], 'Tanggal');
        $iN = self::need($hdr, ['nis'], 'NIS');
        $iV = self::need($hdr, ['nilai'], 'Nilai');
        $iP = \App\Support\Xlsx::col($hdr, ['tipe', 'tipe skala']);
        $iG = \App\Support\Xlsx::col($hdr, ['guru', 'pemilik']);
        $classes = SchoolClass::get()->keyBy(fn ($c) => self::norm($c->name));
        $subjects = Subject::get()->keyBy(fn ($s) => self::norm($s->name));
        $users = self::userIndex();
        $roster = [];
        $groups = []; // kunci kegiatan → data kegiatan + nilai
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $row = 'Baris '.($n + 2);
            $cls = $classes[self::norm(self::cell($r, $iK))] ?? null;
            $sub = $subjects[self::norm(self::cell($r, $iM))] ?? null;
            $nama = self::cell($r, $iA);
            $tgl = self::parseDate(self::cell($r, $iT));
            $tipe = strtolower(self::cell($r, $iP)) ?: 'angka';
            $nis = self::cell($r, $iN);
            $v = self::cell($r, $iV);
            $sid = $cls ? (self::roster($roster, $cls->id)[self::norm($nis)] ?? null) : null;
            $galat = match (true) {
                ! $cls => "kelas '".self::cell($r, $iK)."' tidak ditemukan",
                ! $sub => "mapel '".self::cell($r, $iM)."' tidak ditemukan",
                $nama === '' || mb_strlen($nama) > 191 => 'nama kegiatan kosong/terlalu panjang',
                ! $tgl => "tanggal '".self::cell($r, $iT)."' tidak valid",
                \App\Support\Dates::isFuture($tgl) => "tanggal $tgl di masa depan",
                ! in_array($tipe, ['angka', 'huruf'], true) => "tipe '$tipe' harus angka/huruf",
                ! $sid => "NIS '$nis' tidak ada di kelas {$cls->name}",
                $v !== '' && $tipe === 'angka' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 100) => "nilai '$v' bukan angka 0–100",
                $v !== '' && $tipe === 'huruf' && ! in_array(strtoupper($v), ['A', 'B', 'C', 'D', 'E'], true) => "nilai '$v' bukan huruf A–E",
                default => null,
            };
            if ($galat) {
                $out['skip'][] = "$row: $galat";
                continue;
            }
            $key = $cls->id.'|'.$sub->id.'|'.self::norm($nama).'|'.$tgl;
            $groups[$key] ??= ['cls' => $cls, 'sub' => $sub, 'nama' => $nama, 'tgl' => $tgl, 'tipe' => $tipe, 'guru' => self::pencatat($users, self::cell($r, $iG), $actor), 'vals' => [], 'row' => $row];
            if ($groups[$key]['tipe'] !== $tipe) {
                $out['skip'][] = "$row: tipe berbeda dengan baris lain pada kegiatan '$nama' — dilewati";
            } elseif (isset($groups[$key]['vals'][$sid])) {
                $out['skip'][] = "$row: NIS '$nis' muncul dua kali pada kegiatan '$nama' — dilewati";
            } elseif ($v !== '') {
                $groups[$key]['vals'][$sid] = $tipe === 'huruf' ? strtoupper($v) : $v;
            }
        }
        $now = now('UTC')->format('Y-m-d H:i:s');
        foreach ($groups as $g) {
            $label = "{$g['nama']} ({$g['cls']->name}, {$g['sub']->name}, {$g['tgl']})";
            if (\App\Models\GradeActivity::where(['class_id' => $g['cls']->id, 'subject_id' => $g['sub']->id, 'nama_kegiatan' => $g['nama'], 'tanggal_kegiatan' => $g['tgl']])->exists()) {
                $out['skip'][] = "Kegiatan $label sudah ada — dilewati";
                continue;
            }
            if (! $g['vals']) {
                $out['skip'][] = "Kegiatan $label: tidak ada nilai terisi — tidak dibuat";
                continue;
            }
            $id = 'act-'.\Illuminate\Support\Str::uuid();
            \App\Models\GradeActivity::create(['id' => $id, 'teacher_id' => $g['guru'], 'subject_id' => $g['sub']->id, 'class_id' => $g['cls']->id,
                'nama_kegiatan' => $g['nama'], 'tanggal_kegiatan' => $g['tgl'], 'tipe_skala' => $g['tipe'], 'created_at' => $now]);
            \App\Models\GradeValue::insert(array_map(fn ($sid, $v) => ['activity_id' => $id, 'student_id' => $sid, 'nilai' => $v], array_keys($g['vals']), $g['vals']));
            $out['ok']++;
        }
    }

    private static function mapel(User $a, array $hdr, array $rows, array &$out): void
    {
        $iN = self::need($hdr, ['nama mapel', 'mapel', 'mata pelajaran', 'nama'], 'Nama Mapel');
        $have = Subject::pluck('name')->map(fn ($n) => mb_strtolower($n))->flip()->all();
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $name = self::cell($r, $iN);
            $row = 'Baris '.($n + 2);
            if ($name === '' || mb_strlen($name) > 191) {
                $out['skip'][] = "$row: nama mapel kosong/terlalu panjang";
            } elseif (isset($have[mb_strtolower($name)])) {
                $out['skip'][] = "$row ($name): mapel sudah ada";
            } else {
                Subject::create(['name' => $name]);
                $have[mb_strtolower($name)] = true;
                $out['ok']++;
            }
        }
    }

    private static function siswa(User $a, array $hdr, array $rows, array &$out): void
    {
        [$ok, $skip] = AdminService::importStudentRows($a, $hdr, $rows);
        $out['ok'] += $ok;
        $out['skip'] = array_merge($out['skip'], $skip);
    }

    /** "A, B; C" → ['A','B','C'] */
    private static function list(string $v): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $v) ?: []), fn ($x) => $x !== ''));
    }

    private static function guru(User $a, array $hdr, array $rows, array &$out): void
    {
        $iU = self::need($hdr, ['username', 'user', 'nama pengguna'], 'Username');
        $iN = self::need($hdr, ['nama', 'nama lengkap'], 'Nama');
        $iR = \App\Support\Xlsx::col($hdr, ['peran', 'role']);
        $iP = \App\Support\Xlsx::col($hdr, ['password', 'kata sandi']);
        $iW = \App\Support\Xlsx::col($hdr, ['wali kelas', 'wali']);
        $iM = \App\Support\Xlsx::col($hdr, ['mapel', 'mata pelajaran']);
        $iK = \App\Support\Xlsx::col($hdr, ['kelas', 'kelas diajar']);
        $classes = SchoolClass::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => (int) $id])->all();
        $subjects = Subject::regular()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => (int) $id])->all();
        $roleMap = ['guru' => 'guru', 'kepsek' => 'kepsek', 'kepala sekolah' => 'kepsek', 'bk' => 'bk', 'guru bk' => 'bk', 'tu' => 'tu', 'tata usaha' => 'tu', 'staf' => 'tu', 'staff' => 'tu'];
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $u = strtolower(self::cell($r, $iU));
            $row = 'Baris '.($n + 2)." ($u)";
            try {
                $roles = [];
                foreach (self::list(self::cell($r, $iR)) as $x) {
                    $k = $roleMap[mb_strtolower($x)] ?? throw new UserError("peran \"$x\" tidak dikenal (pakai: guru, kepsek, bk, tu)");
                    $roles[$k] = $k;
                }
                $wali = self::cell($r, $iW);
                $waliId = $wali === '' ? null : ($classes[mb_strtolower($wali)] ?? throw new UserError("kelas wali \"$wali\" tidak ditemukan"));
                $sIds = array_map(fn ($x) => $subjects[mb_strtolower($x)] ?? throw new UserError("mapel \"$x\" tidak ditemukan"), self::list(self::cell($r, $iM)));
                $cIds = array_map(fn ($x) => $classes[mb_strtolower($x)] ?? throw new UserError("kelas \"$x\" tidak ditemukan"), self::list(self::cell($r, $iK)));
                $pw = self::cell($r, $iP);
                $generated = false;
                if ($pw === '') {
                    $pw = PasswordPolicy::generate();
                    $generated = true;
                } else {
                    PasswordPolicy::assert($pw);
                }
                $nama = self::cell($r, $iN);
                if ($nama === '') {
                    throw new UserError('nama kosong');
                }
                // Savepoint per baris agar kegagalan satu baris tidak membatalkan baris lain.
                DB::transaction(fn () => AdminService::addTeacher($a, ['username' => $u, 'nama' => $nama, 'password' => $pw, 'roles' => array_values($roles) ?: ['guru'],
                    'kelas_wali_id' => $waliId, 'subjects' => $sIds, 'classes' => $cIds]));
                if ($generated) {
                    $out['passwords'][$u] = $pw;
                }
                $out['ok']++;
            } catch (UserError $e) {
                $out['skip'][] = "$row: ".$e->getMessage();
            }
        }
    }

    private static function pasangan(User $a, array $hdr, array $rows, array &$out): void
    {
        $iU = self::need($hdr, ['username', 'user'], 'Username');
        $iM = self::need($hdr, ['mapel', 'mata pelajaran'], 'Mapel');
        $iK = self::need($hdr, ['kelas'], 'Kelas');
        $users = User::pluck('id', 'username')->mapWithKeys(fn ($id, $n) => [strtolower($n) => (int) $id])->all();
        $classes = SchoolClass::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => (int) $id])->all();
        $subjects = Subject::regular()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => (int) $id])->all();
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $u = strtolower(self::cell($r, $iU));
            $m = self::cell($r, $iM);
            $k = self::cell($r, $iK);
            $row = 'Baris '.($n + 2)." ($u)";
            $uid = $users[$u] ?? null;
            $sid = $subjects[mb_strtolower($m)] ?? null;
            $cid = $classes[mb_strtolower($k)] ?? null;
            if (! $uid) {
                $out['skip'][] = "$row: username tidak ditemukan";
            } elseif (! $sid) {
                $out['skip'][] = "$row: mapel \"$m\" tidak ditemukan";
            } elseif (! $cid) {
                $out['skip'][] = "$row: kelas \"$k\" tidak ditemukan";
            } elseif (Pairing::where(['user_id' => $uid, 'subject_id' => $sid, 'class_id' => $cid])->exists()) {
                $out['skip'][] = "$row: pasangan sudah ada";
            } else {
                Pairing::create(['user_id' => $uid, 'subject_id' => $sid, 'class_id' => $cid]);
                $out['ok']++;
            }
        }
    }
}
