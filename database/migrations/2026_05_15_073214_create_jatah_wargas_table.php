<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jatah_wargas', function (Blueprint $table) {
            $table->id();
            $table->string('uid_kartu'); // Berelasi dengan tabel wargas
            $table->string('periode_bulan'); // Format: YYYY-MM
            $table->integer('jumlah_kg');
            $table->enum('status', ['Belum Diambil', 'Sudah Diambil'])->default('Belum Diambil');
            $table->timestamp('diambil_pada')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jatah_wargas');
    }
};
