<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal mengajar: satu baris per pertemuan (kelas + mapel + tanggal + jam ke-).
 * Absensi pertemuan itu tetap di tabel attendance (per siswa, per mapel, per tanggal). Aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jurnal')) {
            return;
        }
        Schema::create('jurnal', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id');                     // guru yang mengisi (terakhir)
            $t->integer('class_id');
            $t->integer('subject_id');
            $t->string('tanggal', 10);
            $t->string('jam_ke', 10)->default('');      // mis. "1-2"; kosong bila tidak diisi
            $t->text('materi');                         // tujuan pembelajaran / materi
            $t->text('kegiatan')->nullable();           // kegiatan & metode
            $t->text('catatan')->nullable();            // kejadian di kelas
            $t->text('rencana')->nullable();            // rencana pertemuan berikutnya
            $t->string('created_at', 32)->nullable();
            $t->string('updated_at', 32)->nullable();
            $t->unique(['class_id', 'subject_id', 'tanggal', 'jam_ke'], 'uniq_jurnal');
            $t->index(['user_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal');
    }
};
