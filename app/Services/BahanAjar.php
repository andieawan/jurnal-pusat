<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Bahan ajar rujukan (PDF, DOCX, TXT) yang diunggah MGMP untuk draf perangkat ajar. Berkas disimpan privat; isinya
 * dikirim ke AI sebagai rujukan: PDF apa adanya (dibaca langsung oleh Gemini), DOCX/TXT sebagai teks.
 */
final class BahanAjar
{
    public const MAKS_BERKAS = 3;

    public const MAKS_KB = 8192; // 8 MB per berkas; PDF yang dikirim ke AI dibatasi total 14 MB

    public const JENIS = 'pdf,docx,txt';

    /** @return array{nama:string, path:string, mime:string, ukuran:int} */
    public static function simpan(UploadedFile $f, int $perangkatId): array
    {
        $ext = strtolower($f->getClientOriginalExtension());
        $path = $f->storeAs('perangkat/'.$perangkatId, Str::random(20).'.'.$ext, 'local');

        return ['nama' => mb_substr($f->getClientOriginalName(), 0, 150), 'path' => $path,
            'mime' => ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain'][$ext] ?? 'application/octet-stream',
            'ukuran' => (int) $f->getSize()];
    }

    public static function hapus(array $b): void
    {
        Storage::disk('local')->delete($b['path']);
    }

    /** Isi teks berkas DOCX/TXT (dipangkas); null untuk PDF (dikirim sebagai berkas). */
    public static function teks(array $b): ?string
    {
        $isi = Storage::disk('local')->path($b['path']);
        if (! is_file($isi) || $b['mime'] === 'application/pdf') {
            return null;
        }
        if (str_ends_with($b['path'], '.docx')) {
            $zip = new ZipArchive;
            if ($zip->open($isi) !== true) {
                return '';
            }
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
            $t = strip_tags(str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $xml));
        } else {
            $t = (string) file_get_contents($isi);
        }
        $t = html_entity_decode(preg_replace("/\n{3,}/", "\n\n", $t), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return mb_substr(mb_check_encoding($t, 'UTF-8') ? $t : mb_convert_encoding($t, 'UTF-8', 'Windows-1252'), 0, 60000);
    }

    /** Bagian permintaan Gemini untuk semua bahan: PDF sebagai inlineData, lainnya sebagai teks. */
    public static function bagianAi(array $bahan): array
    {
        $parts = [];
        $pdf = 0;
        foreach ($bahan as $b) {
            if ($b['mime'] === 'application/pdf') {
                $data = Storage::disk('local')->get($b['path']);
                if ($data !== null && ($pdf += strlen($data)) <= 14 * 1024 * 1024) { // batas permintaan Gemini ±20 MB setelah base64
                    $parts[] = ['text' => 'Bahan rujukan (PDF): '.$b['nama']];
                    $parts[] = ['inlineData' => ['mimeType' => 'application/pdf', 'data' => base64_encode($data)]];
                }
            } elseif (($t = self::teks($b)) !== null && trim($t) !== '') {
                $parts[] = ['text' => "Bahan rujukan ({$b['nama']}):\n".$t];
            }
        }

        return $parts;
    }
}
