<?php

namespace App\Services;

use App\Models\AiKunciMgmp;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mengisi satu dokumen perangkat ajar dengan Google Gemini (dipanggil dari server; kunci API tidak pernah dikirim ke
 * browser). Yang dikirim ke Gemini hanya isian pembelajaran (mapel, topik, TP, dst.) — tanpa
 * nama guru, nama siswa, atau data pribadi lain. Jawaban diminta berupa JSON lalu disamakan dengan kerangka.
 */
final class PerangkatAi
{
    /**
     * Kunci yang dipakai, berurutan: kunci sekolah (.env) → kunci bersama MGMP mapel itu → kunci pribadi penyusun.
     * @return array{0: string, 1: string} [sumber (sekolah|mgmp|pribadi|''), kunci]
     */
    public static function kunci(?User $u, ?int $subjectId = null): array
    {
        if ((string) config('absensi.ai.key') !== '') {
            return ['sekolah', (string) config('absensi.ai.key')];
        }
        $mgmp = $subjectId ? (string) AiKunciMgmp::where('subject_id', $subjectId)->first()?->kunci : '';
        if ($mgmp !== '') {
            return ['mgmp', $mgmp];
        }

        return (string) ($u?->ai_key ?? '') !== '' ? ['pribadi', (string) $u->ai_key] : ['', ''];
    }

    public static function kunciSekolah(): bool
    {
        return (string) config('absensi.ai.key') !== '';
    }

    public static function tersedia(?User $u, ?int $subjectId = null): bool
    {
        return self::kunci($u, $subjectId)[1] !== '';
    }

    /** Pemakaian AI satu mapel pada semester berjalan: [terpakai, kuota (0 = tanpa batas)]. */
    public static function pemakaian(int $subjectId): array
    {
        $set = SchoolSetting::current();
        $n = DB::table('ai_pemakaian')->where(['subject_id' => $subjectId, 'tahun' => (string) $set->tahun_ajaran, 'semester' => (string) $set->semester])
            ->whereIn('kunci', ['sekolah', 'mgmp'])->count();

        return [$n, max(0, (int) config('absensi.ai.kuota'))];
    }

    private static function catat(int $subjectId, ?User $u, string $dok, string $sumber): void
    {
        $set = SchoolSetting::current();
        DB::table('ai_pemakaian')->insert(['subject_id' => $subjectId, 'user_id' => (int) $u?->id, 'tahun' => (string) $set->tahun_ajaran,
            'semester' => (string) $set->semester, 'dok' => $dok, 'kunci' => $sumber, 'created_at' => now('UTC')->format('Y-m-d H:i:s')]);
    }

    private const PETUNJUK = [
        'modul' => 'Modul ajar: 3-5 tujuan pembelajaran operasional (kata kerja terukur), pemahaman bermakna, 2-4 pertanyaan pemantik, kegiatan TIAP pertemuan (pendahuluan; inti dibagi memahami, mengaplikasi, merefleksi mengikuti tahapan model; penutup) dengan langkah konkret yang bisa langsung dipraktikkan guru, dan ringkasan rencana asesmen.',
        'lkpd' => 'LKPD untuk kelompok: judul, petunjuk kerja, 2-4 aktivitas berurutan (memahami, mengaplikasi, merefleksi) dengan langkah yang jelas dan bisa dikerjakan peserta didik, serta 3-5 pertanyaan refleksi.',
        'asesmen' => 'Asesmen: 3-5 pertanyaan diagnostik beserta tindak lanjut pedagogis, 4-6 indikator observasi formatif yang dikaitkan dengan dimensi profil lulusan yang dipilih, dan rubrik sumatif 3-5 kriteria dengan 4 tingkat (baru berkembang, layak, cakap, mahir) yang deskriptornya berbeda jelas.',
        'bacaan' => 'Bahan bacaan untuk guru & peserta didik: ringkasan pengantar, 3-5 bagian materi yang akurat dan sesuai fase, glosarium 5-8 istilah, serta daftar pustaka yang nyata dan umum dipakai (jangan mengarang judul atau tautan; bila ragu tulis jenis sumbernya).',
        'tindak' => 'Program tindak lanjut: 2-3 kegiatan remedial (sasaran keterampilan dan bentuk bimbingan) dan 2-3 kegiatan pengayaan (fokus dan bentuk tantangan).',
    ];

