<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mata pelajaran dari Pusat: ID lokal tetap (riwayat presensi/nilai aman), ditautkan lewat UUID Pusat. Aman dijalankan ulang. */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'pusat_uuid' => fn (Blueprint $t) => $t->string('pusat_uuid', 36)->nullable()->unique(),
            'kode' => fn (Blueprint $t) => $t->string('kode', 20)->nullable(),
            'kelompok' => fn (Blueprint $t) => $t->string('kelompok', 20)->nullable(),
            'aktif' => fn (Blueprint $t) => $t->boolean('aktif')->default(true),
        ] as $kolom => $buat) {
            if (! Schema::hasColumn('subjects', $kolom)) {
                Schema::table('subjects', $buat);
            }
        }
    }

    public function down(): void
    {
        foreach (['pusat_uuid', 'kode', 'kelompok', 'aktif'] as $kolom) {
            if (Schema::hasColumn('subjects', $kolom)) {
                Schema::table('subjects', function (Blueprint $t) use ($kolom) {
                    if ($kolom === 'pusat_uuid') {
                        $t->dropUnique(['pusat_uuid']);
                    }
                    $t->dropColumn($kolom);
                });
            }
        }
    }
};
