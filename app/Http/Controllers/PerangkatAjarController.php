<?php

namespace App\Http\Controllers;

use App\Models\PerangkatAjar;
use App\Models\SchoolSetting;
use App\Services\Assignments;
use App\Services\Audit;
use App\Services\Perangkat;
use App\Services\PerangkatAi;
use App\Services\ProfilPusat;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Perangkat Ajar: guru menyusun modul ajar, LKPD, asesmen, bahan bacaan, dan tindak lanjut untuk mapel + kelas yang
 * diampunya. Identitas (guru, sekolah, kepala sekolah, fase, tahun ajaran) diisi otomatis; isi dokumen dari templat
 * atau dari AI (bila admin mengaktifkannya), lalu bisa diubah, dicetak berkop, dan diunduh sebagai Word.
 */
class PerangkatAjarController extends Controller
{
    private function bolehLihat(PerangkatAjar $p): void
    {
        $u = $this->me();
        abort_unless($p->user_id === $u->id || $u->isAdmin() || $u->hasRole('kepsek'), 403);
    }

    private function bolehUbah(PerangkatAjar $p): void
    {
        abort_unless($p->user_id === $this->me()->id || $this->me()->isAdmin(), 403);
    }

    private function dok(string $dok): string
    {
        abort_unless(isset(Perangkat::DOKUMEN[$dok]), 404);

        return $dok;
    }

    public function index(Request $request)
    {
        $u = $this->me();
        $semua = $u->isAdmin() || $u->hasRole('kepsek');
        $daftar = PerangkatAjar::with(['mapel', 'kelas', 'guru'])
            ->when(! $semua, fn ($q) => $q->where('user_id', $u->id))
            ->orderByDesc('updated_at')->paginate(20);

        return view('perangkat.index', ['daftar' => $daftar, 'semua' => $semua, 'bisaBuat' => $u->can('perangkat-buat')]);
    }

    public function create(Request $request)
    {
        abort_unless($this->me()->can('perangkat-buat'), 403);
        $u = $this->me();
        $dari = $request->integer('dari') ? PerangkatAjar::find($request->integer('dari')) : null;
        if ($dari) {
            $this->bolehLihat($dari);
        }
        $subjects = Assignments::subjects($u);
        $isian = $dari?->isian ?? [];
        $subjectId = (int) ($request->input('subject') ?: ($dari->subject_id ?? $subjects->first()?->id));
        $classes = $subjectId ? Assignments::classesForSubject($u, $subjectId) : collect();
        $classId = (int) ($request->input('class') ?: ($dari->class_id ?? $classes->first()?->id));
        $terakhir = PerangkatAjar::where('user_id', $u->id)->orderByDesc('id')->first();

        return view('perangkat.form', [
            'subjects' => $subjects, 'classes' => $classes, 'classMap' => Assignments::classMap($u, $subjects),
            'subjectId' => $subjectId, 'classId' => $classId, 'isian' => $isian,
            'nip' => $isian['nip'] ?? ($terakhir->isian['nip'] ?? ''),
            'ai' => PerangkatAi::tersedia(), 'set' => SchoolSetting::current(),
        ]);
    }

    public function store(Request $request)
    {
        $u = $this->me();
        abort_unless($u->can('perangkat-buat'), 403);
        $d = $request->validate([
            'subject' => 'required|integer', 'class' => 'required|integer',
            'topik' => 'required|string|max:200', 'tp' => 'required|string|max:2000', 'elemen' => 'nullable|string|max:300',
            'konsentrasi' => 'nullable|string|max:150', 'nip' => 'nullable|string|max:40',
            'alokasi' => 'required|string|max:60', 'pertemuan' => 'required|integer|min:1|max:6',
            'model' => ['required', Rule::in(array_keys(Perangkat::MODEL))],
            'metode' => 'nullable|array', 'metode.*' => [Rule::in(Perangkat::METODE)],
            'dimensi' => 'nullable|array', 'dimensi.*' => [Rule::in(Perangkat::DIMENSI)],
            'lingkungan' => 'nullable|array', 'lingkungan.*' => [Rule::in(Perangkat::LINGKUNGAN)],
            'media' => 'nullable|array', 'media.*' => [Rule::in(Perangkat::MEDIA)],
            'kesiapan' => 'nullable|string|max:600', 'sarana' => 'nullable|string|max:600', 'catatan' => 'nullable|string|max:1000',
            'dokumen' => 'required|array|min:1', 'dokumen.*' => [Rule::in(array_keys(Perangkat::DOKUMEN))],
        ], ['dokumen.required' => 'Pilih minimal satu dokumen.', 'tp.required' => 'Tujuan pembelajaran wajib diisi.', 'topik.required' => 'Topik wajib diisi.']);

        $mapel = Assignments::subjects($u)->firstWhere('id', (int) $d['subject']);
        $kelas = $mapel ? Assignments::classesForSubject($u, $mapel->id)->firstWhere('id', (int) $d['class']) : null;
        if (! $mapel || ! $kelas) {
            return back()->withInput()->with('error', 'Mapel atau kelas itu tidak Anda ampu.');
        }
        $set = SchoolSetting::current();
        $isian = [
            'mapel' => $mapel->name, 'kelas' => $kelas->name, 'fase' => Perangkat::fase($kelas),
            'konsentrasi' => $d['konsentrasi'] ?? '', 'guru' => $u->nama, 'nip' => $d['nip'] ?? '',
            'sekolah' => $set->school_name ?: config('absensi.school_name'), 'kepsek' => $set->kepsek_nama ?: config('absensi.surat.kepsek'),
            'kepsek_nip' => ProfilPusat::nilai('kepsek_nip') ?: config('absensi.surat.kepsek_nuptk'),
            'tahun' => $set->tahun_ajaran, 'semester' => $set->semester,
            'topik' => $d['topik'], 'tp' => $d['tp'], 'elemen' => $d['elemen'] ?? '',
            'alokasi' => $d['alokasi'], 'pertemuan' => (int) $d['pertemuan'], 'model' => $d['model'],
            'metode' => array_values($d['metode'] ?? []), 'dimensi' => array_values($d['dimensi'] ?? []),
            'lingkungan' => array_values($d['lingkungan'] ?? []), 'media' => array_values($d['media'] ?? []),
            'kesiapan' => $d['kesiapan'] ?? '', 'sarana' => $d['sarana'] ?? '', 'catatan' => $d['catatan'] ?? '',
        ];
        $dokumen = array_values(array_intersect(array_keys(Perangkat::DOKUMEN), $d['dokumen']));
        $p = PerangkatAjar::create([
            'user_id' => $u->id, 'subject_id' => $mapel->id, 'class_id' => $kelas->id, 'topik' => $d['topik'], 'isian' => $isian,
            'hasil' => array_combine($dokumen, array_map(fn ($k) => Perangkat::templat($isian, $k), $dokumen)),
        ]);
        Audit::log('Buat Perangkat Ajar', 'Perangkat Ajar', $u->nama, "{$mapel->name} · {$kelas->name} · {$d['topik']}");

        $pakaiAi = $request->boolean('pakai_ai') && PerangkatAi::tersedia();

        return redirect()->route('perangkat.show', array_filter(['perangkat' => $p, 'isi_ai' => $pakaiAi ? 1 : null]))
            ->with('success', $pakaiAi ? 'Draf dibuat. AI sedang mengisi dokumen satu per satu…' : 'Draf perangkat ajar dibuat dari templat. Lengkapi bagian bertanda [ … ] lewat tombol Ubah.');
    }

