<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Warga;
use App\Models\Transaksi;
use App\Models\Perangkat;
use App\Models\JatahWarga;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FirebaseWebhookController extends Controller
{
    /**
     * Menerima push data dari Firebase Cloud Functions.
     * Endpoint: POST /api/webhook/sensor-data
     * 
     * Firebase Cloud Functions akan mengirim payload dengan format:
     * {
     *   "event": "transaksi_baru" | "perangkat_update" | "jatah_update",
     *   "data": { ... }
     * }
     */
    public function store(Request $request)
    {
        $event = $request->input('event');
        $data  = $request->input('data');

        if (!$event || !$data) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Payload tidak valid. Dibutuhkan field "event" dan "data".'
            ], 422);
        }

        try {
            match ($event) {
                'transaksi_baru'   => $this->handleTransaksiBaru($data),
                'perangkat_update' => $this->handlePerangkatUpdate($data),
                'jatah_update'     => $this->handleJatahUpdate($data),
                default            => throw new \InvalidArgumentException("Event tidak dikenal: {$event}"),
            };

            return response()->json(['status' => 'success'], 200);
        } catch (\Exception $e) {
            Log::error("Webhook error [{$event}]: " . $e->getMessage(), [
                'payload' => $data,
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle event: Transaksi baru dari ESP32 via Firebase
     */
    private function handleTransaksiBaru(array $data): void
    {
        $uid = (string) ($data['uid_kartu'] ?? '');
        $createdAt = isset($data['created_at']) ? Carbon::parse($data['created_at']) : now();

        // Cek duplikasi berdasarkan uid + waktu + jumlah
        $exists = Transaksi::where('uid_kartu', $uid)
            ->where('created_at', $createdAt)
            ->where('jumlah_diambil', (float) ($data['jumlah_diambil'] ?? 0))
            ->exists();

        if ($exists) {
            Log::info("Webhook: Transaksi duplikat, diabaikan.", ['uid' => $uid]);
            return;
        }

        Transaksi::create([
            'uid_kartu'      => $uid,
            'nik'            => $data['nik'] ?? '',
            'jumlah_diambil' => (float) ($data['jumlah_diambil'] ?? 0),
            'keterangan'     => $data['keterangan'] ?? null,
            'waktu_ambil'    => $createdAt,
            'created_at'     => $createdAt,
            'updated_at'     => $createdAt,
        ]);

        Log::info("Webhook: Transaksi baru dicatat.", ['uid' => $uid, 'jumlah' => $data['jumlah_diambil'] ?? 0]);
    }

    /**
     * Handle event: Update stok/status perangkat (heartbeat ESP32)
     */
    private function handlePerangkatUpdate(array $data): void
    {
        $idAlat = (string) ($data['id_alat'] ?? '');
        
        if (!$idAlat) {
            throw new \InvalidArgumentException('id_alat tidak boleh kosong.');
        }

        Perangkat::updateOrCreate(
            ['id_alat' => $idAlat],
            [
                'sisa_stok_beras' => (float) ($data['sisa_stok_beras'] ?? 0),
                'persentase_stok' => (float) ($data['persentase_stok'] ?? 0),
                'status_alat'     => $data['status_alat'] ?? 'Online',
                'last_ping'       => isset($data['last_ping']) ? Carbon::parse($data['last_ping']) : now(),
            ]
        );

        Log::info("Webhook: Perangkat diupdate.", ['id_alat' => $idAlat]);
    }

    /**
     * Handle event: Update status jatah warga (setelah pengambilan beras)
     */
    private function handleJatahUpdate(array $data): void
    {
        $uid     = (string) ($data['uid_kartu'] ?? '');
        $periode = $data['periode_bulan'] ?? '';

        if (!$uid || !$periode) {
            throw new \InvalidArgumentException('uid_kartu dan periode_bulan tidak boleh kosong.');
        }

        JatahWarga::updateOrCreate(
            [
                'uid_kartu'     => $uid,
                'periode_bulan' => $periode,
            ],
            [
                'jumlah_kg'    => (float) ($data['jumlah_kg'] ?? 10),
                'status'       => $data['status'] ?? 'Belum Diambil',
                'diambil_pada' => isset($data['diambil_pada']) ? Carbon::parse($data['diambil_pada']) : null,
            ]
        );

        Log::info("Webhook: Jatah diupdate.", ['uid' => $uid, 'periode' => $periode]);
    }
}
