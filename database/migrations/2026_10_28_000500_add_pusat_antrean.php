<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Antrean perubahan yang harus dikirim ke Pusat (mis. kontak orang tua) saat Pusat tidak bisa dihubungi. Aman dijalankan ulang. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pusat_antrean')) {
            Schema::create('pusat_antrean', function (Blueprint $t) {
                $t->id();
                $t->string('jenis', 30);
                $t->string('kunci', 80);
                $t->text('data');
                $t->unsignedInteger('percobaan')->default(0);
                $t->string('galat', 500)->nullable();
                $t->string('dibuat', 20);
                $t->string('dicoba', 20)->nullable();
                $t->unique(['jenis', 'kunci']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pusat_antrean');
    }
};
