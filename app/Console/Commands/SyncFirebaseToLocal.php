<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Firebase\FirebaseService;
use App\Models\Warga;
use App\Models\Transaksi;
use App\Models\Perangkat;
use App\Models\JatahWarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SyncFirebaseToLocal extends Command
{
    protected $signature = 'firebase:sync {--fresh : Hapus semua data lokal sebelum sinkronisasi}';
    protected $description = 'Sinkronisasi data dari Firebase Realtime Database ke MySQL lokal (sekali jalan)';

    public function handle()
    {
        $this->info('🔄 Memulai sinkronisasi Firebase → MySQL...');
        $this->newLine();

        try {
            $firebase = new FirebaseService();
        } catch (\Exception $e) {
            $this->error('❌ Gagal terhubung ke Firebase: ' . $e->getMessage());
            return 1;
        }

        if ($this->option('fresh')) {
            $this->warn('⚠️  Menghapus semua data lokal...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            JatahWarga::truncate();
            Transaksi::truncate();
            Perangkat::truncate();
            Warga::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->info('✅ Data lokal dihapus.');
            $this->newLine();
        }

        // ==========================================
        // 1. SINKRONISASI WARGA
        // ==========================================
        $this->info('📋 [1/4] Sinkronisasi tabel wargas...');
        $wargas = $firebase->get('wargas');
        $countWarga = 0;

        if ($wargas) {
            foreach ($wargas as $uid => $data) {
                Warga::updateOrCreate(
                    ['uid_kartu' => (string) $uid],
                    [
                        'nik'           => $data['nik'] ?? '',
                        'nama'          => $data['nama'] ?? '',
                        'alamat'        => $data['alamat'] ?? '',
                        'pin'           => $data['pin'] ?? '0000',
                        'jatah_bulanan' => (float) ($data['jatah_bulanan'] ?? 10),
                        'status'        => $data['status'] ?? 'Aktif',
                    ]
                );
                $countWarga++;
            }
        }
        $this->info("   ✅ {$countWarga} warga disinkronkan.");

        // ==========================================
        // 2. SINKRONISASI PERANGKAT
        // ==========================================
        $this->info('🔧 [2/4] Sinkronisasi tabel perangkats...');
        $perangkats = $firebase->get('perangkats');
        $countPerangkat = 0;

        if ($perangkats) {
            foreach ($perangkats as $idAlat => $data) {
                Perangkat::updateOrCreate(
                    ['id_alat' => (string) $idAlat],
                    [
                        'sisa_stok_beras' => (float) ($data['sisa_stok_beras'] ?? 0),
                        'persentase_stok' => (float) ($data['persentase_stok'] ?? 0),
                        'status_alat'     => $data['status_alat'] ?? 'Offline',
                        'last_ping'       => isset($data['last_ping']) ? Carbon::parse($data['last_ping']) : null,
                    ]
                );
                $countPerangkat++;
            }
        }
        $this->info("   ✅ {$countPerangkat} perangkat disinkronkan.");

        // ==========================================
        // 3. SINKRONISASI JATAH WARGA
        // ==========================================
        $this->info('📅 [3/4] Sinkronisasi tabel jatah_wargas...');
        $jatahAll = $firebase->get('jatah_wargas');
        $countJatah = 0;

        if ($jatahAll) {
            foreach ($jatahAll as $uid => $periodes) {
                // Skip jika warga tidak ada di MySQL (data orphan)
                if (!Warga::where('uid_kartu', (string) $uid)->exists()) {
                    $this->warn("   ⚠️  Skip jatah untuk UID {$uid} (warga tidak ditemukan)");
                    continue;
                }

                foreach ($periodes as $periode => $jatah) {
                    JatahWarga::updateOrCreate(
                        [
                            'uid_kartu'     => (string) $uid,
                            'periode_bulan' => (string) $periode,
                        ],
                        [
                            'jumlah_kg'    => (float) ($jatah['jumlah_kg'] ?? 10),
                            'status'       => $jatah['status'] ?? 'Belum Diambil',
                            'diambil_pada' => isset($jatah['diambil_pada']) ? Carbon::parse($jatah['diambil_pada']) : null,
                        ]
                    );
                    $countJatah++;
                }
            }
        }
        $this->info("   ✅ {$countJatah} record jatah disinkronkan.");

        // ==========================================
        // 4. SINKRONISASI TRANSAKSI
        // ==========================================
        $this->info('💰 [4/4] Sinkronisasi tabel transaksis...');
        $transaksis = $firebase->get('transaksis');
        $countTransaksi = 0;

        if ($transaksis) {
            foreach ($transaksis as $key => $data) {
                // Cek apakah transaksi sudah ada (berdasarkan kombinasi uid+waktu+jumlah)
                $createdAt = isset($data['created_at']) ? Carbon::parse($data['created_at']) : now();

                $exists = Transaksi::where('uid_kartu', (string) ($data['uid_kartu'] ?? ''))
                    ->where('created_at', $createdAt)
                    ->where('jumlah_diambil', (float) ($data['jumlah_diambil'] ?? 0))
                    ->exists();

                if (!$exists) {
                    Transaksi::create([
                        'uid_kartu'      => (string) ($data['uid_kartu'] ?? ''),
                        'nik'            => $data['nik'] ?? '',
                        'jumlah_diambil' => (float) ($data['jumlah_diambil'] ?? 0),
                        'keterangan'     => $data['keterangan'] ?? null,
                        'waktu_ambil'    => $createdAt,
                        'created_at'     => $createdAt,
                        'updated_at'     => $createdAt,
                    ]);
                    $countTransaksi++;
                }
            }
        }
        $this->info("   ✅ {$countTransaksi} transaksi disinkronkan.");

        // ==========================================
        // RINGKASAN
        // ==========================================
        $this->newLine();
        $this->info('🎉 Sinkronisasi selesai!');
        $this->table(
            ['Tabel', 'Jumlah Record'],
            [
                ['wargas', $countWarga],
                ['perangkats', $countPerangkat],
                ['jatah_wargas', $countJatah],
                ['transaksis', $countTransaksi],
            ]
        );

        return 0;
    }
}
