<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perangkats', function (Blueprint $table) {
            $table->string('id_alat')->primary(); // PK (String), contoh: "ALAT-001"
            $table->float('sisa_stok_beras')->default(0);
            $table->float('persentase_stok')->default(0);
            $table->string('status_alat')->default('Online');
            $table->timestamp('last_ping')->nullable(); // Waktu terakhir ESP32 heartbeat
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perangkats');
    }
};