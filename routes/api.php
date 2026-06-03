<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DispenserController;
use App\Http\Controllers\Api\FirebaseWebhookController;

Route::prefix('alat')->group(function () {
    Route::post('/cek-warga', [DispenserController::class, 'cekWarga']);
    Route::post('/transaksi-selesai', [DispenserController::class, 'catatTransaksi']);
    Route::post('/update-stok', [DispenserController::class, 'updateStokPerangkat']);
});

// Endpoint untuk menerima push data dari Firebase Cloud Functions
// Dilindungi oleh middleware ValidateWebhookSecret
Route::post('/webhook/sensor-data', [FirebaseWebhookController::class, 'store'])
    ->middleware(\App\Http\Middleware\ValidateWebhookSecret::class);