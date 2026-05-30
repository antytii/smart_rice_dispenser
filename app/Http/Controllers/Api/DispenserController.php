<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Firebase\WargaService;
use App\Services\Firebase\TransaksiService;
use App\Services\Firebase\PerangkatService;
use App\Services\Firebase\JatahWargaService;
use App\Services\Firebase\FirebaseService;

class DispenserController extends Controller
{
    protected WargaService $wargaService;
    protected TransaksiService $transaksiService;
    protected PerangkatService $perangkatService;
    protected JatahWargaService $jatahWargaService;

    public function __construct()
    {
        $firebase = new FirebaseService();
        $this->wargaService = new WargaService($firebase);
        $this->transaksiService = new TransaksiService($firebase);
        $this->perangkatService = new PerangkatService($firebase);
        $this->jatahWargaService = new JatahWargaService($firebase, $this->wargaService);
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

        // Pastikan jatah bulan ini sudah ter-generate (Lazy)
        $this->jatahWargaService->pastikanJatahBulanIniAda();

        $uid = $warga['uid_kartu'];

        // Jatah dari bulan-bulan lalu yang belum diambil
        $jatahLalu = $this->jatahWargaService->totalJatahLalu($uid);

        // Jatah khusus bulan ini
        $jatahIni = $this->jatahWargaService->totalJatahIni($uid);

        $totalJatah = $jatahLalu + $jatahIni;

        // Pengecekan Stok Beras di Perangkat
        $perangkat = $this->perangkatService->first();
        if ($perangkat && $perangkat['sisa_stok_beras'] < $totalJatah && $totalJatah > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stok Mesin Habis' // Tepat 16 karakter
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'nama'       => $warga['nama'],
                'pin'        => $warga['pin'],
                'jatah_lalu' => (float) $jatahLalu,
                'jatah_ini'  => (float) $jatahIni,
            ]
        ], 200);
    }

    // 2. Fungsi setelah beras berhasil dituang
    public function catatTransaksi(Request $request)
    {
        $request->validate([
            'uid_kartu' => 'required|string',
            'jumlah'    => 'required|numeric',
            'id_alat'   => 'required|string',
            'tipe'      => 'required|string', // "lalu" atau "ini"
        ]);

        $warga = $this->wargaService->findByUid($request->uid_kartu);

        if (!$warga) {
            return response()->json(['status' => 'error', 'message' => 'Warga tidak ditemukan.'], 404);
        }

        $uid = $warga['uid_kartu'];

        // Update status jatah
        if ($request->tipe === 'lalu') {
            $this->jatahWargaService->ambilJatahLalu($uid);
        } else {
            $this->jatahWargaService->ambilJatahIni($uid);
        }

        // Kurangi stok di Perangkat
        $this->perangkatService->kurangiStok($request->id_alat, (float) $request->jumlah);

        // Catat log transaksi
        $this->transaksiService->create([
            'uid_kartu'      => $uid,
            'nik'            => $warga['nik'],
            'jumlah_diambil' => (float) $request->jumlah,
            'keterangan'     => 'Ambil jatah bulan ' . $request->tipe,
        ]);

        return response()->json(['status' => 'success'], 200);
    }

    // 3. Fungsi Update Stok (Heartbeat dari ESP32)
    public function updateStokPerangkat(Request $request)
    {
        $request->validate([
            'id_alat'         => 'required|string',
            'sisa_stok_beras' => 'required|numeric',
            'persentase_stok' => 'required|numeric',
        ]);

        $updated = $this->perangkatService->updateStok(
            $request->id_alat,
            (float) $request->sisa_stok_beras,
            (float) $request->persentase_stok
        );

        if (!$updated) {
            return response()->json(['status' => 'error', 'message' => 'Alat tidak terdaftar.'], 404);
        }

        return response()->json(['status' => 'success'], 200);
    }
}