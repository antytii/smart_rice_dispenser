<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Warga;
use App\Models\Transaksi;
use App\Models\Perangkat;
use App\Models\JatahWarga;
use App\Services\Firebase\FirebaseService;
use Carbon\Carbon;

class DispenserController extends Controller
{
    // 1. Fungsi saat warga TAP e-KTP
    public function cekWarga(Request $request)
    {
        $request->validate(['uid_kartu' => 'required|string']);

        // Baca dari MySQL (cepat, < 5ms)
        $warga = Warga::where('uid_kartu', $request->uid_kartu)->first();

        if (!$warga) {
            return response()->json(['status' => 'error', 'message' => 'e-KTP tidak terdaftar.'], 404);
        }

        if ($warga->status !== 'Aktif') {
            return response()->json(['status' => 'error', 'message' => 'Kartu tidak aktif.'], 403);
        }

        // Pastikan jatah bulan ini sudah ter-generate (Lazy)
        JatahWarga::pastikanJatahBulanIniAda();

        $uid = $warga->uid_kartu;
        $bulanIni = Carbon::now()->format('Y-m');

        // Jatah dari bulan-bulan lalu yang belum diambil
        $jatahLalu = JatahWarga::where('uid_kartu', $uid)
            ->where('periode_bulan', '!=', $bulanIni)
            ->where('status', 'Belum Diambil')
            ->sum('jumlah_kg');

        // Jatah khusus bulan ini
        $jatahIniRecord = JatahWarga::where('uid_kartu', $uid)
            ->where('periode_bulan', $bulanIni)
            ->where('status', 'Belum Diambil')
            ->first();
        $jatahIni = $jatahIniRecord ? (float) $jatahIniRecord->jumlah_kg : 0.0;

        $totalJatah = $jatahLalu + $jatahIni;

        // Pengecekan Stok Beras di Perangkat
        $perangkat = Perangkat::first();
        if ($perangkat && $perangkat->sisa_stok_beras < $totalJatah && $totalJatah > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stok Mesin Habis' // Tepat 16 karakter
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'nama'       => $warga->nama,
                'pin'        => $warga->pin,
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

        $warga = Warga::where('uid_kartu', $request->uid_kartu)->first();

        if (!$warga) {
            return response()->json(['status' => 'error', 'message' => 'Warga tidak ditemukan.'], 404);
        }

        $uid = $warga->uid_kartu;
        $bulanIni = Carbon::now()->format('Y-m');

        // Update status jatah di MySQL
        if ($request->tipe === 'lalu') {
            // Tandai semua jatah lalu sebagai "Sudah Diambil"
            JatahWarga::where('uid_kartu', $uid)
                ->where('periode_bulan', '!=', $bulanIni)
                ->where('status', 'Belum Diambil')
                ->update([
                    'status' => 'Sudah Diambil',
                    'diambil_pada' => now(),
                ]);
        } else {
            // Tandai jatah bulan ini sebagai "Sudah Diambil"
            JatahWarga::where('uid_kartu', $uid)
                ->where('periode_bulan', $bulanIni)
                ->update([
                    'status' => 'Sudah Diambil',
                    'diambil_pada' => now(),
                ]);
        }

        // Kurangi stok di Perangkat (MySQL)
        $perangkat = Perangkat::where('id_alat', $request->id_alat)->first();
        if ($perangkat) {
            $sisaBaru = max(0, $perangkat->sisa_stok_beras - (float) $request->jumlah);
            $perangkat->update([
                'sisa_stok_beras' => $sisaBaru,
                'persentase_stok' => ($sisaBaru / 1.0) * 100, // Sesuaikan logic kapasitas max
                'last_ping'       => now(),
            ]);
        }

        // Catat log transaksi di MySQL
        Transaksi::create([
            'uid_kartu'      => $uid,
            'nik'            => $warga->nik,
            'jumlah_diambil' => (float) $request->jumlah,
            'keterangan'     => 'Ambil jatah bulan ' . $request->tipe,
        ]);

        // Dual-write ke Firebase (agar realtime listener di dashboard tetap update)
        try {
            $firebase = new FirebaseService();
            
            // Update jatah di Firebase
            $jatahAll = JatahWarga::where('uid_kartu', $uid)->get();
            foreach ($jatahAll as $jatah) {
                $firebase->set("jatah_wargas/{$uid}/{$jatah->periode_bulan}", [
                    'jumlah_kg'    => $jatah->jumlah_kg,
                    'status'       => $jatah->status,
                    'diambil_pada' => $jatah->diambil_pada ? $jatah->diambil_pada->toIso8601String() : null,
                    'created_at'   => $jatah->created_at->toIso8601String(),
                ]);
            }

            // Push transaksi ke Firebase
            $firebase->push('transaksis', [
                'uid_kartu'      => $uid,
                'nik'            => $warga->nik,
                'jumlah_diambil' => (float) $request->jumlah,
                'keterangan'     => 'Ambil jatah bulan ' . $request->tipe,
                'created_at'     => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            \Log::warning("Dual-write Firebase gagal (transaksi): " . $e->getMessage());
        }

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

        // Simpan ke MySQL
        $perangkat = Perangkat::where('id_alat', $request->id_alat)->first();

        if (!$perangkat) {
            return response()->json(['status' => 'error', 'message' => 'Alat tidak terdaftar.'], 404);
        }

        $perangkat->update([
            'sisa_stok_beras' => (float) $request->sisa_stok_beras,
            'persentase_stok' => (float) $request->persentase_stok,
            'status_alat'     => 'Online',
            'last_ping'       => now(),
        ]);

        // Dual-write ke Firebase (sudah dilakukan oleh ESP32 sendiri, jadi ini opsional)
        // ESP32 langsung menulis ke Firebase, jadi kita hanya perlu memastikan MySQL terupdate

        return response()->json(['status' => 'success'], 200);
    }
}