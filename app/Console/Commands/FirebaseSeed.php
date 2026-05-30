<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Firebase\FirebaseService;

class FirebaseSeed extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'firebase:seed {--fresh : Hapus semua data sebelum seed}';

    /**
     * The console command description.
     */
    protected $description = 'Seed data awal ke Firebase Realtime Database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔥 Memulai Firebase Seeder...');

        try {
            $firebase = app(FirebaseService::class);

            // Jika --fresh, hapus semua data dulu
            if ($this->option('fresh')) {
                $this->warn('⚠️  Menghapus semua data di Firebase...');
                $firebase->getReference('wargas')->remove();
                $firebase->getReference('transaksis')->remove();
                $firebase->getReference('perangkats')->remove();
                $this->info('✅ Data lama dihapus.');
            }

            // 1. Seed Data Warga
            $this->info('📝 Menyimpan data warga...');
            $firebase->getReference('wargas/E3F1A2C4')->set([
                'nik' => '7271000011112222',
                'nama' => 'Andi Syahkty',
                'alamat' => 'Palu, Sulawesi Tengah',
                'pin' => '1234',
                'jatah_lalu' => 10.0,
                'jatah_ini' => 10.0,
                'status' => 'Aktif',
            ]);
            $this->info('  ✅ Warga "Andi Syahkty" (E3F1A2C4) berhasil ditambahkan.');

            // 2. Seed Data Perangkat
            $this->info('📝 Menyimpan data perangkat...');
            $firebase->getReference('perangkats/BANSOS-M1')->set([
                'sisa_stok_beras' => 100.0,
                'persentase_stok' => 100.0,
                'status_alat' => 'Online',
                'updated_at' => now()->timestamp,
            ]);
            $this->info('  ✅ Perangkat "BANSOS-M1" berhasil ditambahkan.');

            $this->newLine();
            $this->info('🎉 Firebase seeding selesai!');
            $this->table(
                ['Node', 'Key', 'Keterangan'],
                [
                    ['wargas', 'E3F1A2C4', 'Andi Syahkty - PIN: 1234'],
                    ['perangkats', 'BANSOS-M1', 'Stok: 100 Kg (100%)'],
                ]
            );

        } catch (\Exception $e) {
            $this->error('❌ Gagal: ' . $e->getMessage());
            $this->newLine();
            $this->warn('Pastikan:');
            $this->warn('  1. File service-account.json ada di storage/app/firebase/');
            $this->warn('  2. FIREBASE_DATABASE_URL sudah diset di .env');
            $this->warn('  3. Realtime Database sudah aktif di Firebase Console');
            return 1;
        }

        return 0;
    }
}
