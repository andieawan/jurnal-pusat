<?php

namespace App\Http\Controllers;

use App\Models\AiKunciMgmp;
use App\Models\Pairing;
use App\Models\PerangkatAjar;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assignments;
use App\Services\Audit;
use App\Services\BahanAjar;
use App\Services\Perangkat;
use App\Services\PerangkatAi;
use App\Services\ProfilPusat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Perangkat Ajar dengan alur MGMP:
 *  1. Tim MGMP (peran "mgmp" dari Pusat) menyusun DRAF per mapel + tingkat tiap semester — dari templat atau AI,
 *     boleh dengan bahan ajar rujukan (PDF/DOCX/TXT) — lalu menandainya "siap".
 *  2. Guru yang mengampu mapel itu MENGAMBIL draf untuk kelasnya: salinan menjadi perangkat ajar miliknya dengan
 *     identitas dirinya (nama, NIP, kelas, fase), lalu bisa diubah, dicetak berkop, dan diunduh Word. Tanpa AI lagi,
 *     sehingga pemakaian API cukup sekali per draf MGMP.
 */
class PerangkatAjarController extends Controller
{
    /** Mapel tempat pengguna boleh menyusun draf MGMP. */
    private function mapelMgmp(User $u): Collection
    {
        if ($u->isAdmin()) {
            return Subject::regular()->orderBy('name')->get();
        }

        return $u->hasRole('mgmp') ? Assignments::subjects($u) : collect();
    }

    private function bisaUbah(PerangkatAjar $p): bool
    {
        $u = $this->me();
        if ($u->isAdmin()) {
            return true;
        }

        return $p->jenis === 'mgmp' ? $this->mapelMgmp($u)->contains('id', $p->subject_id) : $p->user_id === $u->id;
    }

    private function bisaLihat(PerangkatAjar $p): bool
    {
        $u = $this->me();

        return $this->bisaUbah($p) || $u->hasRole('kepsek') || $p->user_id === $u->id
            || ($p->jenis === 'mgmp' && $p->status === 'siap' && Assignments::subjects($u)->contains('id', $p->subject_id));
    }

    private function dok(string $dok): string
    {
        abort_unless(isset(Perangkat::DOKUMEN[$dok]), 404);

        return $dok;
    }

    public function index()
    {
        $u = $this->me();
        abort_unless($u->can('perangkat'), 403);
        $set = SchoolSetting::current();
        $mapelSaya = Assignments::subjects($u)->pluck('id');
        $mgmp = $this->mapelMgmp($u);
        $semua = $u->isAdmin() || $u->hasRole('kepsek');
        $muat = fn ($q) => $q->with(['mapel', 'kelas', 'guru'])->orderByDesc('updated_at');

        return view('perangkat.index', [
            'set' => $set, 'mgmp' => $mgmp->isNotEmpty(), 'semua' => $semua, 'bisaBuat' => $this->mapelGuru($u)->isNotEmpty(), 'bisaPeriksa' => $this->bisaPeriksa(),
            'periksa' => $this->bisaPeriksa() ? $muat(PerangkatAjar::where(['jenis' => 'guru', 'pengesahan' => 'diajukan'])->where('user_id', '!=', $u->id))->get() : collect(),
            'draf' => $mgmp->isEmpty() ? collect() : $muat(PerangkatAjar::where('jenis', 'mgmp')->whereIn('subject_id', $mgmp->pluck('id')))->limit(60)->get(),
            'siap' => $muat(PerangkatAjar::where(['jenis' => 'mgmp', 'status' => 'siap', 'tahun' => (string) $set->tahun_ajaran, 'semester' => (string) $set->semester])
                ->whereIn('subject_id', $mapelSaya))->get(),
            'diambil' => PerangkatAjar::where(['jenis' => 'guru', 'user_id' => $u->id])->whereNotNull('dari_id')->pluck('dari_id')->all(),
            'milik' => $muat(PerangkatAjar::where('jenis', 'guru')->when(! $semua, fn ($q) => $q->where('user_id', $u->id)))->paginate(20),
        ]);
    }

    // ------------------------------------------------------------ membuat: draf MGMP atau perangkat sendiri

