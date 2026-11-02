<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jurnal mengajar satu pertemuan. */
class Jurnal extends Model
{
    protected $table = 'jurnal';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['user_id' => 'integer', 'class_id' => 'integer', 'subject_id' => 'integer'];

    public function guru()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function kelas()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function mapel()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /** Pertemuan terakhir sebelum (tanggal, jam) tertentu untuk kelas + mapel yang sama. */
    public static function sebelumnya(int $classId, int $subjectId, string $tanggal, string $jamKe = ''): ?self
    {
        return self::where('class_id', $classId)->where('subject_id', $subjectId)
            ->where(fn ($q) => $q->where('tanggal', '<', $tanggal)->orWhere(fn ($q) => $q->where('tanggal', $tanggal)->where('jam_ke', '<', $jamKe)))
            ->orderByDesc('tanggal')->orderByDesc('jam_ke')->first();
    }
}
