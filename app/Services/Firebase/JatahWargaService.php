<?php

namespace App\Services\Firebase;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Service untuk operasi data JatahWarga di Firebase Realtime Database.
 * Menggantikan Eloquent Model JatahWarga + method pastikanJatahBulanIniAda().
 * 
 * Struktur Firebase:
 * jatah_wargas/
 *   {uid_kartu}/
 *     {periode_bulan}/       <- contoh: "2026-05"
 *       jumlah_kg: int
 *       status: "Belum Diambil"|"Sudah Diambil"
 *       diambil_pada: string|null
 *       created_at: string
 */
class JatahWargaService
{
    protected FirebaseService $firebase;
    protected WargaService $wargaService;
    protected string $path = 'jatah_wargas';

    public function __construct(FirebaseService $firebase, WargaService $wargaService)
    {
        $this->firebase = $firebase;
        $this->wargaService = $wargaService;
    }

    /**
     * Ambil semua jatah milik satu warga (array periode => data)
     */
    public function milikWarga(string $uid): array
    {
        $data = $this->firebase->get("{$this->path}/{$uid}");
        if (!$data) return [];

        // Tambahkan periode_bulan ke dalam setiap item
        $result = [];
        foreach ($data as $periode => $jatah) {
            $result[] = array_merge($jatah, [
                'uid_kartu' => $uid,
                'periode_bulan' => $periode,
            ]);
        }
        return $result;
    }

    /**
     * Ambil jatah warga untuk bulan tertentu
     */
    public function getJatah(string $uid, string $periode): ?array
    {
        $data = $this->firebase->get("{$this->path}/{$uid}/{$periode}");
        if (!$data) return null;
        return array_merge($data, ['uid_kartu' => $uid, 'periode_bulan' => $periode]);
    }

    /**
     * Hitung total jatah "Belum Diambil" dari bulan LALU
     */
    public function totalJatahLalu(string $uid): float
    {
        $bulanIni = Carbon::now()->format('Y-m');
        $semua = $this->milikWarga($uid);
        $total = 0.0;
        foreach ($semua as $jatah) {
            if ($jatah['status'] === 'Belum Diambil' && $jatah['periode_bulan'] !== $bulanIni) {
                $total += (float)($jatah['jumlah_kg'] ?? 0);
            }
        }
        return $total;
    }

    /**
     * Hitung total jatah "Belum Diambil" bulan INI
     */
    public function totalJatahIni(string $uid): float
    {
        $bulanIni = Carbon::now()->format('Y-m');
        $jatah = $this->getJatah($uid, $bulanIni);
        if (!$jatah || $jatah['status'] !== 'Belum Diambil') return 0.0;
        return (float)($jatah['jumlah_kg'] ?? 0);
    }

    /**
     * Tandai semua jatah LALU sebagai "Sudah Diambil"
     */
    public function ambilJatahLalu(string $uid): void
    {
        $bulanIni = Carbon::now()->format('Y-m');
        $semua = $this->milikWarga($uid);
        foreach ($semua as $jatah) {
            if ($jatah['status'] === 'Belum Diambil' && $jatah['periode_bulan'] !== $bulanIni) {
                $this->firebase->update(
                    "{$this->path}/{$uid}/{$jatah['periode_bulan']}",
                    ['status' => 'Sudah Diambil', 'diambil_pada' => now()->toIso8601String()]
                );
            }
        }
    }

    /**
     * Tandai jatah bulan INI sebagai "Sudah Diambil"
     */
    public function ambilJatahIni(string $uid): void
    {
        $bulanIni = Carbon::now()->format('Y-m');
        $this->firebase->update(
            "{$this->path}/{$uid}/{$bulanIni}",
            ['status' => 'Sudah Diambil', 'diambil_pada' => now()->toIso8601String()]
        );
    }

    /**
     * Pastikan semua warga aktif sudah punya jatah bulan ini.
     * Hanya dieksekusi SEKALI per bulan menggunakan cache flag.
     * Selebihnya langsung return tanpa menyentuh Firebase.
     */
    public function pastikanJatahBulanIniAda(): void
    {
        $bulanIni = Carbon::now()->format('Y-m');
        $cacheKey = "jatah_check_{$bulanIni}";

        // Jika sudah dijalankan bulan ini, skip (tidak perlu ke Firebase sama sekali)
        if (Cache::has($cacheKey)) {
            return;
        }

        $wargaAktif = $this->wargaService->allAktif();

        foreach ($wargaAktif as $warga) {
            $uid = $warga['uid_kartu'];
            $existing = $this->firebase->get("{$this->path}/{$uid}/{$bulanIni}");

            if (!$existing) {
                $this->firebase->set("{$this->path}/{$uid}/{$bulanIni}", [
                    'jumlah_kg' => (float)($warga['jatah_bulanan'] ?? 10),
                    'status' => 'Belum Diambil',
                    'diambil_pada' => null,
                    'created_at' => now()->toIso8601String(),
                ]);
            }
        }

        // Simpan flag cache sampai akhir bulan ini
        $sisaDetikBulanIni = Carbon::now()->endOfMonth()->diffInSeconds(Carbon::now());
        Cache::put($cacheKey, true, $sisaDetikBulanIni);
    }

    /**
     * Ambil semua jatah dari semua warga (untuk dashboard)
     */
    public function allWithWarga(): array
    {
        $semuaJatah = $this->firebase->get($this->path);
        if (!$semuaJatah) return [];

        $result = [];
        foreach ($semuaJatah as $uid => $periodes) {
            foreach ($periodes as $periode => $jatah) {
                $result[] = array_merge($jatah, [
                    'uid_kartu' => $uid,
                    'periode_bulan' => $periode,
                ]);
            }
        }
        return $result;
    }

    /**
     * Total seluruh beras yang pernah didistribusikan (dari transaksi, bukan jatah)
     * Ini hanya helper untuk menghitung dari tabel jatah yg sudah diambil
     */
    public function totalBerasSudahDiambil(): float
    {
        $all = $this->allWithWarga();
        $total = 0.0;
        foreach ($all as $jatah) {
            if (($jatah['status'] ?? '') === 'Sudah Diambil') {
                $total += (float)($jatah['jumlah_kg'] ?? 0);
            }
        }
        return $total;
    }

    /**
     * Tambah/Ubah jatah warga secara manual untuk periode tertentu
     */
    public function tambahJatahManual(string $uid, string $periode, float $jumlahKg): void
    {
        $this->firebase->set("{$this->path}/{$uid}/{$periode}", [
            'jumlah_kg' => $jumlahKg,
            'status' => 'Belum Diambil',
            'diambil_pada' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Hapus jatah warga untuk periode tertentu
     */
    public function hapusJatahManual(string $uid, string $periode): void
    {
        $this->firebase->delete("{$this->path}/{$uid}/{$periode}");
    }
}

