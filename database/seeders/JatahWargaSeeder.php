<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Warga;
use App\Models\JatahWarga;
use Carbon\Carbon;

class JatahWargaSeeder extends Seeder
{
    public function run(): void
    {
        // Bersihkan tabel sebelum seeding
        JatahWarga::truncate();

        $wargas = Warga::all();

        // Buat data riwayat dari Januari 2026 sampai bulan ini
        $bulanSekarang = (int) now()->format('m');
        $tahun = now()->format('Y');

        foreach ($wargas as $warga) {
            // Warga pertama (Budi) rajin ambil beras, hanya bulan ini yang belum
            // Warga kedua (Siti) belum ambil dari 3 bulan lalu

            for ($bulan = 1; $bulan <= $bulanSekarang; $bulan++) {
                $periode = $tahun . '-' . str_pad($bulan, 2, '0', STR_PAD_LEFT);
                
                // Secara default, bulan-bulan sebelumnya sudah diambil
                $status = 'Sudah Diambil';
                $diambilPada = Carbon::create($tahun, $bulan, rand(1, 28))->format('Y-m-d H:i:s');

                // Simulasi warga menunggak: 
                // Jika UID adalah kartu tertentu, atau secara acak kita set Belum Diambil untuk bulan-bulan terakhir
                if ($bulan == $bulanSekarang) {
                    $status = 'Belum Diambil';
                    $diambilPada = null;
                } elseif ($bulan == $bulanSekarang - 1 && $warga->id % 2 == 0) {
                    // Warga dengan ID genap tidak ngambil bulan lalu
                    $status = 'Belum Diambil';
                    $diambilPada = null;
                } elseif ($bulan == $bulanSekarang - 2 && $warga->id % 3 == 0) {
                    // Warga dengan ID kelipatan 3 tidak ngambil dari 2 bulan lalu
                    $status = 'Belum Diambil';
                    $diambilPada = null;
                }

                JatahWarga::create([
                    'uid_kartu' => $warga->uid_kartu,
                    'periode_bulan' => $periode,
                    'jumlah_kg' => $warga->jatah_bulanan ?? 10,
                    'status' => $status,
                    'diambil_pada' => $diambilPada
                ]);
            }
        }
    }
}
