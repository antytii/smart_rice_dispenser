<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use App\Services\Firebase\FirebaseService;
use App\Services\Firebase\WargaService;
use App\Services\Firebase\TransaksiService;
use App\Services\Firebase\PerangkatService;
use App\Services\Firebase\JatahWargaService;

class DashboardController extends Controller
{
    protected FirebaseService $firebase;
    protected WargaService $wargaService;
    protected TransaksiService $transaksiService;
    protected PerangkatService $perangkatService;
    protected JatahWargaService $jatahWargaService;

    public function __construct()
    {
        $this->firebase = new FirebaseService();
        $this->wargaService = new WargaService($this->firebase);
        $this->transaksiService = new TransaksiService($this->firebase);
        $this->perangkatService = new PerangkatService($this->firebase);
        $this->jatahWargaService = new JatahWargaService($this->firebase, $this->wargaService);
    }

    public function index()
    {
        // Pastikan seluruh warga punya jatah untuk bulan ini (Lazy)
        $this->jatahWargaService->pastikanJatahBulanIniAda();

        $bulanIni  = Carbon::now()->month;
        $tahunIni  = Carbon::now()->year;
        $bulanLalu = Carbon::now()->subMonth()->month;
        $tahunLalu = Carbon::now()->subMonth()->year;

        return Inertia::render('Dashboard', [
            'warga'          => $this->wargaService->all(),
            'perangkat'      => $this->perangkatService->all(),
            'totalBeras'     => $this->transaksiService->sum(),
            'berasBulanIni'  => $this->transaksiService->sumByMonth($bulanIni, $tahunIni),
            'berasBulanLalu' => $this->transaksiService->sumByMonth($bulanLalu, $tahunLalu),
            'transaksi'      => $this->transaksiService->latest(10),
        ]);
    }

    public function dataWarga()
    {
        // Pastikan jatah bulan ini ada
        $this->jatahWargaService->pastikanJatahBulanIniAda();

        // Ambil semua warga, seluruh jatah, dan seluruh transaksi sekali jalan (1 request per node)
        $wargas = $this->wargaService->all();
        
        $firebase = new FirebaseService();
        $semuaJatah = $firebase->get('jatah_wargas') ?: [];
        $semuaTransaksi = $this->transaksiService->all();

        foreach ($wargas as &$warga) {
            $uid = $warga['uid_kartu'];
            
            // Filter jatah warga lokal dari memory
            $rawJatah = $semuaJatah[$uid] ?? [];
            $jatahList = [];
            foreach ($rawJatah as $periode => $jatah) {
                $jatahList[] = array_merge($jatah, [
                    'uid_kartu' => $uid,
                    'periode_bulan' => $periode,
                ]);
            }

            // Hitung agregat transaksi lokal dari memory
            $transaksiTotal = 0;
            foreach ($semuaTransaksi as $t) {
                if ($t['uid_kartu'] === $uid) {
                    $transaksiTotal += (float)($t['jumlah_diambil'] ?? 0);
                }
            }

            // Hitung total jatah belum diambil lokal dari memory
            $totalBelumDiambil = 0;
            foreach ($jatahList as $j) {
                if ($j['status'] === 'Belum Diambil') {
                    $totalBelumDiambil += (float)($j['jumlah_kg'] ?? 0);
                }
            }

            $warga['jatah_warga'] = $jatahList;
            $warga['transaksi_sum_jumlah_diambil'] = $transaksiTotal;
            $warga['total_belum_diambil'] = $totalBelumDiambil;
        }
        unset($warga);

        return Inertia::render('DataWarga', [
            'warga'     => $wargas,
            'perangkat' => $this->perangkatService->all(),
        ]);
    }

    public function grafik()
    {
        Carbon::setLocale('id');

        $labelsHarian    = [];
        $distribusiHarian = [];

        for ($i = 6; $i >= 0; $i--) {
            $date              = Carbon::now()->subDays($i);
            $labelsHarian[]    = $date->isoFormat('dddd');
            $distribusiHarian[] = $this->transaksiService->sumByDate($date->format('Y-m-d'));
        }

        return Inertia::render('Grafik', [
            'warga'            => $this->wargaService->all(),
            'perangkat'        => $this->perangkatService->all(),
            'labelsHarian'     => $labelsHarian,
            'distribusiHarian' => $distribusiHarian,
        ]);
    }

