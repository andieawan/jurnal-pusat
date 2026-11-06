<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kontak orang tua di data siswa (untuk tombol WhatsApp gratis via wa.me).
 * (Catatan BK dulu juga di sini; sekarang ada di aplikasi BK.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $t) {
            if (! Schema::hasColumn('students', 'nama_ortu')) {
                $t->string('nama_ortu', 191)->nullable();
            }
            if (! Schema::hasColumn('students', 'telp_ortu')) {
                $t->string('telp_ortu', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Sengaja kosong: tidak menghapus data sekolah.
    }
};
