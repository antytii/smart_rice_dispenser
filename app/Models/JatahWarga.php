<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JatahWarga extends Model
{
    use HasFactory;

    protected $fillable = [
        'uid_kartu',
        'periode_bulan',
        'jumlah_kg',
        'status',
        'diambil_pada'
    ];

    protected $casts = [
        'jumlah_kg' => 'float',
        'diambil_pada' => 'datetime',
    ];

    public function warga()
    {
        return $this->belongsTo(Warga::class, 'uid_kartu', 'uid_kartu');
    }

    /**
     * Opsi C yang ditingkatkan (Otomatis Penuh & Malas/Lazy)
     * Dipanggil kapan saja (saat buka halaman admin, atau warga nge-tap).
     * Akan mengecek apakah jatah bulan ini sudah dibuat untuk semua warga aktif.
     * 
     * Menggunakan MySQL — sangat cepat karena semua query lokal (< 5ms).
     */
    public static function pastikanJatahBulanIniAda()
    {
        $bulanIni = now()->format('Y-m'); // Contoh: 2026-06

        // Ambil semua uid warga yang aktif
        $wargaAktif = Warga::where('status', 'Aktif')->get();

        // Ambil uid yang sudah punya jatah di bulan ini
        $sudahPunya = self::where('periode_bulan', $bulanIni)->pluck('uid_kartu')->toArray();

        $dataBaru = [];
        foreach ($wargaAktif as $w) {
            // Jika belum punya jatah bulan ini, kita buatkan!
            if (!in_array($w->uid_kartu, $sudahPunya)) {
                $dataBaru[] = [
                    'uid_kartu' => $w->uid_kartu,
                    'periode_bulan' => $bulanIni,
                    'jumlah_kg' => $w->jatah_bulanan ?? 10, // Default 10 jika kosong
                    'status' => 'Belum Diambil',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Masukkan massal (jika ada)
        if (count($dataBaru) > 0) {
            self::insert($dataBaru);

            // Dual-write ke Firebase agar ESP32 bisa baca jatah baru
            try {
                $firebase = new \App\Services\Firebase\FirebaseService();
                foreach ($dataBaru as $data) {
                    $firebase->set("jatah_wargas/{$data['uid_kartu']}/{$data['periode_bulan']}", [
                        'jumlah_kg'    => (float) $data['jumlah_kg'],
                        'status'       => $data['status'],
                        'diambil_pada' => null,
                        'created_at'   => $data['created_at']->toIso8601String(),
                    ]);
                }
            } catch (\Exception $e) {
                \Log::warning("Dual-write Firebase gagal (jatah otomatis): " . $e->getMessage());
            }
        }
    }
}