    /** Mapel yang diampu guru (untuk perangkat ajar miliknya sendiri), tanpa BK. */
    private function mapelGuru(User $u): Collection
    {
        return Assignments::subjects($u)->reject(fn ($s) => (int) $s->id === (int) config('absensi.bk_subject_id'))->values();
    }

    private function nipLama(User $u): string
    {
        return (string) (PerangkatAjar::where(['jenis' => 'guru', 'user_id' => $u->id])->orderByDesc('id')->first()?->isian['nip'] ?? '');
    }

    public function create(Request $request)
    {
        $u = $this->me();
        $mgmp = $this->mapelMgmp($u);
        $jenis = $request->query('jenis') === 'guru' || $mgmp->isEmpty() ? 'guru' : 'mgmp';
        $mapel = $jenis === 'mgmp' ? $mgmp : $this->mapelGuru($u);
        abort_if($mapel->isEmpty(), 403, $jenis === 'mgmp' ? 'Hanya tim MGMP yang menyusun draf perangkat ajar.' : 'Belum ada mapel yang Anda ampu.');
        $dari = $request->integer('dari') ? PerangkatAjar::find($request->integer('dari')) : null;
        abort_if($dari && ! $this->bisaLihat($dari), 403);
        $want = (int) ($request->input('subject') ?: ($dari->subject_id ?? 0));
        $subjectId = $mapel->contains('id', $want) ? $want : (int) $mapel->first()->id;
        $tingkat = Assignments::classesForSubject($u, $subjectId)->map(fn ($k) => Perangkat::tingkat($k))->filter()->unique()->values();

        return view('perangkat.form', [
            'jenis' => $jenis, 'subjects' => $mapel, 'subjectId' => $subjectId, 'isian' => $dari?->isian ?? [],
            'classMap' => $jenis === 'guru' ? Assignments::classMap($u, $mapel) : [],
            'classes' => $jenis === 'guru' ? Assignments::classesForSubject($u, $subjectId) : collect(), 'classId' => $request->integer('class'),
            'tingkat' => $dari->tingkat ?? ($tingkat->first() ?: 'X'), 'ai' => PerangkatAi::tersedia($u, $subjectId),
            'set' => SchoolSetting::current(), 'bisaMgmp' => $mgmp->isNotEmpty(),
        ]);
    }

