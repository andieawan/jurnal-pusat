<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perangkat ajar (modul ajar, LKPD, asesmen, bahan bacaan, tindak lanjut). MGMP menyusun draf per mapel + tingkat tiap
 * semester; guru mengambil draf itu untuk kelasnya menjadi perangkat ajar miliknya. Aman diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('perangkat_ajar')) {
            return;
        }
        Schema::create('perangkat_ajar', function (Blueprint $t) {
            $t->id();
            $t->string('jenis', 10)->default('guru'); // mgmp = draf bersama MGMP mapel; guru = perangkat ajar milik guru
            $t->integer('user_id');                 // pembuat (draf MGMP) / pemilik (perangkat guru)
            $t->integer('subject_id');
            $t->integer('class_id')->nullable();    // kosong untuk draf MGMP (berlaku per tingkat)
            $t->string('tingkat', 5)->default('');  // X / XI / XII
            $t->string('tahun', 12)->default('');   // tahun ajaran, mis. 2026/2027
            $t->string('semester', 10)->default('');
            $t->string('status', 10)->default('draf'); // draf MGMP: draf | siap (boleh diambil guru)
            $t->integer('dari_id')->nullable();     // perangkat guru: draf MGMP asalnya
            $t->string('topik', 200);
            $t->text('isian');                      // JSON: data yang diisi guru di formulir
            $t->longText('hasil');                  // JSON: isi 5 dokumen (struktur Perangkat::kerangka)
            $t->string('sumber', 120)->default(''); // dokumen mana yang dibuat AI, mis. "modul,lkpd"
            $t->string('created_at', 32)->nullable();
            $t->string('updated_at', 32)->nullable();
            $t->index(['jenis', 'subject_id', 'tahun', 'semester']);
            $t->index(['user_id', 'subject_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perangkat_ajar');
    }
};
