<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perangkats', function (Blueprint $table) {
            $table->string('id_alat')->primary(); // PK (String)
            $table->float('sisa_stok_beras'); 
            $table->float('persentase_stok'); 
            $table->string('status_alat')->default('Online'); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perangkats');
    }
};