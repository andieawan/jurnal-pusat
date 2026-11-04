<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kunci API Gemini bersama satu MGMP (per mapel). Kolom `kunci` terenkripsi dengan APP_KEY. */
class AiKunciMgmp extends Model
{
    protected $table = 'ai_kunci_mgmp';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['kunci'];

    protected $casts = ['subject_id' => 'integer', 'kunci' => 'encrypted'];

    protected static function booted(): void
    {
        static::saving(fn (self $k) => $k->updated_at = now('UTC')->format('Y-m-d H:i:s'));
    }
}
