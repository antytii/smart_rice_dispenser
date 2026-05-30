<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksis', function (Blueprint $table) {
            $table->id('id_transaksi'); // Auto-increment PK
            $table->string('uid_kartu'); // Foreign Key
            $table->string('nik');
            $table->float('jumlah_diambil');
            $table->timestamp('waktu_ambil')->useCurrent();
            $table->timestamps();

            // Relasi ke tabel wargas
            $table->foreign('uid_kartu')
                  ->references('uid_kartu')->on('wargas')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksis');
    }
};