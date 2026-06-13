<?php

namespace App\Services\Firebase;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Service untuk operasi Perangkat di Firebase Realtime Database.
 * Menggantikan Eloquent Model Perangkat.
 * 
 * Struktur Firebase:
 * perangkats/
 *   {id_alat}/
 *     sisa_stok_beras: float
 *     persentase_stok: float
 *     status_alat: "Online"|"Offline"
 *     last_ping: string (ISO8601) <- untuk deteksi offline otomatis
 */
class PerangkatService
{
    protected FirebaseService $firebase;
    protected string $path = 'perangkats';
    protected string $cacheKey = 'firebase_perangkats';
    protected int $cacheTtl = 60; // 60 detik (data IoT sering update)

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    /**
     * Ambil semua perangkat, dengan status_alat dihitung otomatis
     */
    public function all(): array
    {
        return Cache::remember($this->cacheKey, $this->cacheTtl, function () {
            $data = $this->firebase->get($this->path);
            if (!$data) return [];

            $result = [];
            foreach ($data as $idAlat => $item) {
                $result[] = $this->applyStatusLogic(array_merge($item, ['id_alat' => $idAlat]));
            }
            return $result;
        });
    }

    /**
     * Ambil satu perangkat berdasarkan id_alat
     */
    public function find(string $idAlat): ?array
    {
        $data = $this->firebase->get("{$this->path}/{$idAlat}");
        if (!$data) return null;
        return $this->applyStatusLogic(array_merge($data, ['id_alat' => $idAlat]));
    }

    /**
     * Ambil perangkat pertama (asumsi 1 alat)
     */
    public function first(): ?array
    {
        $all = $this->all();
        return count($all) > 0 ? $all[0] : null;
    }

    /**
     * Update stok dan status perangkat (heartbeat dari ESP32)
     */
    public function updateStok(string $idAlat, float $sisaStok, float $persentase): bool
    {
        $existing = $this->firebase->get("{$this->path}/{$idAlat}");
        if (!$existing) return false;

        $this->firebase->update("{$this->path}/{$idAlat}", [
            'sisa_stok_beras' => $sisaStok,
            'persentase_stok' => $persentase,
            'status_alat' => 'Online',
            'last_ping' => Carbon::now()->toIso8601String(),
        ]);
        Cache::forget($this->cacheKey); // Invalidate agar status Online langsung terlihat
        return true;
    }

    /**
     * Kurangi stok setelah transaksi berhasil
     */
    public function kurangiStok(string $idAlat, float $jumlah): void
    {
        $perangkat = $this->firebase->get("{$this->path}/{$idAlat}");
        if (!$perangkat) return;

        $sisaBaru = max(0, (float)($perangkat['sisa_stok_beras'] ?? 0) - $jumlah);
        $persentaseBaru = ($sisaBaru / 100) * 100; // Sesuaikan logic kapasitas max

        $this->firebase->update("{$this->path}/{$idAlat}", [
            'sisa_stok_beras' => $sisaBaru,
            'persentase_stok' => $persentaseBaru,
            'last_ping' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Tambahkan perangkat baru
     */
    public function create(string $idAlat, array $data): void
    {
        $data['last_ping'] = Carbon::now()->toIso8601String();
        $this->firebase->set("{$this->path}/{$idAlat}", $data);
    }

    /**
     * Logic otomatis: jika last_ping > 30 detik lalu, status = Offline
     * (Menggantikan Eloquent Accessor getStatusAlatAttribute)
     */
    private function applyStatusLogic(array $item): array
    {
        if (isset($item['last_ping'])) {
            $lastPing = Carbon::parse($item['last_ping']);
            // Toleransi dinaikkan menjadi 60 detik agar tidak flicker saat heartbeat 5 detik
            if ($lastPing->diffInSeconds(Carbon::now()) >= 10) {
                $item['status_alat'] = 'Offline';
            }
        }
        return $item;
    }
}
