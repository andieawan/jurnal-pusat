<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Penyambungan ke Pusat Sekolah: kolom penaut (UUID Pusat) + status sinkron. Aman dijalankan ulang. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'students', 'classes'] as $table) {
            if (! Schema::hasColumn($table, 'pusat_uuid')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('pusat_uuid', 36)->nullable()->unique();
                });
            }
        }
        if (! Schema::hasTable('pusat_state')) {
            Schema::create('pusat_state', function (Blueprint $t) {
                $t->string('kunci', 40)->primary();
                $t->text('nilai')->nullable();
                $t->string('updated_at', 32)->nullable();
            });
        }
        if (! Schema::hasTable('pusat_webhook')) {
            Schema::create('pusat_webhook', function (Blueprint $t) {
                $t->string('delivery_id', 64)->primary(); // kunci idempotensi (X-Pusat-Delivery)
                $t->string('event', 60);
                $t->string('created_at', 32);
            });
        }
    }

    public function down(): void
    {
        // Sengaja kosong: tidak menghapus data sekolah secara tidak sengaja.
    }
};