    /** @return array isi dokumen, sudah disamakan dengan kerangka */
    public static function isi(array $in, string $dok, ?User $u = null, int $subjectId = 0): array
    {
        [$sumber, $kunci] = self::kunci($u, $subjectId ?: null);
        if ($kunci === '') {
            throw new RuntimeException('Belum ada kunci API Gemini. Isi kunci di Perangkat Ajar → Kunci AI (pribadi atau bersama MGMP), atau minta admin mengisi GEMINI_API_KEY.');
        }
        [$terpakai, $kuota] = $subjectId ? self::pemakaian($subjectId) : [0, 0];
        if ($sumber !== 'pribadi' && $kuota > 0 && $terpakai >= $kuota) {
            throw new RuntimeException("Kuota AI mapel ini semester ini sudah habis ($terpakai dari $kuota dokumen). Lanjutkan dengan mengisi manual, atau pakai kunci pribadi.");
        }
        $bahan = BahanAjar::bagianAi((array) ($in['bahan'] ?? []));
        $kerangka = Perangkat::kerangka($dok);
        $prompt = implode("\n", [
            'Anda adalah guru SMK berpengalaman di Indonesia yang menyusun perangkat ajar Kurikulum Merdeka dengan pendekatan pembelajaran mendalam (berkesadaran, bermakna, menggembirakan; pengalaman belajar memahami-mengaplikasi-merefleksi).',
            'Tulis dalam bahasa Indonesia baku yang jelas. Sesuaikan dengan fase, kelas, konsentrasi keahlian, dan alokasi waktu. Jangan menulis identitas guru atau sekolah.',
            '',
            'Data pembelajaran:',
            '- Mata pelajaran: '.$in['mapel'],
            '- Konsentrasi keahlian: '.($in['konsentrasi'] ?: '-'),
            '- Fase / tingkat: '.($in['fase'] ?: '-').' / '.$in['kelas'],
            '- Topik / materi pokok: '.$in['topik'],
            '- Elemen CP: '.($in['elemen'] ?: '-'),
            '- Tujuan pembelajaran (rancangan guru): '.preg_replace('/\R+/', '; ', (string) $in['tp']),
            '- Alokasi waktu: '.$in['alokasi'].' x '.$in['pertemuan'].' pertemuan',
            '- Model pembelajaran: '.$in['model'].' (tahapan: '.implode(', ', Perangkat::MODEL[$in['model']] ?? []).')',
            '- Metode: '.(implode(', ', $in['metode']) ?: '-'),
            '- Dimensi profil lulusan: '.(implode(', ', $in['dimensi']) ?: '-'),
            '- Lingkungan belajar: '.(implode(', ', $in['lingkungan']) ?: '-'),
            '- Media & alat: '.(implode(', ', $in['media']) ?: '-'),
            '- Kesiapan peserta didik: '.($in['kesiapan'] ?: '-'),
            '- Sarana sekolah: '.($in['sarana'] ?: '-'),
            $in['catatan'] ? '- Instruksi tambahan guru: '.$in['catatan'] : '',
            $bahan ? 'Bahan rujukan dari guru terlampir. Utamakan isi, istilah, dan urutan materi dari bahan itu; jangan menyalin panjang kata demi kata.' : '',
            '',
            'Tugas: '.self::PETUNJUK[$dok],
            $dok === 'modul' ? 'Buat tepat '.max(1, (int) $in['pertemuan']).' butir "pertemuan".' : '',
            'Jawab HANYA dengan JSON yang bentuknya persis seperti kerangka berikut (kunci sama; daftar boleh berisi lebih banyak butir; teks biasa tanpa markdown/HTML):',
            json_encode($kerangka, JSON_UNESCAPED_UNICODE),
        ]);

        $model = (string) config('absensi.ai.model', 'gemini-flash-latest');
        $res = Http::withHeaders(['x-goog-api-key' => $kunci])
            ->timeout((int) config('absensi.ai.timeout', 50))
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                'contents' => [['role' => 'user', 'parts' => array_merge($bahan, [['text' => $prompt]])]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.6],
            ]);
        if (! $res->successful()) {
            $pesan = (string) ($res->json('error.message') ?? 'HTTP '.$res->status());
            throw new RuntimeException(match (true) {
                in_array($res->status(), [401, 403], true) || str_contains($pesan, 'API key') => 'Kunci API Gemini ditolak. Periksa kunci yang dipakai.',
                $res->status() === 429 => 'Kuota Gemini habis atau terlalu banyak permintaan. Coba lagi beberapa menit lagi.',
                $res->status() === 404 => 'Model "'.$model.'" tidak ditemukan. Periksa GEMINI_MODEL.',
                default => 'Gemini gagal menjawab: '.mb_substr($pesan, 0, 200),
            });
        }
        $teks = collect($res->json('candidates.0.content.parts', []))->pluck('text')->implode('');
        $teks = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/', '', $teks);
        $data = json_decode((string) $teks, true);
        if (! is_array($data)) {
            throw new RuntimeException('Jawaban AI tidak bisa dibaca. Coba buat ulang.');
        }

        $subjectId && self::catat($subjectId, $u, $dok, $sumber);

        return Perangkat::rapikan($data, $kerangka);
    }
}
