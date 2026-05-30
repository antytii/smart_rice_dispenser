<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DispenserController;

Route::prefix('alat')->group(function () {
    Route::post('/cek-warga', [DispenserController::class, 'cekWarga']);
    Route::post('/transaksi-selesai', [DispenserController::class, 'catatTransaksi']);
    Route::post('/update-stok', [DispenserController::class, 'updateStokPerangkat']);
});