    public function storeWarga(Request $request)
    {
        // Validasi manual karena tidak ada DB unique constraint lagi
        $validated = $request->validate([
            'nik'           => 'required|string|max:16',
            'uid_kartu'     => 'required', // Boleh string atau integer
            'nama'          => 'required|string|max:255',
            'alamat'        => 'required|string',
            'pin'           => 'required|string|min:4|max:4',
            'jatah_ini'     => 'required|numeric|min:0.1',
            'status'        => 'required|string|in:Aktif,Nonaktif',
        ]);

        $validated['uid_kartu'] = (string) $validated['uid_kartu'];

        // Cek unik manual
        if ($this->wargaService->nikExists($validated['nik'])) {
            return back()->withErrors(['nik' => 'NIK sudah terdaftar.']);
        }
        if ($this->wargaService->uidExists($validated['uid_kartu'])) {
            return back()->withErrors(['uid_kartu' => 'UID kartu sudah terdaftar.']);
        }

        $this->wargaService->create([
            'uid_kartu'      => $validated['uid_kartu'],
            'nik'            => $validated['nik'],
            'nama'           => $validated['nama'],
            'alamat'         => $validated['alamat'],
            'pin'            => $validated['pin'],
            'jatah_bulanan'  => (float) $validated['jatah_ini'],
            'status'         => $validated['status'],
        ]);

        // Generate jatah bulan ini untuk warga baru
        $this->jatahWargaService->pastikanJatahBulanIniAda();

        return redirect()->back();
    }

    public function updateWarga(Request $request, string $uid)
    {
        $validated = $request->validate([
            'nik'       => 'required|string|max:16',
            'uid_kartu' => 'required', // Boleh string atau integer
            'nama'      => 'required|string|max:255',
            'alamat'    => 'required|string',
            'pin'       => 'required|string|min:4|max:4',
            'jatah_ini' => 'required|numeric|min:0.1',
            'status'    => 'required|string|in:Aktif,Nonaktif',
        ]);

        $validated['uid_kartu'] = (string) $validated['uid_kartu'];

        // Cek unik NIK kecuali diri sendiri
        if ($this->wargaService->nikExists($validated['nik'], $uid)) {
            return back()->withErrors(['nik' => 'NIK sudah terdaftar.']);
        }

        // Cek unik UID Kartu jika UID diubah
        if ($validated['uid_kartu'] !== $uid && $this->wargaService->uidExists($validated['uid_kartu'])) {
            return back()->withErrors(['uid_kartu' => 'UID kartu sudah terdaftar.']);
        }

        $this->wargaService->update($uid, [
            'uid_kartu'     => $validated['uid_kartu'],
            'nik'           => $validated['nik'],
            'nama'          => $validated['nama'],
            'alamat'        => $validated['alamat'],
            'pin'           => $validated['pin'],
            'jatah_bulanan' => (float) $validated['jatah_ini'],
            'status'        => $validated['status'],
        ]);

        return redirect()->back();
    }

    public function destroyWarga(string $uid)
    {
        $this->wargaService->delete($uid);
        return redirect()->back();
    }

    public function tambahJatah(Request $request, string $uid)
    {
        $validated = $request->validate([
            'periode_bulan' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'jumlah_kg'     => 'required|numeric|min:0.1',
        ]);

        $this->jatahWargaService->tambahJatahManual($uid, $validated['periode_bulan'], (float)$validated['jumlah_kg']);

        return redirect()->back();
    }

    public function hapusJatah(string $uid, string $periode)
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
            return back()->withErrors(['message' => 'Format periode tidak valid.']);
        }

        $this->jatahWargaService->hapusJatahManual($uid, $periode);

        return redirect()->back();
    }
}