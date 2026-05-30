<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Firebase\FirebaseService;
use App\Services\Firebase\WargaService;
use App\Services\Firebase\JatahWargaService;
use Carbon\Carbon;

class FirebaseSeed extends Command
{
    protected $signature   = 'firebase:seed {--fresh : Hapus semua data dulu sebelum seed}';
    protected $description = 'Seed data awal (warga & perangkat) ke Firebase Realtime Database';

    public function handle(): void
    {
        $firebase      = new FirebaseService();
        $wargaService  = new WargaService($firebase);
        $jatahService  = new JatahWargaService($firebase, $wargaService);

        if ($this->option('fresh')) {
            $this->warn('Menghapus semua data lama...');
            $firebase->delete('wargas');
            $firebase->delete('jatah_wargas');
            $firebase->delete('transaksis');
            $firebase->delete('perangkats');
            $this->info('Data lama dihapus.');
        }

        // ==============================================================
        // 1. Seed Warga
        // ==============================================================
        $this->info('Menyimpan data warga...');

        $wargas = [
            [
                'uid_kartu'     => '11223344',
                'nik'           => '3201012301900001',
                'nama'          => 'Budi Santoso',
                'alamat'        => 'Jl. Kemerdekaan No. 10, RT 01/RW 02',
                'pin'           => '1234',
                'jatah_bulanan' => 10,
                'status'        => 'Aktif',
            ],
            [
                'uid_kartu'     => '22334455',
                'nik'           => '3201012301900002',
                'nama'          => 'Siti Aminah',
                'alamat'        => 'Jl. Kebangsaan No. 45, RT 03/RW 02',
                'pin'           => '5678',
                'jatah_bulanan' => 10,
                'status'        => 'Aktif',
            ],
            [
                'uid_kartu'     => '33445566',
                'nik'           => '3201012301900003',
                'nama'          => 'Ahmad Dahlan',
                'alamat'        => 'Jl. Perintis No. 99, RT 01/RW 03',
                'pin'           => '9012',
                'jatah_bulanan' => 10,
                'status'        => 'Nonaktif',
            ],
        ];

        foreach ($wargas as $warga) {
            $uid = $warga['uid_kartu'];
            unset($warga['uid_kartu']);
            $firebase->set("wargas/{$uid}", $warga);
            $this->line("  ✓ Warga: {$warga['nama']}");
        }

        // ==============================================================
        // 2. Seed Perangkat
        // ==============================================================
        $this->info('Menyimpan data perangkat...');

        $firebase->set('perangkats/ALAT-001', [
            'sisa_stok_beras' => 100.0,
            'persentase_stok' => 100.0,
            'status_alat'     => 'Offline',
            'last_ping'       => Carbon::now()->subHour()->toIso8601String(),
        ]);
        $this->line('  ✓ Perangkat: ALAT-001');

        // ==============================================================
        // 3. Seed Jatah Warga (Historis Multi-Bulan)
        // ==============================================================
        $this->info('Menyimpan data jatah warga bulanan (historis)...');

        $currentMonth = Carbon::now()->format('Y-m');
        $lastMonth    = Carbon::now()->subMonth()->format('Y-m');
        $twoMonthsAgo = Carbon::now()->subMonths(2)->format('Y-m');

        // Budi Santoso (11223344)
        // - 2 bulan lalu: Sudah Diambil (10kg)
        // - 1 bulan lalu: Belum Diambil (10kg) -> ini akan jadi 'jatah_lalu'
        // - Bulan ini: Belum Diambil (10kg) -> ini akan jadi 'jatah_ini'
        $firebase->set("jatah_wargas/11223344/{$twoMonthsAgo}", [
            'jumlah_kg' => 10,
            'status' => 'Sudah Diambil',
            'diambil_pada' => Carbon::now()->subMonths(2)->addDays(5)->toIso8601String(),
            'created_at' => Carbon::now()->subMonths(2)->toIso8601String(),
        ]);
        $firebase->set("jatah_wargas/11223344/{$lastMonth}", [
            'jumlah_kg' => 10,
            'status' => 'Belum Diambil',
            'diambil_pada' => null,
            'created_at' => Carbon::now()->subMonth()->toIso8601String(),
        ]);
        $firebase->set("jatah_wargas/11223344/{$currentMonth}", [
            'jumlah_kg' => 10,
            'status' => 'Belum Diambil',
            'diambil_pada' => null,
            'created_at' => Carbon::now()->toIso8601String(),
        ]);

        // Siti Aminah (22334455)
        // - 2 bulan lalu: Sudah Diambil (10kg)
        // - 1 bulan lalu: Sudah Diambil (10kg)
        // - Bulan ini: Belum Diambil (10kg)
        $firebase->set("jatah_wargas/22334455/{$twoMonthsAgo}", [
            'jumlah_kg' => 10,
            'status' => 'Sudah Diambil',
            'diambil_pada' => Carbon::now()->subMonths(2)->addDays(12)->toIso8601String(),
            'created_at' => Carbon::now()->subMonths(2)->toIso8601String(),
        ]);
        $firebase->set("jatah_wargas/22334455/{$lastMonth}", [
            'jumlah_kg' => 10,
            'status' => 'Sudah Diambil',
            'diambil_pada' => Carbon::now()->subMonth()->addDays(8)->toIso8601String(),
            'created_at' => Carbon::now()->subMonth()->toIso8601String(),
        ]);
        $firebase->set("jatah_wargas/22334455/{$currentMonth}", [
            'jumlah_kg' => 10,
            'status' => 'Belum Diambil',
            'diambil_pada' => null,
            'created_at' => Carbon::now()->toIso8601String(),
        ]);

        // Ahmad Dahlan (33445566) - Nonaktif
        // - 2 bulan lalu: Belum Diambil (10kg)
        // - 1 bulan lalu: Belum Diambil (10kg)
        // - Bulan ini: (Tidak dapat jatah karena Nonaktif)
        $firebase->set("jatah_wargas/33445566/{$twoMonthsAgo}", [
            'jumlah_kg' => 10,
            'status' => 'Belum Diambil',
            'diambil_pada' => null,
            'created_at' => Carbon::now()->subMonths(2)->toIso8601String(),
        ]);
        $firebase->set("jatah_wargas/33445566/{$lastMonth}", [
            'jumlah_kg' => 10,
            'status' => 'Belum Diambil',
            'diambil_pada' => null,
            'created_at' => Carbon::now()->subMonth()->toIso8601String(),
        ]);

        // ==============================================================
        // 4. Seed Log Transaksi Historis (biar grafik muncul)
        // ==============================================================
        $this->info('Menyimpan data transaksi historis...');

        // Transaksi 2 bulan lalu
        $firebase->push('transaksis', [
            'uid_kartu' => '11223344',
            'nik' => '3201012301900001',
            'jumlah_diambil' => 10.0,
            'keterangan' => 'Ambil jatah bulan lalu',
            'created_at' => Carbon::now()->subMonths(2)->addDays(5)->toIso8601String(),
        ]);
        $firebase->push('transaksis', [
            'uid_kartu' => '22334455',
            'nik' => '3201012301900002',
            'jumlah_diambil' => 10.0,
            'keterangan' => 'Ambil jatah bulan ini',
            'created_at' => Carbon::now()->subMonths(2)->addDays(12)->toIso8601String(),
        ]);

        // Transaksi 1 bulan lalu
        $firebase->push('transaksis', [
            'uid_kartu' => '22334455',
            'nik' => '3201012301900002',
            'jumlah_diambil' => 10.0,
            'keterangan' => 'Ambil jatah bulan ini',
            'created_at' => Carbon::now()->subMonth()->addDays(8)->toIso8601String(),
        ]);

        // Tambahkan transaksi harian beberapa hari terakhir agar grafik mingguan aktif!
        for ($i = 5; $i >= 1; $i--) {
            $tgl = Carbon::now()->subDays($i);
            $firebase->push('transaksis', [
                'uid_kartu' => ($i % 2 === 0) ? '11223344' : '22334455',
                'nik' => ($i % 2 === 0) ? '3201012301900001' : '3201012301900002',
                'jumlah_diambil' => 5.0,
                'keterangan' => 'Uji Coba Distribusi',
                'created_at' => $tgl->toIso8601String(),
            ]);
        }

        // Jalankan lazy generation untuk sisa jatah bulan ini yang belum dibuat
        $jatahService->pastikanJatahBulanIniAda();

        $this->newLine();
        $this->info('✅ Firebase seed historis selesai!');
        $this->info('Buka Firebase Console untuk memverifikasi data.');
    }
}
