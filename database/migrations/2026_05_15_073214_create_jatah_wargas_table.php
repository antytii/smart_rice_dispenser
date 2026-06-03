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
            $table->float('jumlah_kg')->default(10); // Diubah dari integer ke float agar konsisten
            $table->enum('status', ['Belum Diambil', 'Sudah Diambil'])->default('Belum Diambil');
            $table->timestamp('diambil_pada')->nullable();
            $table->timestamps();

            // Unique constraint: satu warga hanya punya satu jatah per periode
            $table->unique(['uid_kartu', 'periode_bulan']);

            // Foreign key ke tabel wargas
            $table->foreign('uid_kartu')
                  ->references('uid_kartu')->on('wargas')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jatah_wargas');
    }
};
