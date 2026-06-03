<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use App\Models\Warga;
use App\Models\Transaksi;
use App\Models\Perangkat;
use App\Models\JatahWarga;
use App\Services\Firebase\FirebaseService;

class DashboardController extends Controller
{
    /**
     * =====================================================================
     * DASHBOARD UTAMA
     * =====================================================================
     * Query MySQL lokal — total waktu estimasi < 50ms (vs ~5.5s sebelumnya)
     */
    public function index()
    {
        // Pastikan seluruh warga punya jatah untuk bulan ini (Lazy) — MySQL lokal
        JatahWarga::pastikanJatahBulanIniAda();

        $bulanIni  = Carbon::now()->month;
        $tahunIni  = Carbon::now()->year;
        $bulanLalu = Carbon::now()->subMonth()->month;
        $tahunLalu = Carbon::now()->subMonth()->year;

        return Inertia::render('Dashboard', [
            'warga'          => Warga::all(),
            'perangkat'      => Perangkat::all(),
            'totalBeras'     => Transaksi::sum('jumlah_diambil'),
            'berasBulanIni'  => Transaksi::byMonth($bulanIni, $tahunIni)->sum('jumlah_diambil'),
            'berasBulanLalu' => Transaksi::byMonth($bulanLalu, $tahunLalu)->sum('jumlah_diambil'),
            'transaksi'      => Transaksi::orderByDesc('created_at')->limit(10)->get(),
        ]);
    }

    /**
     * =====================================================================
     * HALAMAN DATA WARGA
     * =====================================================================
     * Menggunakan Eloquent eager loading — 1 query utama + 2 subquery relasi
     * (vs 3x HTTP call ke Firebase + loop manual di PHP sebelumnya)
     */
    public function dataWarga()
    {
        // Pastikan jatah bulan ini ada — MySQL lokal
        JatahWarga::pastikanJatahBulanIniAda();

        // Eager load relasi jatah_warga dan hitung agregat transaksi dalam 1 query
        $wargas = Warga::with('jatah_warga')
            ->withSum('transaksi', 'jumlah_diambil')
            ->get()
            ->map(function (Warga $warga) {
                // Hitung total jatah "Belum Diambil"
                $totalBelumDiambil = $warga->jatah_warga
                    ->where('status', 'Belum Diambil')
                    ->sum('jumlah_kg');

                return array_merge($warga->toArray(), [
                    'transaksi_sum_jumlah_diambil' => (float) ($warga->transaksi_sum_jumlah_diambil ?? 0),
                    'total_belum_diambil' => (float) $totalBelumDiambil,
                ]);
            });

        return Inertia::render('DataWarga', [
            'warga'     => $wargas,
            'perangkat' => Perangkat::all(),
        ]);
    }

    /**
     * =====================================================================
     * HALAMAN GRAFIK LAPORAN
     * =====================================================================
     * Menggunakan aggregate query MySQL — sangat cepat per tanggal
     */
    public function grafik()
    {
        Carbon::setLocale('id');

        $labelsHarian    = [];
        $distribusiHarian = [];

        for ($i = 6; $i >= 0; $i--) {
            $date              = Carbon::now()->subDays($i);
            $labelsHarian[]    = $date->isoFormat('dddd');
            $distribusiHarian[] = Transaksi::byDate($date->format('Y-m-d'))->sum('jumlah_diambil');
        }

        return Inertia::render('Grafik', [
            'warga'            => Warga::all(),
            'perangkat'        => Perangkat::all(),
            'labelsHarian'     => $labelsHarian,
            'distribusiHarian' => $distribusiHarian,
        ]);
    }

    /**
     * =====================================================================
     * CRUD WARGA (Dual-Write: MySQL + Firebase)
     * =====================================================================
     * Write ke MySQL (primer) + Firebase (sekunder, agar ESP32 tetap bisa baca)
     */
    public function storeWarga(Request $request)
    {
        $validated = $request->validate([
            'nik'           => 'required|string|max:16|unique:wargas,nik',
            'uid_kartu'     => 'required|unique:wargas,uid_kartu',
            'nama'          => 'required|string|max:255',
            'alamat'        => 'required|string',
            'pin'           => 'required|string|min:4|max:4',
            'jatah_ini'     => 'required|numeric|min:0.1',
            'status'        => 'required|string|in:Aktif,Nonaktif',
        ]);

        $validated['uid_kartu'] = (string) $validated['uid_kartu'];

        // 1. Simpan ke MySQL (primer)
        $warga = Warga::create([
            'uid_kartu'      => $validated['uid_kartu'],
            'nik'            => $validated['nik'],
            'nama'           => $validated['nama'],
            'alamat'         => $validated['alamat'],
            'pin'            => $validated['pin'],
            'jatah_bulanan'  => (float) $validated['jatah_ini'],
            'status'         => $validated['status'],
        ]);

        // 2. Dual-write ke Firebase (agar ESP32 tetap bisa baca)
        $this->syncWargaToFirebase($validated['uid_kartu'], [
            'nik'           => $validated['nik'],
            'nama'          => $validated['nama'],
            'alamat'        => $validated['alamat'],
            'pin'           => $validated['pin'],
            'jatah_bulanan' => (float) $validated['jatah_ini'],
            'status'        => $validated['status'],
        ]);

        // Generate jatah bulan ini untuk warga baru
        JatahWarga::pastikanJatahBulanIniAda();

        return redirect()->back();
    }