    public function store(Request $request)
    {
        $u = $this->me();
        $jenis = $request->input('jenis') === 'guru' ? 'guru' : 'mgmp';
        $daftar = $jenis === 'mgmp' ? $this->mapelMgmp($u) : $this->mapelGuru($u);
        abort_if($daftar->isEmpty(), 403);
        $d = $request->validate([
            'subject' => 'required|integer',
            'tingkat' => $jenis === 'mgmp' ? ['required', Rule::in(['X', 'XI', 'XII'])] : 'nullable',
            'class' => $jenis === 'guru' ? 'required|integer' : 'nullable',
            'mulai' => ['required', Rule::in(['templat', 'kosong', 'ai'])],
            'topik' => 'required|string|max:200', 'tp' => 'required|string|max:2000', 'elemen' => 'nullable|string|max:300',
            'konsentrasi' => 'nullable|string|max:150',
            'alokasi' => 'required|string|max:60', 'pertemuan' => 'required|integer|min:1|max:6',
            'model' => ['required', Rule::in(array_keys(Perangkat::MODEL))],
            'metode' => 'nullable|array', 'metode.*' => [Rule::in(Perangkat::METODE)],
            'dimensi' => 'nullable|array', 'dimensi.*' => [Rule::in(Perangkat::DIMENSI)],
            'lingkungan' => 'nullable|array', 'lingkungan.*' => [Rule::in(Perangkat::LINGKUNGAN)],
            'media' => 'nullable|array', 'media.*' => [Rule::in(Perangkat::MEDIA)],
            'kesiapan' => 'nullable|string|max:600', 'sarana' => 'nullable|string|max:600', 'catatan' => 'nullable|string|max:1000',
            'dokumen' => 'required|array|min:1', 'dokumen.*' => [Rule::in(array_keys(Perangkat::DOKUMEN))],
            'bahan' => 'nullable|array|max:'.BahanAjar::MAKS_BERKAS, 'bahan.*' => 'file|mimes:'.BahanAjar::JENIS.'|max:'.BahanAjar::MAKS_KB,
        ], ['dokumen.required' => 'Pilih minimal satu dokumen.', 'tp.required' => 'Tujuan pembelajaran wajib diisi.', 'topik.required' => 'Topik wajib diisi.',
            'class.required' => 'Pilih kelas.', 'bahan.*.mimes' => 'Bahan ajar harus PDF, DOCX, atau TXT.', 'bahan.*.max' => 'Ukuran tiap bahan ajar maksimal 8 MB.',
            'bahan.max' => 'Maksimal 3 berkas bahan ajar.']);

        $mapel = $daftar->firstWhere('id', (int) $d['subject']);
        if (! $mapel) {
            return back()->withInput()->with('error', $jenis === 'mgmp' ? 'Anda tidak termasuk tim MGMP mapel itu.' : 'Anda tidak mengampu mapel itu.');
        }
        $kelas = $jenis === 'guru' ? Assignments::classesForSubject($u, $mapel->id)->firstWhere('id', (int) $d['class']) : null;
        if ($jenis === 'guru' && ! $kelas) {
            return back()->withInput()->with('error', 'Pilih kelas yang Anda ampu untuk mapel ini.');
        }
        $tingkat = $kelas ? Perangkat::tingkat($kelas) : $d['tingkat'];
        $set = SchoolSetting::current();
        $isian = [
            'mapel' => $mapel->name, 'kelas' => $kelas ? $kelas->name : 'Kelas '.$tingkat, 'fase' => $kelas ? Perangkat::fase($kelas) : Perangkat::fase($tingkat),
            'konsentrasi' => ($d['konsentrasi'] ?? '') ?: (string) ($kelas->jurusan ?? ''),
            'guru' => $kelas ? $u->nama : 'Tim MGMP '.$mapel->name, 'nip' => $kelas ? $this->nipLama($u) : '',
            'sekolah' => $set->school_name ?: config('absensi.school_name'), 'kepsek' => $set->kepsek_nama ?: config('absensi.surat.kepsek'),
            'kepsek_nip' => ProfilPusat::nilai('kepsek_nip') ?: config('absensi.surat.kepsek_nuptk'),
            'tahun' => $set->tahun_ajaran, 'semester' => $set->semester,
            'topik' => $d['topik'], 'tp' => $d['tp'], 'elemen' => $d['elemen'] ?? '',
            'alokasi' => $d['alokasi'], 'pertemuan' => (int) $d['pertemuan'], 'model' => $d['model'],
            'metode' => array_values($d['metode'] ?? []), 'dimensi' => array_values($d['dimensi'] ?? []),
            'lingkungan' => array_values($d['lingkungan'] ?? []), 'media' => array_values($d['media'] ?? []),
            'kesiapan' => $d['kesiapan'] ?? '', 'sarana' => $d['sarana'] ?? '', 'catatan' => $d['catatan'] ?? '', 'bahan' => [],
        ];
        $pakaiAi = $d['mulai'] === 'ai' && PerangkatAi::tersedia($u, $mapel->id);
        $mulai = $d['mulai'] === 'ai' && ! $pakaiAi ? 'templat' : $d['mulai'];
        $dokumen = array_values(array_intersect(array_keys(Perangkat::DOKUMEN), $d['dokumen']));
        $p = PerangkatAjar::create([
            'jenis' => $jenis, 'user_id' => $u->id, 'subject_id' => $mapel->id, 'class_id' => $kelas?->id, 'tingkat' => $tingkat,
            'tahun' => (string) $set->tahun_ajaran, 'semester' => (string) $set->semester, 'topik' => $d['topik'], 'isian' => $isian, 'mulai' => $mulai,
            'hasil' => array_combine($dokumen, array_map(fn ($k) => $mulai === 'kosong' ? Perangkat::kosong($isian, $k) : Perangkat::templat($isian, $k), $dokumen)),
        ]);
        if ($request->hasFile('bahan')) {
            $isian['bahan'] = array_map(fn ($f) => BahanAjar::simpan($f, $p->id), $request->file('bahan'));
            $p->update(['isian' => $isian]);
        }
        Audit::log($jenis === 'mgmp' ? 'Buat Draf MGMP' : 'Buat Perangkat Ajar', 'Perangkat Ajar', $u->nama, "{$mapel->name} · {$isian['kelas']} · {$d['topik']}");
        $pesan = match ($mulai) {
            'ai' => 'Draf dibuat. AI sedang mengisi dokumen satu per satu…',
            'kosong' => 'Perangkat dibuat kosong. Isi tiap dokumen lewat tombol Ubah isi.',
            default => 'Perangkat dibuat dari templat. Lengkapi bagian bertanda [ … ] lewat Ubah isi.',
        };
        if ($jenis === 'mgmp' && $mulai !== 'ai') {
            $pesan .= ' Setelah lengkap, tandai siap dipakai guru.';
        }

        return redirect()->route('perangkat.show', array_filter(['perangkat' => $p, 'isi_ai' => $pakaiAi ? 1 : null]))->with('success', $pesan);
    }

