<?php

namespace App\Console\Commands;

use App\Services\PindahData;
use Illuminate\Console\Command;

class EksporJurnal extends Command
{
    protected $signature = 'absensi:ekspor-jurnal {berkas? : Lokasi berkas .zip (bawaan: storage/app/private/pindah/absensi-<tanggal>.zip)}';

    protected $description = 'Jalankan di server Absensi: kemas semua data (absensi, nilai, BK, TU, mutasi, tautan wali/papan, berkas unggahan) untuk dipindah ke Jurnal Guru.';

    public function handle(): int
    {
        $tujuan = $this->argument('berkas') ?: storage_path('app/private/pindah/absensi-'.now()->format('Ymd-His').'.zip');
        try {
            $h = PindahData::ekspor($tujuan);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Data', 'Jumlah'], collect($h['jumlah'])->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info('Selesai: '.$h['berkas']);
        $this->line('Salin berkas ini ke server Jurnal, lalu jalankan: php artisan jurnal:impor-absensi <berkas>');

        return self::SUCCESS;
    }
}
