<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Perangkat ajar guru (modul ajar, LKPD, asesmen, bahan bacaan, tindak lanjut) per mapel + kelas. Aman diulang. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('perangkat_ajar')) {
            return;
        }
        Schema::create('perangkat_ajar', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');                 // guru pemilik
            $t->integer('subject_id');
            $t->integer('class_id');
            $t->string('topik', 200);
            $t->text('isian');                      // JSON: data yang diisi guru di formulir
            $t->longText('hasil');                  // JSON: isi 5 dokumen (struktur Perangkat::kerangka)
            $t->string('sumber', 120)->default(''); // dokumen mana yang dibuat AI, mis. "modul,lkpd"
            $t->string('created_at', 32)->nullable();
            $t->string('updated_at', 32)->nullable();
            $t->index(['user_id', 'subject_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perangkat_ajar');
    }
};
