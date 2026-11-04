<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu set perangkat ajar milik seorang guru. Isi dokumen di kolom `hasil` (lihat App\Services\Perangkat). */
class PerangkatAjar extends Model
{
    protected $table = 'perangkat_ajar';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['user_id' => 'integer', 'subject_id' => 'integer', 'class_id' => 'integer', 'dari_id' => 'integer', 'isian' => 'array', 'hasil' => 'array'];

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            $now = now('UTC')->format('Y-m-d H:i:s');
            $p->created_at ??= $now;
            $p->updated_at = $now;
        });
    }

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

    public function dariAi(string $dok): bool
    {
        return in_array($dok, explode(',', $this->sumber), true);
    }
}
