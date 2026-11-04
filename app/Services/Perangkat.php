<?php

namespace App\Services;

use App\Models\SchoolClass;

/**
 * Perangkat ajar (Kurikulum Merdeka, pembelajaran mendalam): pilihan isian, kerangka 5 dokumen, dan draf templat
 * tanpa AI. Isi dokumen disimpan sebagai data terstruktur (bukan HTML) lalu ditata oleh tampilan aplikasi, sehingga
 * hasil AI dan hasil templat tampil seragam, aman ditampilkan, dan mudah diubah guru.
 */
final class Perangkat
{
    public const DOKUMEN = [
        'modul' => 'Modul Ajar',
        'lkpd' => 'LKPD',
        'asesmen' => 'Asesmen & Rubrik',
        'bacaan' => 'Bahan Bacaan',
        'tindak' => 'Tindak Lanjut',
    ];

    /** Model pembelajaran => tahapan (sintaks) yang dipakai untuk menyusun kegiatan inti. */
    public const MODEL = [
        'Project Based Learning (PjBL)' => ['Penentuan pertanyaan mendasar', 'Mendesain perencanaan proyek', 'Menyusun jadwal', 'Memantau kemajuan proyek', 'Menguji hasil', 'Evaluasi pengalaman'],
        'Problem Based Learning (PBL)' => ['Orientasi pada masalah', 'Mengorganisasi peserta didik', 'Membimbing penyelidikan', 'Mengembangkan & menyajikan hasil', 'Menganalisis & mengevaluasi proses'],
        'Discovery Learning' => ['Pemberian rangsangan', 'Identifikasi masalah', 'Pengumpulan data', 'Pengolahan data', 'Pembuktian', 'Menarik kesimpulan'],
        'Inquiry Learning' => ['Orientasi', 'Merumuskan masalah', 'Merumuskan hipotesis', 'Mengumpulkan data', 'Menguji hipotesis', 'Merumuskan kesimpulan'],
        'Teaching Factory (TeFa)' => ['Menerima pesanan/order', 'Menganalisis pesanan', 'Merencanakan produksi', 'Melaksanakan produksi', 'Quality control', 'Penyerahan & evaluasi'],
        'Cooperative Learning' => ['Menyampaikan tujuan', 'Menyajikan informasi', 'Mengorganisasi kelompok', 'Membimbing kerja kelompok', 'Evaluasi', 'Penghargaan'],
        'Design Thinking' => ['Empati', 'Definisi masalah', 'Ide', 'Purwarupa', 'Uji coba'],
        'Pembelajaran Langsung' => ['Orientasi', 'Demonstrasi', 'Latihan terbimbing', 'Umpan balik', 'Latihan mandiri'],
    ];

    public const METODE = ['Ceramah interaktif', 'Demonstrasi', 'Diskusi kelompok', 'Tanya jawab', 'Simulasi', 'Praktikum', 'Observasi', 'Studi kasus', 'Presentasi', 'Bermain peran', 'Proyek', 'Tutor sebaya'];

    /** 8 dimensi profil lulusan. */
    public const DIMENSI = ['Keimanan dan ketakwaan terhadap Tuhan YME', 'Kewargaan', 'Penalaran kritis', 'Kreativitas', 'Kolaborasi', 'Kemandirian', 'Kesehatan', 'Komunikasi'];

    public const LINGKUNGAN = ['Ruang kelas', 'Laboratorium / bengkel', 'Ruang virtual (LMS/Meet)', 'Dunia usaha & industri', 'Teaching Factory', 'Perpustakaan / lapangan'];

    public const MEDIA = ['Slide presentasi', 'Video pembelajaran', 'Internet & sumber web', 'LKPD digital', 'LMS (Google Classroom/Moodle)', 'Kuis daring', 'Simulator', 'Perangkat lunak kejuruan', 'Alat & bahan praktik'];

    /** Tingkat dari nama kelas: "X RPL 1" → X, "XII TKJ 2" → XII. */
    public static function tingkat(?SchoolClass $k): string
    {
        $t = strtoupper((string) strtok(trim((string) $k?->name), ' '));

        return ['10' => 'X', '11' => 'XI', '12' => 'XII'][$t] ?? (in_array($t, ['X', 'XI', 'XII', 'VII', 'VIII', 'IX'], true) ? $t : '');
    }

    /** Fase dari tingkat (atau kelas): X → E, XI/XII → F. */
    public static function fase(SchoolClass|string|null $k): string
    {
        $t = is_string($k) ? $k : self::tingkat($k);

        return match ($t) {
            'X' => 'E',
            'XI', 'XII' => 'F',
            'VII', 'VIII', 'IX' => 'D',
            default => '',
        };
    }