    public function status(PerangkatAjar $perangkat)
    {
        abort_unless($perangkat->jenis === 'mgmp' && $this->bisaUbah($perangkat), 403);
        $perangkat->update(['status' => $perangkat->status === 'siap' ? 'draf' : 'siap']);

        return back()->with('success', $perangkat->status === 'siap' ? 'Draf ditandai siap: guru mapel ini sekarang bisa mengambilnya.' : 'Draf dikembalikan ke tahap penyusunan (tidak tampil untuk guru).');
    }

    // ------------------------------------------------------------ guru mengambil draf

    public function ambil(Request $request, PerangkatAjar $perangkat)
    {
        $u = $this->me();
        abort_unless($perangkat->jenis === 'mgmp' && $this->bisaLihat($perangkat), 403);
        $kelas = Assignments::classesForSubject($u, $perangkat->subject_id)->firstWhere('id', $request->integer('class'));
        if (! $kelas) {
            return back()->with('error', 'Pilih kelas yang Anda ampu untuk mapel ini.');
        }
        $ada = PerangkatAjar::where(['jenis' => 'guru', 'user_id' => $u->id, 'dari_id' => $perangkat->id, 'class_id' => $kelas->id])->first();
        if ($ada) {
            return redirect()->route('perangkat.show', $ada)->with('info', 'Draf ini sudah Anda ambil untuk '.$kelas->name.'.');
        }
        $isian = array_merge($perangkat->isian, ['guru' => $u->nama, 'nip' => $this->nipLama($u), 'kelas' => $kelas->name, 'fase' => Perangkat::fase($kelas) ?: $perangkat->isian['fase'],
            'konsentrasi' => $perangkat->isian['konsentrasi'] ?: (string) $kelas->jurusan, 'bahan' => []]);
        $p = PerangkatAjar::create([
            'jenis' => 'guru', 'user_id' => $u->id, 'subject_id' => $perangkat->subject_id, 'class_id' => $kelas->id,
            'tingkat' => Perangkat::tingkat($kelas), 'tahun' => $perangkat->tahun, 'semester' => $perangkat->semester,
            'topik' => $perangkat->topik, 'isian' => $isian, 'hasil' => $perangkat->hasil, 'sumber' => $perangkat->sumber, 'dari_id' => $perangkat->id, 'mulai' => 'mgmp',
        ]);
        Audit::log('Ambil Draf MGMP', 'Perangkat Ajar', $u->nama, "{$perangkat->topik} → {$kelas->name}");

        return redirect()->route('perangkat.show', $p)->with('success', 'Draf MGMP diambil untuk '.$kelas->name.'. Sesuaikan dengan kelas Anda lewat Ubah isi.');
    }

    public function identitas(Request $request, PerangkatAjar $perangkat)
    {
        abort_unless($perangkat->jenis === 'guru' && $this->bisaUbah($perangkat), 403);
        $d = $request->validate(['nip' => 'nullable|string|max:40', 'konsentrasi' => 'nullable|string|max:150', 'alokasi' => 'required|string|max:60']);
        $batal = $this->ubahIsi($perangkat, ['isian' => array_merge($perangkat->isian, ['nip' => $d['nip'] ?? '', 'konsentrasi' => $d['konsentrasi'] ?? '', 'alokasi' => $d['alokasi']])]);

        return back()->with('success', 'Identitas diperbarui.'.$batal);
    }

