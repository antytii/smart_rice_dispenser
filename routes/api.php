<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DispenserController;

Route::post('/alat/cek-warga', [DispenserController::class, 'cekWarga']);
Route::post('/alat/transaksi-selesai', [DispenserController::class, 'catatTransaksi']);
Route::post('/alat/update-stok', [DispenserController::class, 'updateStokPerangkat']);