    /** Kerangka kosong satu dokumen: menentukan kunci dan jenis isian (teks, daftar teks, atau daftar baris). */
    public static function kerangka(string $dok): array
    {
        return match ($dok) {
            'modul' => [
                'tujuan' => [''],
                'pemahaman_bermakna' => '',
                'pertanyaan_pemantik' => [''],
                'pertemuan' => [['judul' => '', 'pendahuluan' => [''], 'memahami' => [''], 'mengaplikasi' => [''], 'merefleksi' => [''], 'penutup' => ['']]],
                'asesmen' => '',
            ],
            'lkpd' => ['judul' => '', 'petunjuk' => [''], 'aktivitas' => [['judul' => '', 'langkah' => ['']]], 'refleksi' => ['']],
            'asesmen' => [
                'diagnostik' => [['pertanyaan' => '', 'tindak_lanjut' => '']],
                'formatif' => [['indikator' => '', 'dimensi' => '']],
                'rubrik' => [['kriteria' => '', 'baru_berkembang' => '', 'layak' => '', 'cakap' => '', 'mahir' => '']],
            ],
            'bacaan' => ['ringkasan' => '', 'bagian' => [['judul' => '', 'isi' => '']], 'glosarium' => [['istilah' => '', 'arti' => '']], 'pustaka' => ['']],
            'tindak' => ['remedial' => [['sasaran' => '', 'kegiatan' => '']], 'pengayaan' => [['fokus' => '', 'kegiatan' => '']]],
        };
    }

    /**
     * Samakan bentuk isian dengan kerangka: kunci asing dibuang, yang hilang diisi kosong, teks dirapikan.
     * Dipakai untuk jawaban AI maupun isian formulir "Ubah".
     */
    public static function rapikan(mixed $isi, mixed $acuan): mixed
    {
        if (is_string($acuan)) {
            return is_scalar($isi) ? mb_substr(trim((string) $isi), 0, 4000) : '';
        }
        if (array_is_list($acuan)) {
            $contoh = $acuan[0];
            if (is_string($isi) && is_string($contoh)) { // daftar dari textarea: satu baris satu butir
                $isi = preg_split('/\R/', $isi);
            }
            $hasil = [];
            foreach (is_array($isi) ? array_values($isi) : [] as $v) {
                $v = self::rapikan($v, $contoh);
                if ($v !== '' && $v !== [] && ! (is_array($v) && ! array_filter($v, fn ($x) => $x !== '' && $x !== []))) {
                    $hasil[] = $v;
                }
            }

            return array_slice($hasil, 0, 40);
        }
        $hasil = [];
        foreach ($acuan as $k => $contoh) {
            $hasil[$k] = self::rapikan(is_array($isi) ? ($isi[$k] ?? null) : null, $contoh);
        }

        return $hasil;
    }