    // ------------------------------------------------------------ pemeriksaan kepala sekolah

    private function bisaPeriksa(): bool
    {
        return $this->me()->hasRole('kepsek') || $this->me()->isAdmin();
    }

    /** Simpan perubahan isi perangkat guru; perangkat yang sudah disahkan kembali ke draf (perlu diajukan ulang). */
    private function ubahIsi(PerangkatAjar $p, array $kolom): string
    {
        $batal = $p->jenis === 'guru' && $p->pengesahan === 'disetujui';
        $p->update($kolom + ($batal ? ['pengesahan' => '', 'pengesahan_pada' => null, 'pengesahan_oleh' => ''] : []));

        return $batal ? ' Karena isinya berubah, pengesahan kepala sekolah dibatalkan; ajukan ulang bila sudah selesai.' : '';
    }

    public function ajukan(PerangkatAjar $perangkat)
    {
        abort_unless($perangkat->jenis === 'guru' && $perangkat->user_id === $this->me()->id, 403);
        if (in_array($perangkat->pengesahan, ['diajukan', 'disetujui'], true)) {
            return back()->with('info', $perangkat->pengesahan === 'diajukan' ? 'Perangkat ini sudah diajukan dan menunggu pemeriksaan.' : 'Perangkat ini sudah disahkan.');
        }
        $perangkat->update(['pengesahan' => 'diajukan']);
        Audit::log('Ajukan Perangkat Ajar', 'Perangkat Ajar', $this->me()->nama, "{$perangkat->topik} · {$perangkat->isian['kelas']}");

        return back()->with('success', 'Perangkat ajar diajukan ke kepala sekolah untuk diperiksa.');
    }

    public function periksa(Request $request, PerangkatAjar $perangkat)
    {
        abort_unless($perangkat->jenis === 'guru' && $this->bisaPeriksa() && $perangkat->user_id !== $this->me()->id, 403);
        $d = $request->validate(['keputusan' => 'required|in:setujui,revisi', 'catatan' => 'nullable|required_if:keputusan,revisi|string|max:1000'],
            ['catatan.required_if' => 'Tulis catatan perbaikan untuk guru.']);
        $setuju = $d['keputusan'] === 'setujui';
        $perangkat->update(['pengesahan' => $setuju ? 'disetujui' : 'revisi', 'pengesahan_catatan' => $d['catatan'] ?? null,
            'pengesahan_oleh' => $this->me()->nama, 'pengesahan_pada' => now('UTC')->format('Y-m-d H:i:s')]);
        Audit::log($setuju ? 'Sahkan Perangkat Ajar' : 'Minta Perbaikan Perangkat Ajar', 'Perangkat Ajar', $this->me()->nama,
            "{$perangkat->topik} · {$perangkat->isian['kelas']} · {$perangkat->isian['guru']}");

        return redirect()->to(route('perangkat').'#periksa')->with('success', $setuju ? 'Perangkat ajar disahkan.' : 'Catatan perbaikan dikirim ke guru.');
    }

