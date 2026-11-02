<?php

namespace App\Models;

use App\Models\Concerns\UsesSequenceId;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use UsesSequenceId;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer', 'aktif' => 'boolean'];

    /** Mapel reguler (tanpa Bimbingan Konseling). */
    public function scopeRegular($q)
    {
        $q->where('id', '!=', config('absensi.bk_subject_id'));
        if (self::adaKolomAktif()) {
            $q->where('aktif', true); // mapel yang dinonaktifkan/dihapus di Pusat tidak ditawarkan lagi (riwayat tetap)
        }

        return $q;
    }

    public static function adaKolomAktif(): bool
    {
        static $ada = null;

        return $ada ??= \Illuminate\Support\Facades\Schema::hasColumn('subjects', 'aktif');
    }

    /** Daftar mapel sudah diambil dari Pusat → tambah/ubah/hapus di Absensi dikunci. */
    public static function dariPusat(): bool
    {
        return \App\Services\PusatKlien::kelolaSiswa() && (bool) \App\Services\PusatKlien::baca('mapel_pusat');
    }
}
