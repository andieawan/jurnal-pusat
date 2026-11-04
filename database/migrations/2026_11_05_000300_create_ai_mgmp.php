<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci API Gemini bersama per MGMP (satu per mapel, terenkripsi) dan catatan pemakaian AI untuk kuota per mapel per
 * semester (AI_KUOTA_MGMP) dan per guru (AI_KUOTA_GURU). Aman diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_kunci_mgmp')) {
            Schema::create('ai_kunci_mgmp', function (Blueprint $t) {
                $t->id();
                $t->integer('subject_id')->unique();
                $t->text('kunci');                       // terenkripsi (APP_KEY)
                $t->string('oleh', 120)->default('');    // nama yang terakhir mengisi
                $t->string('updated_at', 32)->nullable();
            });
        }
        if (! Schema::hasTable('ai_pemakaian')) {
            Schema::create('ai_pemakaian', function (Blueprint $t) {
                $t->id();
                $t->integer('subject_id');
                $t->integer('user_id');
                $t->string('tahun', 12)->default('');
                $t->string('semester', 10)->default('');
                $t->string('dok', 10);
                $t->string('kunci', 10);                 // sekolah | mgmp | pribadi
                $t->string('jenis', 10)->default('mgmp'); // mgmp = draf MGMP; guru = perangkat milik guru
                $t->string('created_at', 32)->nullable();
                $t->index(['subject_id', 'tahun', 'semester']);
                $t->index(['user_id', 'tahun', 'semester']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pemakaian');
        Schema::dropIfExists('ai_kunci_mgmp');
    }
};
