<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kunci API Gemini milik guru MGMP sendiri (dipakai bila GEMINI_API_KEY di .env kosong). Tersimpan terenkripsi. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'ai_key')) {
            Schema::table('users', fn (Blueprint $t) => $t->text('ai_key')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'ai_key')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('ai_key'));
        }
    }
};
