<?php

namespace App\Services\Firebase;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Service untuk operasi Transaksi di Firebase Realtime Database.
 * Menggantikan Eloquent Model Transaksi.
 * 
 * Struktur Firebase:
 * transaksis/
 *   {push_key}/
 *     uid_kartu: string
 *     nik: string
 *     jumlah_diambil: float
 *     keterangan: string
 *     created_at: string (ISO8601)
 */
class TransaksiService
{
    protected FirebaseService $firebase;
    protected string $path = 'transaksis';
    protected ?array $cacheAll = null;
    protected string $cacheKey = 'firebase_transaksis';
    protected int $cacheTtl = 300; // 5 menit

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    /**
     * Catat transaksi baru
     */
    public function create(array $data): string
    {
        $this->cacheAll = null;
        Cache::forget($this->cacheKey); // Invalidate Laravel cache
        $data['created_at'] = Carbon::now()->toIso8601String();
        return $this->firebase->push($this->path, $data);
    }

    /**
     * Ambil semua transaksi (array, diurutkan by created_at desc)
     */
    public function all(): array
    {
        // In-memory cache per request
        if ($this->cacheAll !== null) {
            return $this->cacheAll;
        }

        // Laravel cache lintas request (5 menit)
        $this->cacheAll = Cache::remember($this->cacheKey, $this->cacheTtl, function () {
            $data = $this->firebase->get($this->path);
            if (!$data) return [];

            $result = [];
            foreach ($data as $key => $item) {
                $result[] = array_merge($item, ['id_transaksi' => $key]);
            }

            usort($result, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            return $result;
        });

        return $this->cacheAll;
    }

    /**
     * Ambil N transaksi terbaru
     */
    public function latest(int $limit = 10): array
    {
        return array_slice($this->all(), 0, $limit);
    }

    /**
     * Total beras yang pernah didistribusikan
     */
    public function sum(): float
    {
        $all = $this->all();
        return array_sum(array_column($all, 'jumlah_diambil'));
    }

    /**
     * Total beras pada bulan tertentu
     */
    public function sumByMonth(int $month, int $year): float
    {
        $all = $this->all();
        $total = 0;
        foreach ($all as $t) {
            $date = Carbon::parse($t['created_at'] ?? '');
            if ($date->month === $month && $date->year === $year) {
                $total += (float)($t['jumlah_diambil'] ?? 0);
            }
        }
        return $total;
    }

    /**
     * Total beras pada tanggal tertentu (untuk grafik harian)
     */
    public function sumByDate(string $date): float
    {
        $all = $this->all();
        $total = 0;
        foreach ($all as $t) {
            if (Carbon::parse($t['created_at'] ?? '')->format('Y-m-d') === $date) {
                $total += (float)($t['jumlah_diambil'] ?? 0);
            }
        }
        return $total;
    }
}
