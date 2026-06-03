<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wargas', function (Blueprint $table) {
            $table->string('uid_kartu')->primary(); // PK (String) — UID e-KTP RFID
            $table->string('nik', 16)->unique();
            $table->string('nama');
            $table->text('alamat');
            $table->string('pin', 4);
            $table->float('jatah_bulanan')->default(10); // Kuota per bulan (kg)
            $table->string('status')->default('Aktif'); // 'Aktif' atau 'Nonaktif'
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wargas');
    }
};