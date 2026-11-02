<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Semua tampilan Blade harus terkompilasi menjadi PHP yang sah, termasuk halaman yang tidak tersentuh tes lain.
 * Menangkap misalnya "kata@endif" (direktif menempel huruf tidak dikenali Blade → @if tanpa penutup).
 */
class TampilanTest extends TestCase
{
    public function test_semua_tampilan_terkompilasi_sah(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'blade');
        $n = 0;
        foreach (File::allFiles(resource_path('views')) as $f) {
            if (! str_ends_with($f->getFilename(), '.blade.php')) {
                continue;
            }
            file_put_contents($tmp, Blade::compileString(file_get_contents($f->getPathname())));
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $keluaran, $kode);
            $this->assertSame(0, $kode, $f->getRelativePathname().': '.implode(' ', $keluaran));
            $keluaran = [];
            $n++;
        }
        @unlink($tmp);
        $this->assertGreaterThan(0, $n);
    }
}
