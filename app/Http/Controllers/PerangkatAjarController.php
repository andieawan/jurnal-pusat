<?php

namespace App\Http\Controllers;

use App\Models\PerangkatAjar;
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
            'set' => $set, 'mgmp' => $mgmp->isNotEmpty(), 'semua' => $semua,
            'draf' => $mgmp->isEmpty() ? collect() : $muat(PerangkatAjar::where('jenis', 'mgmp')->whereIn('subject_id', $mgmp->pluck('id')))->limit(60)->get(),
            'siap' => $muat(PerangkatAjar::where(['jenis' => 'mgmp', 'status' => 'siap', 'tahun' => (string) $set->tahun_ajaran, 'semester' => (string) $set->semester])
                ->whereIn('subject_id', $mapelSaya))->get(),
            'diambil' => PerangkatAjar::where(['jenis' => 'guru', 'user_id' => $u->id])->whereNotNull('dari_id')->pluck('dari_id')->all(),
            'milik' => $muat(PerangkatAjar::where('jenis', 'guru')->when(! $semua, fn ($q) => $q->where('user_id', $u->id)))->paginate(20),
        ]);
    }

    // ------------------------------------------------------------ draf MGMP

    public function create(Request $request)
    {
        $u = $this->me();
        $mapel = $this->mapelMgmp($u);
        abort_if($mapel->isEmpty(), 403, 'Hanya tim MGMP yang menyusun draf perangkat ajar.');
        $dari = $request->integer('dari') ? PerangkatAjar::find($request->integer('dari')) : null;
        abort_if($dari && ! $this->bisaLihat($dari), 403);
        $subjectId = (int) ($request->input('subject') ?: ($dari->subject_id ?? $mapel->first()->id));
        $tingkat = Assignments::classesForSubject($u, $subjectId)->map(fn ($k) => Perangkat::tingkat($k))->filter()->unique()->values();

        return view('perangkat.form', [
            'subjects' => $mapel, 'subjectId' => $subjectId, 'isian' => $dari?->isian ?? [],
            'tingkat' => $dari->tingkat ?? ($tingkat->first() ?: 'X'), 'ai' => PerangkatAi::tersedia($u), 'set' => SchoolSetting::current(),
        ]);
    }

    public function store(Request $request)
    {
        $u = $this->me();
        $mapelMgmp = $this->mapelMgmp($u);
        abort_if($mapelMgmp->isEmpty(), 403);
        $d = $request->validate([
            'subject' => 'required|integer', 'tingkat' => ['required', Rule::in(['X', 'XI', 'XII'])],
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
            'bahan.*.mimes' => 'Bahan ajar harus PDF, DOCX, atau TXT.', 'bahan.*.max' => 'Ukuran tiap bahan ajar maksimal 8 MB.', 'bahan.max' => 'Maksimal 3 berkas bahan ajar.']);

        $mapel = $mapelMgmp->firstWhere('id', (int) $d['subject']);
        if (! $mapel) {
            return back()->withInput()->with('error', 'Anda tidak termasuk tim MGMP mapel itu.');
        }
        $set = SchoolSetting::current();
        $isian = [
            'mapel' => $mapel->name, 'kelas' => 'Kelas '.$d['tingkat'], 'fase' => Perangkat::fase($d['tingkat']),
            'konsentrasi' => $d['konsentrasi'] ?? '', 'guru' => 'Tim MGMP '.$mapel->name, 'nip' => '',
            'sekolah' => $set->school_name ?: config('absensi.school_name'), 'kepsek' => $set->kepsek_nama ?: config('absensi.surat.kepsek'),
            'kepsek_nip' => ProfilPusat::nilai('kepsek_nip') ?: config('absensi.surat.kepsek_nuptk'),
            'tahun' => $set->tahun_ajaran, 'semester' => $set->semester,
            'topik' => $d['topik'], 'tp' => $d['tp'], 'elemen' => $d['elemen'] ?? '',
            'alokasi' => $d['alokasi'], 'pertemuan' => (int) $d['pertemuan'], 'model' => $d['model'],
            'metode' => array_values($d['metode'] ?? []), 'dimensi' => array_values($d['dimensi'] ?? []),
            'lingkungan' => array_values($d['lingkungan'] ?? []), 'media' => array_values($d['media'] ?? []),
            'kesiapan' => $d['kesiapan'] ?? '', 'sarana' => $d['sarana'] ?? '', 'catatan' => $d['catatan'] ?? '', 'bahan' => [],
        ];
        $dokumen = array_values(array_intersect(array_keys(Perangkat::DOKUMEN), $d['dokumen']));
        $p = PerangkatAjar::create([
            'jenis' => 'mgmp', 'user_id' => $u->id, 'subject_id' => $mapel->id, 'tingkat' => $d['tingkat'],
            'tahun' => (string) $set->tahun_ajaran, 'semester' => (string) $set->semester, 'topik' => $d['topik'], 'isian' => $isian,
            'hasil' => array_combine($dokumen, array_map(fn ($k) => Perangkat::templat($isian, $k), $dokumen)),
        ]);
        if ($request->hasFile('bahan')) {
            $isian['bahan'] = array_map(fn ($f) => BahanAjar::simpan($f, $p->id), $request->file('bahan'));
            $p->update(['isian' => $isian]);
        }
        Audit::log('Buat Draf MGMP', 'Perangkat Ajar', $u->nama, "{$mapel->name} · kelas {$d['tingkat']} · {$d['topik']}");
        $pakaiAi = $request->boolean('pakai_ai') && PerangkatAi::tersedia($u);

        return redirect()->route('perangkat.show', array_filter(['perangkat' => $p, 'isi_ai' => $pakaiAi ? 1 : null]))
            ->with('success', $pakaiAi ? 'Draf dibuat. AI sedang mengisi dokumen satu per satu…' : 'Draf MGMP dibuat dari templat. Lengkapi bagian bertanda [ … ], lalu tandai siap dipakai guru.');
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
        $nipLama = PerangkatAjar::where(['jenis' => 'guru', 'user_id' => $u->id])->orderByDesc('id')->first()?->isian['nip'] ?? '';
        $isian = array_merge($perangkat->isian, ['guru' => $u->nama, 'nip' => $nipLama, 'kelas' => $kelas->name, 'fase' => Perangkat::fase($kelas) ?: $perangkat->isian['fase'],
            'konsentrasi' => $perangkat->isian['konsentrasi'] ?: (string) $kelas->jurusan, 'bahan' => []]);
        $p = PerangkatAjar::create([
            'jenis' => 'guru', 'user_id' => $u->id, 'subject_id' => $perangkat->subject_id, 'class_id' => $kelas->id,
            'tingkat' => Perangkat::tingkat($kelas), 'tahun' => $perangkat->tahun, 'semester' => $perangkat->semester,
            'topik' => $perangkat->topik, 'isian' => $isian, 'hasil' => $perangkat->hasil, 'sumber' => $perangkat->sumber, 'dari_id' => $perangkat->id,
        ]);
        Audit::log('Ambil Draf MGMP', 'Perangkat Ajar', $u->nama, "{$perangkat->topik} → {$kelas->name}");

        return redirect()->route('perangkat.show', $p)->with('success', 'Draf MGMP diambil untuk '.$kelas->name.'. Sesuaikan dengan kelas Anda lewat Ubah isi.');
    }

    public function identitas(Request $request, PerangkatAjar $perangkat)
    {
        abort_unless($perangkat->jenis === 'guru' && $this->bisaUbah($perangkat), 403);
        $d = $request->validate(['nip' => 'nullable|string|max:40', 'konsentrasi' => 'nullable|string|max:150', 'alokasi' => 'required|string|max:60']);
        $perangkat->update(['isian' => array_merge($perangkat->isian, ['nip' => $d['nip'] ?? '', 'konsentrasi' => $d['konsentrasi'] ?? '', 'alokasi' => $d['alokasi']])]);

        return back()->with('success', 'Identitas diperbarui.');
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
            'ai' => $perangkat->jenis === 'mgmp' && $bisaUbah && PerangkatAi::tersedia($u), 'isiAi' => $request->boolean('isi_ai'),
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
        $perangkat->update(['hasil' => $hasil]);

        return redirect()->route('perangkat.show', ['perangkat' => $perangkat, 'dok' => $dok])->with('success', Perangkat::DOKUMEN[$dok].' tersimpan.');
    }

    /** Isi satu dokumen draf MGMP dengan AI (dipanggil berurutan oleh halaman sebagai JSON, atau lewat tombol biasa). */
    public function ai(Request $request, PerangkatAjar $perangkat, string $dok)
    {
        abort_unless($perangkat->jenis === 'mgmp' && $this->bisaUbah($perangkat), 403);
        $this->dok($dok);
        try {
            $isi = PerangkatAi::isi($perangkat->isian, $dok, $this->me());
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
        abort_unless($perangkat->jenis === 'mgmp' && $this->bisaUbah($perangkat), 403);
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
        abort_unless($perangkat->jenis === 'mgmp' && $this->bisaUbah($perangkat), 403);
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
        abort_if($this->mapelMgmp($this->me())->isEmpty(), 403);

        return view('perangkat.kunci', ['sekolah' => PerangkatAi::kunciSekolah(), 'punya' => (string) $this->me()->ai_key !== '',
            'akhir' => substr((string) $this->me()->ai_key, -4)]);
    }

    public function kunciSimpan(Request $request)
    {
        abort_if($this->mapelMgmp($this->me())->isEmpty(), 403);
        if ($request->boolean('hapus')) {
            $this->me()->forceFill(['ai_key' => null])->save();

            return redirect()->route('perangkat.kunci')->with('success', 'Kunci API Anda dihapus.');
        }
        $d = $request->validate(['ai_key' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-]{20,}$/']], ['ai_key.regex' => 'Format kunci tidak dikenali. Salin kunci dari Google AI Studio apa adanya.']);
        $this->me()->forceFill(['ai_key' => $d['ai_key']])->save();
        Audit::log('Simpan Kunci AI', 'Perangkat Ajar', $this->me()->nama, 'kunci pribadi …'.substr($d['ai_key'], -4));

        return redirect()->route('perangkat.kunci')->with('success', 'Kunci API tersimpan (terenkripsi). Kunci hanya dipakai untuk draf yang Anda susun.');
    }
}