    public function show(Request $request, PerangkatAjar $perangkat)
    {
        $this->bolehLihat($perangkat);
        $ada = array_keys($perangkat->hasil);
        $dok = in_array($request->query('dok'), $ada, true) ? $request->query('dok') : ($ada[0] ?? 'modul');

        return view('perangkat.show', ['p' => $perangkat, 'dok' => $dok, 'ada' => $ada, 'ai' => PerangkatAi::tersedia(),
            'bolehUbah' => $perangkat->user_id === $this->me()->id || $this->me()->isAdmin(), 'isiAi' => $request->boolean('isi_ai')]);
    }

    public function edit(PerangkatAjar $perangkat, string $dok)
    {
        $this->bolehUbah($perangkat);
        $this->dok($dok);

        return view('perangkat.ubah', ['p' => $perangkat, 'dok' => $dok,
            'isi' => Perangkat::rapikan($perangkat->hasil[$dok] ?? Perangkat::templat($perangkat->isian, $dok), Perangkat::kerangka($dok))]);
    }

    public function update(Request $request, PerangkatAjar $perangkat, string $dok)
    {
        $this->bolehUbah($perangkat);
        $this->dok($dok);
        $hasil = $perangkat->hasil;
        $hasil[$dok] = Perangkat::rapikan($request->input('isi', []), Perangkat::kerangka($dok));
        $perangkat->update(['hasil' => $hasil]);

        return redirect()->route('perangkat.show', ['perangkat' => $perangkat, 'dok' => $dok])->with('success', Perangkat::DOKUMEN[$dok].' tersimpan.');
    }

    /** Isi satu dokumen dengan AI. Dipanggil berurutan oleh halaman (JSON), atau lewat tombol biasa tanpa JavaScript. */
    public function ai(Request $request, PerangkatAjar $perangkat, string $dok)
    {
        $this->bolehUbah($perangkat);
        $this->dok($dok);
        try {
            $isi = PerangkatAi::isi($perangkat->isian, $dok);
        } catch (RuntimeException $e) {
            return $request->expectsJson() ? response()->json(['ok' => false, 'pesan' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }
        $hasil = $perangkat->hasil;
        $hasil[$dok] = $isi;
        $sumber = array_unique(array_filter(array_merge(explode(',', $perangkat->sumber), [$dok])));
        $perangkat->update(['hasil' => $hasil, 'sumber' => implode(',', $sumber)]);

        return $request->expectsJson() ? response()->json(['ok' => true])
            : redirect()->route('perangkat.show', ['perangkat' => $perangkat, 'dok' => $dok])->with('success', Perangkat::DOKUMEN[$dok].' diisi oleh AI. Periksa dan sesuaikan sebelum dipakai.');
    }

    /** Unduh sebagai dokumen Word (HTML yang dibuka Microsoft Word / LibreOffice). */
    public function unduh(PerangkatAjar $perangkat, string $dok)
    {
        $this->bolehLihat($perangkat);
        abort_unless(isset($perangkat->hasil[$this->dok($dok)]), 404);
        $nama = preg_replace('/[^A-Za-z0-9]+/', '-', Perangkat::DOKUMEN[$dok].' '.$perangkat->isian['mapel'].' '.$perangkat->isian['kelas'].' '.$perangkat->topik);

        return response(view('perangkat.word', ['p' => $perangkat, 'dok' => $dok])->render(), 200, [
            'Content-Type' => 'application/msword; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.trim(mb_substr($nama, 0, 120), '-').'.doc"',
        ]);
    }

    public function destroy(PerangkatAjar $perangkat)
    {
        $this->bolehUbah($perangkat);
        $perangkat->delete();
        Audit::log('Hapus Perangkat Ajar', 'Perangkat Ajar', $this->me()->nama, $perangkat->topik);

        return redirect()->route('perangkat')->with('success', 'Perangkat ajar dihapus.');
    }
}
