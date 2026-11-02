<?php

namespace App\Support;

/** Verifikasi tanda tangan webhook Pusat: "sha256=" + HMAC-SHA256(rahasia, "{timestamp}.{badan mentah}"). */
final class PusatTanda
{
    public static function buat(string $rahasia, int $waktu, string $badan): string
    {
        return 'sha256='.hash_hmac('sha256', $waktu.'.'.$badan, $rahasia);
    }

    public static function verifikasi(string $rahasia, int $waktu, string $badan, string $tanda, int $toleransi = 300, ?int $sekarang = null): bool
    {
        if ($rahasia === '' || abs(($sekarang ?? time()) - $waktu) > $toleransi) {
            return false;
        }

        return hash_equals(self::buat($rahasia, $waktu, $badan), $tanda);
    }
}
