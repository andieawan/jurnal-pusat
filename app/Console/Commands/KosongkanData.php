<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kosongkan data Absensi untuk memulai ulang bersama Pusat (mis. ganti data dummy).
 * DIHAPUS : kelas, siswa, mapel (kecuali "Bimbingan Konseling"), jurusan, pasangan mapel, presensi, nilai, catatan BK,
 *           surat & mutasi TU, absen pegawai, tautan ketua kelas/orang tua/papan kelas, akun selain admin, status sinkron.
 * DISIMPAN: akun admin/superadmin, pengaturan sekolah, kunci API, log aktivitas, berkas backup.
 */
class KosongkanData extends Command
{
    protected $signature = 'absensi:kosongkan-data
        {--yakin : Lewati pertanyaan konfirmasi (untuk skrip)}
        {--tanpa-cadangan : Jangan buat backup database dulu (tidak disarankan)}';

    protected $description = 'Hapus semua data siswa/kelas/mapel/presensi/nilai & akun non-admin — backup dibuat otomatis lebih dulu';

    /** Urutan: anak dulu, induk belakangan. */
    private const TABEL = [
        'attendance' => 'Presensi', 'grade_values' => 'Nilai', 'grade_activities' => 'Kegiatan nilai', 'bk_records' => 'Catatan BK',
        'mutasi_siswa' => 'Mutasi siswa', 'tu_surat' => 'Surat TU', 'staff_attendance' => 'Absen pegawai',
        'teacher_subject_class_pairing' => 'Pasangan mapel', 'ketua_kelas_tokens' => 'Tautan ketua kelas',
        'parent_access_tokens' => 'Tautan orang tua', 'class_board_tokens' => 'Tautan papan kelas', 'delegation_tokens' => 'Tautan delegasi',
        'students' => 'Siswa', 'classes' => 'Kelas', 'subjects' => 'Mapel', 'jurusan' => 'Jurusan', 'pusat_webhook' => 'Catatan webhook',
        'pusat_state' => 'Status sinkron Pusat',
    ];

    public function handle(): int
    {
        $bk = (int) config('absensi.bk_subject_id');
        $ada = array_filter(self::TABEL, fn ($t) => Schema::hasTable($t), ARRAY_FILTER_USE_KEY);
        $hapusAkun = $this->akunDihapus();
        $baris = [];
        foreach ($ada as $t => $label) {
            $q = DB::table($t);
            if ($t === 'subjects') {
                $q->where('id', '!=', $bk);
            }
            $baris[] = [$label, $q->count()];
        }
        $baris[] = ['Akun selain admin', count($hapusAkun)];
        $this->warn('Data yang akan DIHAPUS PERMANEN dari Absensi:');
        $this->table(['Data', 'Jumlah'], $baris);
        $this->line('Tetap disimpan: akun admin/superadmin, pengaturan sekolah, kunci API, log aktivitas, mapel "Bimbingan Konseling".');

        if (! $this->option('yakin') && $this->ask('Ketik HAPUS SEMUA untuk melanjutkan') !== 'HAPUS SEMUA') {
            $this->info('Dibatalkan. Tidak ada yang dihapus.');

            return self::FAILURE;
        }
        if (! $this->option('tanpa-cadangan')) {
            $r = BackupService::run();
            if (! $r['success']) {
                $this->error('Backup gagal: '.($r['error'] ?? '?').'. Tidak ada yang dihapus.');

                return self::FAILURE;
            }
            $this->info('Backup dibuat: '.$r['file'].' (lihat Admin → Pengaturan & Backup)');
        }

        DB::transaction(function () use ($ada, $bk, $hapusAkun) {
            foreach (array_keys($ada) as $t) {
                $q = DB::table($t);
                if ($t === 'subjects') {
                    $q->where('id', '!=', $bk);
                }
                $q->delete();
            }
            if (Schema::hasTable('subjects') && Schema::hasColumn('subjects', 'pusat_uuid')) {
                DB::table('subjects')->update(['pusat_uuid' => null, 'kode' => null, 'kelompok' => null, 'aktif' => true]);
            }
            DB::table('users')->whereIn('id', $hapusAkun)->delete();
            $kosong = ['kelas_wali_id' => null, 'subjects' => '[]', 'classes' => '[]'];
            if (Schema::hasColumn('users', 'pegawai_uuid')) {
                $kosong['pegawai_uuid'] = null;
            }
            DB::table('users')->update($kosong);
        });
        @unlink(storage_path('app/pusat-pengampu.json'));
        Audit::log('Kosongkan Data', 'Sistem', 'artisan', 'Semua data siswa/kelas/mapel/presensi/nilai dikosongkan; '.count($hapusAkun).' akun dihapus');

        $this->info('Selesai. Absensi kosong; tekan Sinkron sekarang (Admin → Integrasi) setelah data baru diimpor di Pusat.');

        return self::SUCCESS;
    }

    /** @return int[] id akun tanpa peran admin/superadmin */
    private function akunDihapus(): array
    {
        return DB::table('users')->get(['id', 'roles'])
            ->reject(fn ($u) => (bool) array_intersect(['admin', 'superadmin'], (array) json_decode((string) $u->roles, true)))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }
}
