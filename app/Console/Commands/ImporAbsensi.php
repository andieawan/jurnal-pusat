<?php

namespace App\Console\Commands;

use App\Services\PindahData;
use Illuminate\Console\Command;

class ImporAbsensi extends Command
{
    protected $signature = 'jurnal:impor-absensi {berkas : Berkas .zip hasil absensi:ekspor-jurnal} {--terapkan : Benar-benar simpan (tanpa ini hanya simulasi)}';

    protected $description = 'Jalankan di server Jurnal: gabungkan data dari Absensi. Data yang sudah ada di Jurnal tidak ditimpa; aman diulang.';

    public function handle(): int
    {
        $berkas = (string) $this->argument('berkas');
        if (! is_file($berkas)) {
            $this->error("Berkas $berkas tidak ditemukan.");

            return self::FAILURE;
        }
        $terapkan = (bool) $this->option('terapkan');
        try {
            $h = PindahData::impor($berkas, $terapkan);
        } catch (\Throwable $e) {
            $this->error('Gagal, tidak ada yang diubah: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Data', 'Baru', 'Dilengkapi', 'Sudah ada / dilewati'],
            collect($h)->map(fn ($n, $t) => [$t, $n['baru'], $n['ubah'], $n['lewat']])->values()->all());
        if ($terapkan) {
            $this->info('Data sudah digabung ke Jurnal.');
        } else {
            $this->warn('SIMULASI — belum ada yang disimpan. Jika angkanya wajar, ulangi dengan --terapkan');
        }
        $this->line('Catatan: "dilewati" juga mencakup data yang siswa/kelas/mapelnya tidak ditemukan. Jalankan Sinkron Pusat dulu agar semuanya cocok.');

        return self::SUCCESS;
    }
}