    public function updateWarga(Request $request, string $uid)
    {
        $validated = $request->validate([
            'nik'       => 'required|string|max:16',
            'uid_kartu' => 'required',
            'nama'      => 'required|string|max:255',
            'alamat'    => 'required|string',
            'pin'       => 'required|string|min:4|max:4',
            'jatah_ini' => 'required|numeric|min:0.1',
            'status'    => 'required|string|in:Aktif,Nonaktif',
        ]);

        $validated['uid_kartu'] = (string) $validated['uid_kartu'];

        // Validasi unik NIK (kecuali diri sendiri)
        $nikExists = Warga::where('nik', $validated['nik'])
            ->where('uid_kartu', '!=', $uid)
            ->exists();
        if ($nikExists) {
            return back()->withErrors(['nik' => 'NIK sudah terdaftar.']);
        }

        // Validasi unik UID jika diubah
        if ($validated['uid_kartu'] !== $uid) {
            if (Warga::where('uid_kartu', $validated['uid_kartu'])->exists()) {
                return back()->withErrors(['uid_kartu' => 'UID kartu sudah terdaftar.']);
            }
        }

        $warga = Warga::findOrFail($uid);

        $dataUpdate = [
            'nik'           => $validated['nik'],
            'nama'          => $validated['nama'],
            'alamat'        => $validated['alamat'],
            'pin'           => $validated['pin'],
            'jatah_bulanan' => (float) $validated['jatah_ini'],
            'status'        => $validated['status'],
        ];

        // Jika UID berubah, kita perlu update relasi
        if ($validated['uid_kartu'] !== $uid) {
            // Update UID di jatah_wargas dan transaksis (cascade via foreign key)
            // MySQL foreign key dengan ON UPDATE CASCADE sudah menangani ini
            $warga->uid_kartu = $validated['uid_kartu'];
        }

        $warga->fill($dataUpdate);
        $warga->save();

        // Dual-write ke Firebase
        if ($validated['uid_kartu'] !== $uid) {
            // Jika UID berubah: hapus node lama, buat node baru
            $this->deleteWargaFromFirebase($uid);
        }
        $this->syncWargaToFirebase($validated['uid_kartu'], $dataUpdate);

        return redirect()->back();
    }

    public function destroyWarga(string $uid)
    {
        // 1. Hapus dari MySQL (cascade akan menghapus transaksi & jatah juga)
        Warga::where('uid_kartu', $uid)->delete();

        // 2. Hapus dari Firebase
        $this->deleteWargaFromFirebase($uid);

        return redirect()->back();
    }

    public function tambahJatah(Request $request, string $uid)
    {
        $validated = $request->validate([
            'periode_bulan' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'jumlah_kg'     => 'required|numeric|min:0.1',
        ]);

        // MySQL: updateOrCreate (unique constraint pada uid+periode)
        JatahWarga::updateOrCreate(
            [
                'uid_kartu'     => $uid,
                'periode_bulan' => $validated['periode_bulan'],
            ],
            [
                'jumlah_kg' => (float) $validated['jumlah_kg'],
                'status'    => 'Belum Diambil',
            ]
        );

        // Dual-write ke Firebase
        $this->syncJatahToFirebase($uid, $validated['periode_bulan'], [
            'jumlah_kg'    => (float) $validated['jumlah_kg'],
            'status'       => 'Belum Diambil',
            'diambil_pada' => null,
            'created_at'   => now()->toIso8601String(),
        ]);

        return redirect()->back();
    }

    public function hapusJatah(string $uid, string $periode)
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
            return back()->withErrors(['message' => 'Format periode tidak valid.']);
        }

        // MySQL
        JatahWarga::where('uid_kartu', $uid)
            ->where('periode_bulan', $periode)
            ->delete();

        // Firebase
        $this->deleteJatahFromFirebase($uid, $periode);

        return redirect()->back();
    }

    // =====================================================================
    // HELPER: Dual-Write ke Firebase (agar ESP32 tetap bisa baca)
    // =====================================================================

    private function syncWargaToFirebase(string $uid, array $data): void
    {
        try {
            $firebase = new FirebaseService();
            $firebase->set("wargas/{$uid}", $data);
        } catch (\Exception $e) {
            \Log::warning("Dual-write Firebase gagal (warga): " . $e->getMessage());
            // Tidak throw — MySQL sudah tersimpan sebagai sumber utama
        }
    }

    private function deleteWargaFromFirebase(string $uid): void
    {
        try {
            $firebase = new FirebaseService();
            $firebase->delete("wargas/{$uid}");
            $firebase->delete("jatah_wargas/{$uid}");
        } catch (\Exception $e) {
            \Log::warning("Dual-write Firebase gagal (hapus warga): " . $e->getMessage());
        }
    }

    private function syncJatahToFirebase(string $uid, string $periode, array $data): void
    {
        try {
            $firebase = new FirebaseService();
            $firebase->set("jatah_wargas/{$uid}/{$periode}", $data);
        } catch (\Exception $e) {
            \Log::warning("Dual-write Firebase gagal (jatah): " . $e->getMessage());
        }
    }

    private function deleteJatahFromFirebase(string $uid, string $periode): void
    {
        try {
            $firebase = new FirebaseService();
            $firebase->delete("jatah_wargas/{$uid}/{$periode}");
        } catch (\Exception $e) {
            \Log::warning("Dual-write Firebase gagal (hapus jatah): " . $e->getMessage());
        }
    }
}