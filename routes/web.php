<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/data-warga', [DashboardController::class, 'dataWarga'])
    ->middleware(['auth', 'verified'])
    ->name('data-warga');

Route::get('/grafik', [DashboardController::class, 'grafik'])
    ->middleware(['auth', 'verified'])
    ->name('grafik');

Route::post('/warga', [DashboardController::class, 'storeWarga'])
    ->middleware(['auth', 'verified'])
    ->name('warga.store');

Route::put('/warga/{uid}', [DashboardController::class, 'updateWarga'])
    ->middleware(['auth', 'verified'])
    ->name('warga.update');

Route::delete('/warga/{uid}', [DashboardController::class, 'destroyWarga'])
    ->middleware(['auth', 'verified'])
    ->name('warga.destroy');

Route::post('/warga/{uid}/tambah-jatah', [DashboardController::class, 'tambahJatah'])
    ->middleware(['auth', 'verified'])
    ->name('warga.tambah-jatah');

Route::delete('/warga/{uid}/hapus-jatah/{periode}', [DashboardController::class, 'hapusJatah'])
    ->middleware(['auth', 'verified'])
    ->name('warga.hapus-jatah');


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
