<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Warga;
use App\Models\Perangkat;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Data Dummy Warga
        // UID_Kartu diset 'E3F1A2C4' (nanti kita samakan dengan salah satu kartu di Wokwi)
        Warga::create([
            'uid_kartu' => 'E3F1A2C4', 
            'nik' => '7271000011112222',
            'nama' => 'Andi Syahkty',
            'alamat' => 'Palu, Sulawesi Tengah',
            'pin' => '1234',
            'jatah_bulanan' => 10.0,
            'sisa_jatah' => 30.0, // Misal punya jatah rapel 3 bulan
            'status' => 'Aktif'
        ]);

        // 2. Data Dummy Perangkat Mesin
        Perangkat::create([
            'id_alat' => 'BANSOS-M1',
            'sisa_stok_beras' => 100.0, // Tangki full 100 kg
            'persentase_stok' => 100.0,
            'status_alat' => 'Online'
        ]);
    }
}