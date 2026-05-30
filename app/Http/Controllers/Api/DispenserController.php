<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Firebase\WargaService;
use App\Services\Firebase\TransaksiService;
use App\Services\Firebase\PerangkatService;

class DispenserController extends Controller
{
    protected WargaService $wargaService;
    protected TransaksiService $transaksiService;
    protected PerangkatService $perangkatService;

    public function __construct(
        WargaService $wargaService,
        TransaksiService $transaksiService,
        PerangkatService $perangkatService
    ) {
        $this->wargaService = $wargaService;
        $this->transaksiService = $transaksiService;
        $this->perangkatService = $perangkatService;
    }

    // 1. Fungsi saat warga TAP e-KTP
    public function cekWarga(Request $request)
    {
        $request->validate(['uid_kartu' => 'required|string']);

        $warga = $this->wargaService->findByUid($request->uid_kartu);

        if (!$warga) {
            return response()->json(['status' => 'error', 'message' => 'e-KTP tidak terdaftar.'], 404);
        }

        if ($warga['status'] !== 'Aktif') {
            return response()->json(['status' => 'error', 'message' => 'Kartu tidak aktif.'], 403);
        }

        // Kirim data sesuai alur baru (Step 4 & 5)
        return response()->json([
            'status' => 'success',
            'data' => [
                'nama' => $warga['nama'],
                'pin' => $warga['pin'],
                'jatah_lalu' => $warga['jatah_lalu'] ?? 0, // Kirim jatah bulan lalu
                'jatah_ini' => $warga['jatah_ini'] ?? 0    // Kirim jatah bulan ini
            ]
        ], 200);
    }

    // 2. Fungsi setelah beras berhasil dituang (Step 9)
    public function catatTransaksi(Request $request)
    {
        $request->validate([
            'uid_kartu' => 'required|string',
            'jumlah' => 'required|numeric', // diubah dari jumlah_diambil agar sinkron dengan ESP32
            'id_alat' => 'required|string',
            'tipe' => 'required|string' // "lalu" atau "ini"
        ]);

        $warga = $this->wargaService->findByUid($request->uid_kartu);

        if ($warga) {
            // Logika Update Jatah (Step 9)
            if ($request->tipe == 'lalu') {
                $this->wargaService->update($request->uid_kartu, ['jatah_lalu' => 0]);
            } else {
                $this->wargaService->update($request->uid_kartu, ['jatah_ini' => 0]);
            }

            // Potong stok di Firebase Perangkat
            $perangkat = $this->perangkatService->findById($request->id_alat);
            if ($perangkat) {
                $sisaBaru = ($perangkat['sisa_stok_beras'] ?? 0) - $request->jumlah;
                $persentaseBaru = ($sisaBaru / 100) * 100;
                $this->perangkatService->update($request->id_alat, [
                    'sisa_stok_beras' => $sisaBaru,
                    'persentase_stok' => $persentaseBaru,
                ]);
            }

            // Catat log transaksi di Firebase
            $this->transaksiService->create([
                'uid_kartu' => $warga['uid_kartu'],
                'nik' => $warga['nik'],
                'jumlah_diambil' => $request->jumlah,
                'keterangan' => 'Ambil jatah bulan ' . $request->tipe // Catat bulannya
            ]);

            return response()->json(['status' => 'success'], 200);
        }

        return response()->json(['status' => 'error', 'message' => 'Warga tidak ditemukan.'], 404);
    }

    // 3. Fungsi Update Stok (Heartbeat)
    public function updateStokPerangkat(Request $request)
    {
        $request->validate([
            'id_alat' => 'required|string',
            'sisa_stok_beras' => 'required|numeric',
            'persentase_stok' => 'required|numeric'
        ]);

        $updated = $this->perangkatService->updateStok(
            $request->id_alat,
            $request->sisa_stok_beras,
            $request->persentase_stok
        );

        if ($updated) {
            return response()->json(['status' => 'success'], 200);
        }

        return response()->json(['status' => 'error', 'message' => 'Alat tidak terdaftar.'], 404);
    }
}