    /** Rekap kelengkapan: tiap pasangan guru × mapel × kelas semester ini sudah/belum punya perangkat ajar. */
    public function rekap(Request $request)
    {
        abort_unless($this->bisaPeriksa(), 403);
        $set = SchoolSetting::current();
        $kelas = SchoolClass::orderBy('name')->get()->keyBy('id');
        $mapel = Subject::regular()->get()->keyBy('id');
        $pasangan = Pairing::get(['user_id', 'class_id', 'subject_id'])->groupBy('user_id');
        $perangkat = PerangkatAjar::where(['jenis' => 'guru', 'tahun' => (string) $set->tahun_ajaran, 'semester' => (string) $set->semester])
            ->get(['id', 'user_id', 'subject_id', 'class_id', 'topik', 'pengesahan'])->groupBy(fn ($p) => "$p->user_id:$p->subject_id:$p->class_id");
        $baris = [];
        foreach (User::where('is_active', true)->orderBy('nama')->get() as $g) {
            if (! $g->hasRole('guru') || $g->isAdmin()) {
                continue;
            }
            $kunci = [];
            foreach ($pasangan[$g->id] ?? [] as $x) {
                $kunci["$x->subject_id:$x->class_id"] = true;
            }
            foreach (array_map('intval', $g->subjects ?? []) as $sid) {
                foreach (array_map('intval', $g->classes ?? []) as $cid) {
                    $kunci["$sid:$cid"] = true;
                }
            }
            foreach (array_keys($kunci) as $k) {
                [$sid, $cid] = array_map('intval', explode(':', $k));
                if (! isset($mapel[$sid], $kelas[$cid])) {
                    continue;
                }
                $ps = $perangkat["$g->id:$sid:$cid"] ?? collect();
                $status = match (true) {
                    $ps->isEmpty() => 'belum',
                    $ps->contains('pengesahan', 'disetujui') => 'disetujui',
                    $ps->contains('pengesahan', 'diajukan') => 'diajukan',
                    $ps->contains('pengesahan', 'revisi') => 'revisi',
                    default => 'draf',
                };
                $baris[] = ['guru' => $g->nama, 'mapel' => $mapel[$sid]->name, 'kelas' => $kelas[$cid]->name, 'perangkat' => $ps, 'status' => $status];
            }
        }
        $baris = collect($baris)->sortBy(fn ($b) => [$b['guru'], $b['mapel'], $b['kelas']])->values();
        $hitung = $baris->countBy('status');
        $saring = in_array($request->query('status'), ['belum', 'draf', 'diajukan', 'revisi', 'disetujui'], true) ? $request->query('status') : '';

        return view('perangkat.rekap', ['set' => $set, 'hitung' => $hitung, 'total' => $baris->count(), 'saring' => $saring,
            'baris' => $saring ? $baris->where('status', $saring)->values() : $baris]);
    }

    // ------------------------------------------------------------ bersama

    public function show(Request $request, PerangkatAjar $perangkat)
    {
        abort_unless($this->bisaLihat($perangkat), 403);
        $u = $this->me();
        $ada = array_keys($perangkat->hasil);
        $dok = in_array($request->query('dok'), $ada, true) ? $request->query('dok') : ($ada[0] ?? 'modul');
        $bisaUbah = $this->bisaUbah($perangkat);
        $kelasAmbil = $perangkat->jenis === 'mgmp' ? Assignments::classesForSubject($u, $perangkat->subject_id)
            ->filter(fn ($k) => $perangkat->tingkat === '' || Perangkat::tingkat($k) === $perangkat->tingkat)->values() : collect();

        return view('perangkat.show', ['p' => $perangkat, 'dok' => $dok, 'ada' => $ada, 'bisaUbah' => $bisaUbah,
            'ai' => $bisaUbah && PerangkatAi::tersedia($u, $perangkat->subject_id), 'isiAi' => $request->boolean('isi_ai'),
            'pakaiAi' => PerangkatAi::pemakaian($perangkat->subject_id),
            'pakaiGuru' => $perangkat->jenis === 'guru' ? PerangkatAi::pemakaianGuru($perangkat->user_id) : null,
            'periksa' => $perangkat->jenis === 'guru' && $this->bisaPeriksa() && $perangkat->user_id !== $u->id,
            'pemilik' => $perangkat->jenis === 'guru' && $perangkat->user_id === $u->id,
            'nilai' => $perangkat->jenis === 'guru' && $perangkat->class_id && $perangkat->user_id === $u->id && isset($perangkat->hasil['asesmen']),
            'kelasAmbil' => $kelasAmbil, 'asal' => $perangkat->dari_id ? PerangkatAjar::find($perangkat->dari_id) : null]);
    }

    public function edit(PerangkatAjar $perangkat, string $dok)
    {
        abort_unless($this->bisaUbah($perangkat), 403);
        $this->dok($dok);

        return view('perangkat.ubah', ['p' => $perangkat, 'dok' => $dok,
            'isi' => Perangkat::rapikan($perangkat->hasil[$dok] ?? Perangkat::templat($perangkat->isian, $dok), Perangkat::kerangka($dok))]);
    }

