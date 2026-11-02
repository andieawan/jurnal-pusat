<?php

use App\Http\Controllers\Api\V1Controller;
use App\Http\Controllers\Api\V1TulisController;
use Illuminate\Support\Facades\Route;

// Penerima webhook dari Pusat Sekolah (tanda tangan HMAC; lihat PusatWebhookController).
Route::post('/pusat/webhook', \App\Http\Controllers\Api\PusatWebhookController::class)->middleware('throttle:120,1');

// API v1 — hanya baca, autentikasi kunci API per aplikasi (lihat docs/API.md).
Route::prefix('v1')->group(function () {
    Route::get('/openapi.json', [V1Controller::class, 'openapi']);
    Route::get('/ping', [V1Controller::class, 'ping'])->middleware('api.key');

    Route::middleware('api.key:kelas:baca')->group(function () {
        Route::get('/kelas', [V1Controller::class, 'kelasIndex']);
        Route::get('/kelas/{id}', [V1Controller::class, 'kelasShow'])->whereNumber('id');
    });
    Route::get('/mapel', [V1Controller::class, 'mapelIndex'])->middleware('api.key:mapel:baca');
    Route::middleware('api.key:siswa:baca')->group(function () {
        Route::get('/siswa', [V1Controller::class, 'siswaIndex']);
        Route::get('/siswa/{id}', [V1Controller::class, 'siswaShow'])->whereNumber('id');
    });
    Route::middleware('api.key:kehadiran:baca')->group(function () {
        Route::get('/siswa/{id}/rekap', [V1Controller::class, 'rekap'])->whereNumber('id');
        Route::get('/kehadiran', [V1Controller::class, 'kehadiran']);
        Route::get('/kehadiran/ringkasan', [V1Controller::class, 'ringkasan']);
        Route::get('/kehadiran/harian-kelas', [V1TulisController::class, 'harianKelas']);
        Route::get('/delegasi', [V1TulisController::class, 'delegasiIndex'])->middleware('api.key:kehadiran:tulis');
    });
    // Tulis (khusus aplikasi BK): scope kehadiran:tulis
    Route::middleware('api.key:kehadiran:tulis')->group(function () {
        Route::post('/kehadiran/manual', [V1TulisController::class, 'manual']);
        Route::post('/kehadiran/manual/kelas', [V1TulisController::class, 'manualKelas']);
        Route::post('/delegasi', [V1TulisController::class, 'delegasiBuat']);
        Route::delete('/delegasi/{token}', [V1TulisController::class, 'delegasiCabut']);
    });
});
