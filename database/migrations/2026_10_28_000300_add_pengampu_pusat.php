<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pasangan mapel dari Pusat (Pengampu Mapel): baris yang berasal dari Pusat ditandai UUID-nya. Aman dijalankan ulang. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('teacher_subject_class_pairing', 'pusat_uuid')) {
            Schema::table('teacher_subject_class_pairing', fn (Blueprint $t) => $t->string('pusat_uuid', 36)->nullable()->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('teacher_subject_class_pairing', 'pusat_uuid')) {
            Schema::table('teacher_subject_class_pairing', function (Blueprint $t) {
                $t->dropIndex(['pusat_uuid']);
                $t->dropColumn('pusat_uuid');
            });
        }
    }
};