    public function update(Request $request, PerangkatAjar $perangkat, string $dok)
    {
        abort_unless($this->bisaUbah($perangkat), 403);
        $this->dok($dok);
        $hasil = $perangkat->hasil;
        $hasil[$dok] = Perangkat::rapikan($request->input('isi', []), Perangkat::kerangka($dok));
        $batal = $this->ubahIsi($perangkat, ['hasil' => $hasil]);

        return redirect()->route('perangkat.show', ['perangkat' => $perangkat, 'dok' => $dok])->with('success', Perangkat::DOKUMEN[$dok].' tersimpan.'.$batal);
    }

    /** Isi satu dokumen draf MGMP dengan AI (dipanggil berurutan oleh halaman sebagai JSON, atau lewat tombol biasa). */
    public function ai(Request $request, PerangkatAjar $perangkat, string $dok)
    {
        abort_unless($this->bisaUbah($perangkat), 403);
        $this->dok($dok);
        try {
            $isi = PerangkatAi::isi($perangkat->isian, $dok, $this->me(), $perangkat->subject_id, $perangkat->jenis);
        } catch (RuntimeException $e) {
            return $request->expectsJson() ? response()->json(['ok' => false, 'pesan' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        }
        $hasil = $perangkat->hasil;
        $hasil[$dok] = $isi;
        $sumber = array_unique(array_filter(array_merge(explode(',', $perangkat->sumber), [$dok])));
        $perangkat->update(['hasil' => $hasil, 'sumber' => implode(',', $sumber)]);
        Audit::log('Isi Perangkat dengan AI', 'Perangkat Ajar', $this->me()->nama, Perangkat::DOKUMEN[$dok].' · '.$perangkat->topik);

        return $request->expectsJson() ? response()->json(['ok' => true])
            : redirect()->route('perangkat.show', ['perangkat' => $perangkat, 'dok' => $dok])->with('success', Perangkat::DOKUMEN[$dok].' diisi oleh AI. Periksa dan sesuaikan sebelum dipakai.');
    }

    public function bahanTambah(Request $request, PerangkatAjar $perangkat)
    {
        abort_unless($this->bisaUbah($perangkat), 403);
        $isian = $perangkat->isian;
        $sisa = BahanAjar::MAKS_BERKAS - count($isian['bahan'] ?? []);
        $request->validate(['bahan' => 'required|array|max:'.max(0, $sisa), 'bahan.*' => 'file|mimes:'.BahanAjar::JENIS.'|max:'.BahanAjar::MAKS_KB],
            ['bahan.max' => 'Maksimal 3 berkas bahan ajar per draf.', 'bahan.*.mimes' => 'Bahan ajar harus PDF, DOCX, atau TXT.', 'bahan.*.max' => 'Ukuran tiap bahan ajar maksimal 8 MB.']);
        foreach ($request->file('bahan') as $f) {
            $isian['bahan'][] = BahanAjar::simpan($f, $perangkat->id);
        }
        $perangkat->update(['isian' => $isian]);

        return back()->with('success', 'Bahan ajar ditambahkan. Gunakan "Tulis ulang dengan AI" agar isinya memakai bahan ini.');
    }

    public function bahanUnduh(PerangkatAjar $perangkat, int $i)
    {
        abort_unless($this->bisaLihat($perangkat), 403);
        $b = $perangkat->isian['bahan'][$i] ?? abort(404);

        return Storage::disk('local')->download($b['path'], $b['nama']);
    }

    public function bahanHapus(PerangkatAjar $perangkat, int $i)
    {
        abort_unless($this->bisaUbah($perangkat), 403);
        $isian = $perangkat->isian;
        $b = $isian['bahan'][$i] ?? abort(404);
        BahanAjar::hapus($b);
        array_splice($isian['bahan'], $i, 1);
        $perangkat->update(['isian' => $isian]);

        return back()->with('success', 'Bahan ajar "'.$b['nama'].'" dihapus.');
    }

    /** Unduh sebagai dokumen Word (HTML yang dibuka Microsoft Word / LibreOffice). */
    public function unduh(PerangkatAjar $perangkat, string $dok)
    {
        abort_unless($this->bisaLihat($perangkat), 403);
        abort_unless(isset($perangkat->hasil[$this->dok($dok)]), 404);
        $nama = preg_replace('/[^A-Za-z0-9]+/', '-', Perangkat::DOKUMEN[$dok].' '.$perangkat->isian['mapel'].' '.$perangkat->isian['kelas'].' '.$perangkat->topik);

        return response(view('perangkat.word', ['p' => $perangkat, 'dok' => $dok])->render(), 200, [
            'Content-Type' => 'application/msword; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.trim(mb_substr($nama, 0, 120), '-').'.doc"',
        ]);
    }

    public function destroy(PerangkatAjar $perangkat)
    {
        abort_unless($this->bisaUbah($perangkat), 403);
        foreach ($perangkat->isian['bahan'] ?? [] as $b) {
            BahanAjar::hapus($b);
        }
        $perangkat->delete();
        Audit::log('Hapus Perangkat Ajar', 'Perangkat Ajar', $this->me()->nama, $perangkat->topik);

        return redirect()->route('perangkat')->with('success', 'Perangkat ajar dihapus.');
    }

    // ------------------------------------------------------------ kunci AI pribadi

    public function kunci()
    {
        $u = $this->me();
        $mgmp = $this->mapelMgmp($u);
        $mapel = $mgmp->merge($this->mapelGuru($u))->unique('id')->sortBy('name')->values();
        abort_if($mapel->isEmpty(), 403);
        $bersama = AiKunciMgmp::whereIn('subject_id', $mapel->pluck('id'))->get()->keyBy('subject_id');

        return view('perangkat.kunci', ['sekolah' => PerangkatAi::kunciSekolah(), 'punya' => (string) $u->ai_key !== '',
            'akhir' => substr((string) $u->ai_key, -4), 'kuota' => max(0, (int) config('absensi.ai.kuota')),
            'mapel' => $mapel, 'mgmp' => $mgmp, 'pakaiSaya' => PerangkatAi::pemakaianGuru($u->id),
            'bersama' => $bersama, 'pakai' => $mapel->mapWithKeys(fn ($m) => [$m->id => PerangkatAi::pemakaian($m->id)])]);
    }

    public function kunciSimpan(Request $request)
    {
        $u = $this->me();
        $mapelMgmp = $this->mapelMgmp($u);
        abort_if($mapelMgmp->isEmpty() && $this->mapelGuru($u)->isEmpty(), 403);
        $mapel = $request->filled('mapel') ? $mapelMgmp->firstWhere('id', $request->integer('mapel')) : null;
        abort_if($request->filled('mapel') && ! $mapel, 403);
        if ($request->boolean('hapus')) {
            $mapel ? AiKunciMgmp::where('subject_id', $mapel->id)->delete() : $u->forceFill(['ai_key' => null])->save();
            $mapel && Audit::log('Hapus Kunci AI MGMP', 'Perangkat Ajar', $u->nama, $mapel->name);

            return redirect()->route('perangkat.kunci')->with('success', $mapel ? 'Kunci bersama MGMP '.$mapel->name.' dihapus.' : 'Kunci API Anda dihapus.');
        }
        $d = $request->validate(['ai_key' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-]{20,}$/']], ['ai_key.regex' => 'Format kunci tidak dikenali. Salin kunci dari Google AI Studio apa adanya.']);
        if ($mapel) {
            AiKunciMgmp::updateOrCreate(['subject_id' => $mapel->id], ['kunci' => $d['ai_key'], 'oleh' => $u->nama]);
            Audit::log('Simpan Kunci AI MGMP', 'Perangkat Ajar', $u->nama, $mapel->name.' · …'.substr($d['ai_key'], -4));

            return redirect()->route('perangkat.kunci')->with('success', 'Kunci bersama MGMP '.$mapel->name.' tersimpan (terenkripsi). Dipakai semua anggota MGMP mapel ini.');
        }
        $u->forceFill(['ai_key' => $d['ai_key']])->save();
        Audit::log('Simpan Kunci AI', 'Perangkat Ajar', $u->nama, 'kunci pribadi …'.substr($d['ai_key'], -4));

        return redirect()->route('perangkat.kunci')->with('success', 'Kunci API tersimpan (terenkripsi). Kunci hanya dipakai untuk perangkat yang Anda susun.');
    }
}