    /** Dokumen kosong untuk diisi manual: hanya kerangka, tujuan, dan judul pertemuan yang terisi dari formulir. */
    public static function kosong(array $in, string $dok): array
    {
        $isi = self::rapikan([], self::kerangka($dok));
        if ($dok === 'modul') {
            $isi['tujuan'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $in['tp']))));
            $isi['pertemuan'] = array_map(fn ($ke) => ['judul' => 'Pertemuan '.$ke.' ('.$in['alokasi'].')'] + self::rapikan([], self::kerangka('modul')['pertemuan'][0]),
                range(1, max(1, (int) $in['pertemuan'])));
        } elseif ($dok === 'lkpd') {
            $isi['judul'] = 'LKPD: '.$in['topik'];
        }

        return $isi;
    }

    /** Draf tanpa AI: kerangka terisi dari isian guru, bagian yang perlu dilengkapi ditandai [...]. */
    public static function templat(array $in, string $dok): array
    {
        $topik = $in['topik'];
        $tp = array_values(array_filter(preg_split('/\R/', (string) $in['tp'])));
        $sintaks = self::MODEL[$in['model']] ?? self::MODEL['Pembelajaran Langsung'];
        $metode = $in['metode'] ? implode(', ', $in['metode']) : 'diskusi dan tanya jawab';
        $dimensi = $in['dimensi'] ?: ['Penalaran kritis', 'Kolaborasi'];

        return match ($dok) {
            'modul' => [
                'tujuan' => $tp ?: ["Peserta didik mampu memahami dan menerapkan $topik."],
                'pemahaman_bermakna' => "Mempelajari $topik membantu peserta didik [tuliskan manfaat nyata dalam kehidupan / dunia kerja].",
                'pertanyaan_pemantik' => ["Apa yang sudah kalian ketahui tentang $topik?", "Di mana kalian pernah menjumpai $topik dalam kehidupan sehari-hari?"],
                'pertemuan' => array_map(fn ($ke) => [
                    'judul' => 'Pertemuan '.$ke.' ('.$in['alokasi'].')',
                    'pendahuluan' => ['Salam, doa, dan memeriksa kehadiran (berkesadaran).', 'Apersepsi: mengaitkan '.$topik.' dengan pengalaman peserta didik (bermakna).', 'Menyampaikan tujuan pembelajaran dan rencana kegiatan.'],
                    'memahami' => ['Tahap "'.$sintaks[0].'": [uraikan kegiatan pengantar materi '.$topik.'].', 'Peserta didik menggali informasi melalui '.$metode.'.'],
                    'mengaplikasi' => array_map(fn ($s) => 'Tahap "'.$s.'": [uraikan kegiatan peserta didik].', array_slice($sintaks, 1, max(1, count($sintaks) - 2))),
                    'merefleksi' => ['Tahap "'.end($sintaks).'": peserta didik mempresentasikan dan menilai hasil kerja.', 'Refleksi: apa yang dipelajari, apa yang masih sulit, dan langkah selanjutnya.'],
                    'penutup' => ['Menyimpulkan pembelajaran bersama.', 'Menyampaikan rencana pertemuan berikutnya.', 'Doa dan salam.'],
                ], range(1, max(1, min(6, (int) $in['pertemuan'])))),
                'asesmen' => 'Diagnostik di awal (pertanyaan pemantik), formatif selama kegiatan (observasi dan LKPD), sumatif berupa [produk / tes] dengan rubrik terlampir.',
            ],
            'lkpd' => [
                'judul' => 'LKPD: '.$topik,
                'petunjuk' => ['Bacalah tujuan dan langkah kegiatan dengan cermat.', 'Kerjakan bersama kelompok, bagi tugas secara adil.', 'Tuliskan hasil pada kolom yang tersedia.'],
                'aktivitas' => [
                    ['judul' => 'Aktivitas 1: Memahami', 'langkah' => ['Amati [kasus / gambar / video] tentang '.$topik.'.', 'Tuliskan tiga hal penting yang kalian temukan.']],
                    ['judul' => 'Aktivitas 2: Mengaplikasi', 'langkah' => ['[Uraikan tugas praktik / proyek].', 'Dokumentasikan proses dan hasil kerja.']],
                    ['judul' => 'Aktivitas 3: Merefleksi', 'langkah' => ['Presentasikan hasil kelompok.', 'Berikan dan terima umpan balik dari kelompok lain.']],
                ],
                'refleksi' => ['Apa yang paling kalian pahami hari ini?', 'Bagian mana yang masih sulit?', 'Bagaimana kalian akan menggunakan materi ini?'],
            ],
            'asesmen' => [
                'diagnostik' => [
                    ['pertanyaan' => "Apa yang kalian ketahui tentang $topik?", 'tindak_lanjut' => 'Belum paham: penguatan konsep dasar; sudah paham: tantangan tambahan.'],
                    ['pertanyaan' => 'Pengalaman apa yang pernah kalian alami terkait materi ini?', 'tindak_lanjut' => 'Dijadikan contoh nyata saat apersepsi.'],
                ],
                'formatif' => array_map(fn ($d) => ['indikator' => '[Perilaku yang diamati terkait '.mb_strtolower($d).']', 'dimensi' => $d], $dimensi),
                'rubrik' => [
                    ['kriteria' => 'Pemahaman konsep '.$topik, 'baru_berkembang' => 'Belum mampu menjelaskan konsep.', 'layak' => 'Menjelaskan sebagian konsep dengan bantuan.', 'cakap' => 'Menjelaskan konsep dengan benar.', 'mahir' => 'Menjelaskan dan mengaitkan konsep dengan contoh baru.'],
                    ['kriteria' => 'Kualitas hasil kerja / produk', 'baru_berkembang' => 'Hasil belum memenuhi kriteria.', 'layak' => 'Memenuhi sebagian kriteria.', 'cakap' => 'Memenuhi semua kriteria.', 'mahir' => 'Melampaui kriteria dan kreatif.'],
                    ['kriteria' => 'Kerja sama & komunikasi', 'baru_berkembang' => 'Jarang terlibat.', 'layak' => 'Terlibat bila diminta.', 'cakap' => 'Aktif berkontribusi.', 'mahir' => 'Aktif dan menggerakkan anggota lain.'],
                ],
            ],
            'bacaan' => [
                'ringkasan' => "[Tuliskan pengantar singkat tentang $topik: pengertian dan mengapa penting dipelajari.]",
                'bagian' => [['judul' => 'Konsep dasar '.$topik, 'isi' => '[Uraikan konsep utama.]'], ['judul' => 'Penerapan', 'isi' => '[Contoh penerapan di kehidupan / dunia kerja.]']],
                'glosarium' => [['istilah' => '[istilah]', 'arti' => '[arti dalam konteks materi]']],
                'pustaka' => ['[Buku siswa / modul / sumber daring yang dipakai]'],
            ],
            'tindak' => [
                'remedial' => [['sasaran' => 'Peserta didik yang belum mencapai tujuan pembelajaran', 'kegiatan' => 'Bimbingan ulang konsep '.$topik.' dengan contoh sederhana, lalu tugas perbaikan.']],
                'pengayaan' => [['fokus' => 'Peserta didik yang sudah mencapai tujuan', 'kegiatan' => 'Tantangan lanjutan / proyek mini terkait '.$topik.', menjadi tutor sebaya.']],
            ],
        };
    }
}
