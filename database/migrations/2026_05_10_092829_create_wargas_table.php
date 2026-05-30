<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wargas', function (Blueprint $table) {
            $table->string('uid_kartu')->primary(); // PK (String)
            $table->string('nik')->unique();
            $table->string('nama');
            $table->text('alamat');
            $table->string('pin');
            $table->float('jatah_bulanan'); 
            $table->float('sisa_jatah');
            $table->string('status')->default('Aktif'); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wargas');
    }
};