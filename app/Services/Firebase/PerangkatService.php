<?php

namespace App\Services\Firebase;

use Carbon\Carbon;

class PerangkatService
{
    protected FirebaseService $firebase;
    protected string $path = 'perangkats';

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    /**
     * Cari perangkat berdasarkan ID alat (primary key)
     */
    public function findById(string $idAlat): ?array
    {
        $snapshot = $this->firebase->getReference("{$this->path}/{$idAlat}")->getSnapshot();

        if (!$snapshot->exists()) {
            return null;
        }

        $data = $snapshot->getValue();
        $data['id_alat'] = $idAlat;
        return $data;
    }

    /**
     * Ambil semua perangkat + hitung status online/offline
     */
    public function getAll(): array
    {
        $snapshot = $this->firebase->getReference($this->path)->getSnapshot();

        if (!$snapshot->exists()) {
            return [];
        }

        $perangkats = [];
        foreach ($snapshot->getValue() as $id => $data) {
            $data['id_alat'] = $id;

            // Hitung status: jika updated_at > 60 detik lalu => Offline
            if (isset($data['updated_at'])) {
                $lastUpdate = Carbon::createFromTimestamp($data['updated_at']);
                $isOnline = $lastUpdate->diffInSeconds(now()) < 60;
                $data['status_alat'] = $isOnline ? 'Online' : 'Offline';
                $data['updated_at_human'] = $lastUpdate->diffForHumans();
            } else {
                $data['status_alat'] = 'Offline';
                $data['updated_at_human'] = 'Belum pernah aktif';
            }

            $perangkats[] = $data;
        }

        return $perangkats;
    }

    /**
     * Buat perangkat baru
     */
    public function create(string $idAlat, array $data): void
    {
        $data['updated_at'] = now()->timestamp;
        $this->firebase->getReference("{$this->path}/{$idAlat}")->set($data);
    }

    /**
     * Update data perangkat
     */
    public function update(string $idAlat, array $data): void
    {
        $this->firebase->getReference("{$this->path}/{$idAlat}")->update($data);
    }

    /**
     * Update stok dan timestamp (untuk heartbeat dari ESP32)
     */
    public function updateStok(string $idAlat, float $sisaStok, float $persentase): bool
    {
        $perangkat = $this->findById($idAlat);

        if (!$perangkat) {
            return false;
        }

        $this->update($idAlat, [
            'sisa_stok_beras' => $sisaStok,
            'persentase_stok' => $persentase,
            'status_alat' => 'Online',
            'updated_at' => now()->timestamp, // Unix timestamp untuk perbandingan
        ]);

        return true;
    }
}
