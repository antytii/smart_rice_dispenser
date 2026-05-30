<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Warga;

class WargaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Warga::query()->delete(); // Bersihkan data lama agar tidak duplikat NIK

        $wargas = [
            [
                'uid_kartu' => '11223344',
                'nik' => '3201012301900001',
                'nama' => 'Budi Santoso',
                'alamat' => 'Jl. Kemerdekaan No. 10, RT 01/RW 02',
                'pin' => '1234',
                'jatah_bulanan' => 10,
                'jatah_lalu' => 0,
                'jatah_ini' => 10,
                'status' => 'Aktif',
            ],
            [
                'uid_kartu' => '22334455',
                'nik' => '3201012301900002',
                'nama' => 'Siti Aminah',
                'alamat' => 'Jl. Kebangsaan No. 45, RT 03/RW 02',
                'pin' => '5678',
                'jatah_bulanan' => 10,
                'jatah_lalu' => 0,
                'jatah_ini' => 10,
                'status' => 'Aktif',
            ],
            [
                'uid_kartu' => '33445566',
                'nik' => '3201012301900003',
                'nama' => 'Ahmad Dahlan',
                'alamat' => 'Jl. Perintis No. 99, RT 01/RW 03',
                'pin' => '9012',
                'jatah_bulanan' => 10,
                'jatah_lalu' => 5,
                'jatah_ini' => 10,
                'status' => 'Nonaktif',
            ],
        ];

        foreach ($wargas as $warga) {
            Warga::updateOrCreate(
                ['uid_kartu' => $warga['uid_kartu']], // Cari berdasarkan uid_kartu
                $warga // Jika tidak ada buat baru, jika ada update dengan data ini
            );
        }
    }
}
