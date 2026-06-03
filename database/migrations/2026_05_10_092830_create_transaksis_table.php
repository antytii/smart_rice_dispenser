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
            $table->string('uid_kartu'); // Foreign Key ke wargas
            $table->string('nik');
            $table->float('jumlah_diambil');
            $table->string('keterangan')->nullable(); // Keterangan transaksi dari ESP32
            $table->timestamp('waktu_ambil')->useCurrent();
            $table->timestamps();

            // Relasi ke tabel wargas
            $table->foreign('uid_kartu')
                  ->references('uid_kartu')->on('wargas')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');

            $table->index('uid_kartu');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksis');
    }
};