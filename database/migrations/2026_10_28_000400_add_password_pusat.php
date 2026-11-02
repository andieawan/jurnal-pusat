<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login cadangan: salinan hash password dari Pusat (izin "akun_sandi"), dipakai login lokal saat Pusat tidak bisa dihubungi.
 * Disimpan terpisah dari password_hash agar pembaruan dari Pusat tidak mengeluarkan sesi yang sedang aktif. Aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_pusat')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('password_pusat', 255)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'password_pusat')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('password_pusat'));
        }
    }
};
