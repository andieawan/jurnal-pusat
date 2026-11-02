<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\Jurnal;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Facades\DB;

/** Simpan satu pertemuan: jurnal mengajar + absensi mapel, sekaligus dalam satu transaksi. */
final class JurnalService
{
    /**
     * @param  array{materi:string,kegiatan?:?string,catatan?:?string,rencana?:?string}  $isi
     * @param  array<int,array{student_id:int,status:string,notes?:string}>  $entries
     * @return array{jurnal:Jurnal, baru:bool, absen:array}
     */
    public static function simpan(User $u, int $classId, int $subjectId, string $tanggal, string $jamKe, array $isi, array $entries): array
    {
        if (! Dates::valid($tanggal) || Dates::isFuture($tanggal)) {
            throw new UserError('Tanggal pertemuan tidak valid atau berada di masa depan.');
        }
        Rules::requireTeacher($u, $subjectId, $classId);
        if (! Rules::withinEditWindow($tanggal) && ! $u->isAdmin()) {
            throw new UserError('Jurnal tanggal '.Dates::human($tanggal).' melebihi batas pengisian '.(int) config('absensi.edit_window_days', 7).' hari. Hubungi Administrator.');
        }
        $jamKe = self::jam($jamKe);
        $materi = trim((string) ($isi['materi'] ?? ''));
        if ($materi === '') {
            throw new UserError('Materi / tujuan pembelajaran wajib diisi.');
        }
        foreach (['materi' => 2000, 'kegiatan' => 2000, 'catatan' => 1000, 'rencana' => 1000] as $k => $maks) {
            if (mb_strlen((string) ($isi[$k] ?? '')) > $maks) {
                throw new UserError(ucfirst($k)." maksimal $maks karakter.");
            }
        }

        return DB::transaction(function () use ($u, $classId, $subjectId, $tanggal, $jamKe, $isi, $materi, $entries) {
            $absen = $entries ? AttendanceService::submit($u, $classId, $subjectId, $tanggal, $entries, 'guru') : ['created' => 0, 'updated' => 0, 'alerts' => []];
            $now = now('UTC')->format('Y-m-d H:i:s');
            $j = Jurnal::firstOrNew(['class_id' => $classId, 'subject_id' => $subjectId, 'tanggal' => $tanggal, 'jam_ke' => $jamKe]);
            $baru = ! $j->exists;
            $j->fill([
                'user_id' => $u->id, 'materi' => $materi,
                'kegiatan' => trim((string) ($isi['kegiatan'] ?? '')) ?: null,
                'catatan' => trim((string) ($isi['catatan'] ?? '')) ?: null,
                'rencana' => trim((string) ($isi['rencana'] ?? '')) ?: null,
                'updated_at' => $now,
            ]);
            if ($baru) {
                $j->created_at = $now;
            }
            $j->save();
            Audit::log($baru ? 'Isi Jurnal' : 'Ubah Jurnal', 'Jurnal', $u->nama, "Kelas #$classId, Mapel #$subjectId, $tanggal".($jamKe !== '' ? " jam ke-$jamKe" : ''));

            return ['jurnal' => $j, 'baru' => $baru, 'absen' => $absen];
        });
    }

    /** "1 - 2" → "1-2"; hanya angka dan strip, maks 10 karakter. */
    public static function jam(?string $jam): string
    {
        $j = preg_replace('/\s+/', '', (string) $jam);
        if ($j !== '' && ! preg_match('/^\d{1,2}(-\d{1,2})?$/', $j)) {
            throw new UserError('Jam ke- diisi angka, mis. 3 atau 3-4.');
        }

        return $j;
    }

    public static function hapus(User $u, Jurnal $j): void
    {
        Rules::requireTeacher($u, $j->subject_id, $j->class_id);
        if (! Rules::withinEditWindow($j->tanggal) && ! $u->isAdmin()) {
            throw new UserError('Jurnal lama hanya bisa dihapus Administrator.');
        }
        $j->delete();
        Audit::log('Hapus Jurnal', 'Jurnal', $u->nama, "Kelas #{$j->class_id}, Mapel #{$j->subject_id}, {$j->tanggal}");
    }
}
