<?php

namespace App\Services\Firebase;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Service untuk operasi CRUD data Warga di Firebase Realtime Database.
 * Menggantikan Eloquent Model Warga.
 * 
 * Struktur Firebase:
 * wargas/
 *   {uid_kartu}/
 *     nik: string
 *     nama: string
 *     alamat: string
 *     pin: string
 *     jatah_bulanan: int
 *     jatah_lalu: int
 *     jatah_ini: int
 *     status: "Aktif"|"Nonaktif"
 */
class WargaService
{
    protected FirebaseService $firebase;
    protected string $path = 'wargas';
    protected ?array $cacheAll = null;
    protected string $cacheKey = 'firebase_wargas';
    protected int $cacheTtl = 300; // 5 menit

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    /**
     * Ambil semua data warga (return array of arrays, key = uid_kartu)
     */
    public function all(): array
    {
        // Gunakan in-memory cache dulu (per request)
        if ($this->cacheAll !== null) {
            return $this->cacheAll;
        }

        // Lalu coba Laravel cache (lintas request, 5 menit)
        $this->cacheAll = Cache::remember($this->cacheKey, $this->cacheTtl, function () {
            $data = $this->firebase->get($this->path);
            if (!$data) return [];

            return array_map(function ($item, $uid) {
                return array_merge($item, ['uid_kartu' => (string)$uid]);
            }, array_values($data), array_keys($data));
        });

        return $this->cacheAll;
    }

    /**
     * Cari warga berdasarkan uid_kartu
     */
    public function findByUid(string $uid): ?array
    {
        $data = $this->firebase->get("{$this->path}/{$uid}");
        if (!$data) return null;
        return array_merge($data, ['uid_kartu' => (string)$uid]);
    }

    /**
     * Cari warga berdasarkan NIK
     */
    public function findByNik(string $nik): ?array
    {
        $all = $this->firebase->get($this->path);
        if (!$all) return null;
        foreach ($all as $uid => $warga) {
            if (($warga['nik'] ?? '') === $nik) {
                return array_merge($warga, ['uid_kartu' => (string)$uid]);
            }
        }
        return null;
    }

    /**
     * Buat data warga baru
     */
    public function create(array $data): array
    {
        $this->cacheAll = null;
        Cache::forget($this->cacheKey); // Invalidate Laravel cache
        $uid = $data['uid_kartu'];
        unset($data['uid_kartu']); // Key jangan disimpan di dalam value

        $this->firebase->set("{$this->path}/{$uid}", $data);
        return array_merge($data, ['uid_kartu' => $uid]);
    }

    public function update(string $uid, array $data): void
    {
        $this->cacheAll = null;
        Cache::forget($this->cacheKey); // Invalidate Laravel cache

        $newUid = $data['uid_kartu'] ?? $uid;
        unset($data['uid_kartu']); // Jangan simpan key di dalam value

        if ($newUid !== $uid) {
            // 1. Tulis data warga ke path UID baru
            $this->firebase->set("{$this->path}/{$newUid}", $data);

            // 2. Hapus data warga di path UID lama
            $this->firebase->delete("{$this->path}/{$uid}");

            // 3. Pindahkan data jatah_wargas (riwayat bulanan) jika ada
            $jatahData = $this->firebase->get("jatah_wargas/{$uid}");
            if ($jatahData) {
                $this->firebase->set("jatah_wargas/{$newUid}", $jatahData);
                $this->firebase->delete("jatah_wargas/{$uid}");
            }

            // 4. Update seluruh transaksis yang mereferensikan UID lama
            $allTransaksis = $this->firebase->get("transaksis") ?: [];
            foreach ($allTransaksis as $key => $t) {
                if (($t['uid_kartu'] ?? '') === $uid) {
                    $this->firebase->update("transaksis/{$key}", ['uid_kartu' => $newUid]);
                }
            }
        } else {
            // Update biasa jika UID tidak berubah
            $this->firebase->update("{$this->path}/{$uid}", $data);
        }
    }

    /**
     * Hapus warga berdasarkan uid_kartu
     */
    public function delete(string $uid): void
    {
        $this->cacheAll = null;
        Cache::forget($this->cacheKey); // Invalidate Laravel cache
        $this->firebase->delete("{$this->path}/{$uid}");
    }

    /**
     * Ambil semua warga dengan status Aktif
     */
    public function allAktif(): array
    {
        $all = $this->all();
        return array_values(array_filter($all, fn($w) => ($w['status'] ?? '') === 'Aktif'));
    }

    /**
     * Cek apakah NIK sudah digunakan (untuk validasi unique)
     */
    public function nikExists(string $nik, string $excludeUid = ''): bool
    {
        $all = $this->firebase->get($this->path);
        if (!$all) return false;
        foreach ($all as $uid => $warga) {
            if ((string)$uid === (string)$excludeUid) continue;
            if (($warga['nik'] ?? '') === $nik) return true;
        }
        return false;
    }

    /**
     * Cek apakah uid_kartu sudah digunakan
     */
    public function uidExists(string $uid, string $excludeUid = ''): bool
    {
        if ((string)$uid === (string)$excludeUid) return false;
        return $this->firebase->get("{$this->path}/{$uid}") !== null;
    }
}
