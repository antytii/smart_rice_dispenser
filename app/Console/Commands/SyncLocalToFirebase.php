<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Firebase\FirebaseService;
use App\Models\Warga;
use App\Models\JatahWarga;
use App\Models\Perangkat;
use App\Models\Transaksi;

class SyncLocalToFirebase extends Command
{
    protected $signature = 'firebase:sync-push';
    protected $description = 'Sinkronisasi data dari MySQL lokal ke Firebase Realtime Database (Full Push)';

    public function handle()
    {
        $this->info('🚀 Memulai sinkronisasi MySQL → Firebase...');

        try {
            $firebase = new FirebaseService();
        } catch (\Exception $e) {
            $this->error('❌ Gagal terhubung ke Firebase: ' . $e->getMessage());
            return 1;
        }

        // 1. Sync Warga
        $this->info('📋 Sync Wargas...');
        $wargas = Warga::all();
        foreach ($wargas as $w) {
            $firebase->set("wargas/{$w->uid_kartu}", [
                'nik'           => $w->nik,
                'nama'          => $w->nama,
                'alamat'        => $w->alamat,
                'pin'           => $w->pin,
                'jatah_bulanan' => (float) $w->jatah_bulanan,
                'status'        => $w->status,
            ]);
        }

        // 2. Sync Jatah Warga
        $this->info('📅 Sync Jatah Wargas...');
        $jatahs = JatahWarga::all();
        foreach ($jatahs as $j) {
            $firebase->set("jatah_wargas/{$j->uid_kartu}/{$j->periode_bulan}", [
                'jumlah_kg'    => (float) $j->jumlah_kg,
                'status'       => $j->status,
                'diambil_pada' => $j->diambil_pada ? $j->diambil_pada->toIso8601String() : null,
                'created_at'   => $j->created_at->toIso8601String(),
            ]);
        }

        $this->info('✅ Sinkronisasi Selesai!');
        return 0;
    }
}
