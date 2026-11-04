<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mengisi satu dokumen perangkat ajar dengan Google Gemini (dipanggil dari server; kunci API hanya di .env, tidak
 * pernah dikirim ke browser). Yang dikirim ke Gemini hanya isian pembelajaran (mapel, topik, TP, dst.) — tanpa
 * nama guru, nama siswa, atau data pribadi lain. Jawaban diminta berupa JSON lalu disamakan dengan kerangka.
 */
final class PerangkatAi
{
    public static function tersedia(): bool
    {
        return (string) config('absensi.ai.key') !== '';
    }

    private const PETUNJUK = [
        'modul' => 'Modul ajar: 3-5 tujuan pembelajaran operasional (kata kerja terukur), pemahaman bermakna, 2-4 pertanyaan pemantik, kegiatan TIAP pertemuan (pendahuluan; inti dibagi memahami, mengaplikasi, merefleksi mengikuti tahapan model; penutup) dengan langkah konkret yang bisa langsung dipraktikkan guru, dan ringkasan rencana asesmen.',
        'lkpd' => 'LKPD untuk kelompok: judul, petunjuk kerja, 2-4 aktivitas berurutan (memahami, mengaplikasi, merefleksi) dengan langkah yang jelas dan bisa dikerjakan peserta didik, serta 3-5 pertanyaan refleksi.',
        'asesmen' => 'Asesmen: 3-5 pertanyaan diagnostik beserta tindak lanjut pedagogis, 4-6 indikator observasi formatif yang dikaitkan dengan dimensi profil lulusan yang dipilih, dan rubrik sumatif 3-5 kriteria dengan 4 tingkat (baru berkembang, layak, cakap, mahir) yang deskriptornya berbeda jelas.',
        'bacaan' => 'Bahan bacaan untuk guru & peserta didik: ringkasan pengantar, 3-5 bagian materi yang akurat dan sesuai fase, glosarium 5-8 istilah, serta daftar pustaka yang nyata dan umum dipakai (jangan mengarang judul atau tautan; bila ragu tulis jenis sumbernya).',
        'tindak' => 'Program tindak lanjut: 2-3 kegiatan remedial (sasaran keterampilan dan bentuk bimbingan) dan 2-3 kegiatan pengayaan (fokus dan bentuk tantangan).',
    ];

    /** @return array isi dokumen, sudah disamakan dengan kerangka */
    public static function isi(array $in, string $dok): array
    {
        if (! self::tersedia()) {
            throw new RuntimeException('AI belum diaktifkan. Admin perlu mengisi GEMINI_API_KEY di .env.');
        }
        $kerangka = Perangkat::kerangka($dok);
        $prompt = implode("\n", [
            'Anda adalah guru SMK berpengalaman di Indonesia yang menyusun perangkat ajar Kurikulum Merdeka dengan pendekatan pembelajaran mendalam (berkesadaran, bermakna, menggembirakan; pengalaman belajar memahami-mengaplikasi-merefleksi).',
            'Tulis dalam bahasa Indonesia baku yang jelas. Sesuaikan dengan fase, kelas, konsentrasi keahlian, dan alokasi waktu. Jangan menulis identitas guru atau sekolah.',
            '',
            'Data pembelajaran:',
            '- Mata pelajaran: '.$in['mapel'],
            '- Konsentrasi keahlian: '.($in['konsentrasi'] ?: '-'),
            '- Fase / kelas: '.($in['fase'] ?: '-').' / '.$in['kelas'],
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
            '',
            'Tugas: '.self::PETUNJUK[$dok],
            $dok === 'modul' ? 'Buat tepat '.max(1, (int) $in['pertemuan']).' butir "pertemuan".' : '',
            'Jawab HANYA dengan JSON yang bentuknya persis seperti kerangka berikut (kunci sama; daftar boleh berisi lebih banyak butir; teks biasa tanpa markdown/HTML):',
            json_encode($kerangka, JSON_UNESCAPED_UNICODE),
        ]);

        $model = (string) config('absensi.ai.model', 'gemini-flash-latest');
        $res = Http::withHeaders(['x-goog-api-key' => (string) config('absensi.ai.key')])
            ->timeout((int) config('absensi.ai.timeout', 50))
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.6],
            ]);
        if (! $res->successful()) {
            $pesan = (string) ($res->json('error.message') ?? 'HTTP '.$res->status());
            throw new RuntimeException(match (true) {
                in_array($res->status(), [401, 403], true) => 'Kunci API Gemini ditolak. Periksa GEMINI_API_KEY.',
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

        return Perangkat::rapikan($data, $kerangka);
    }
}
