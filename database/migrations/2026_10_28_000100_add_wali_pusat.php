<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wali kelas dari Pusat: kelas menyimpan pegawai wali (UUID Pusat), akun menyimpan pegawai miliknya. Aman dijalankan ulang. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'pegawai_uuid')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('pegawai_uuid', 36)->nullable()->index());
        }
        if (! Schema::hasColumn('classes', 'wali_pusat_uuid')) {
            Schema::table('classes', fn (Blueprint $t) => $t->string('wali_pusat_uuid', 36)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pegawai_uuid')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('pegawai_uuid'));
        }
        if (Schema::hasColumn('classes', 'wali_pusat_uuid')) {
            Schema::table('classes', fn (Blueprint $t) => $t->dropColumn('wali_pusat_uuid'));
        }
    }
};
