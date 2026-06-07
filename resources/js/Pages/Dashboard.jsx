import { Head, Link, useForm, router } from '@inertiajs/react';
import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useRealtimePerangkat } from '@/Hooks/useRealtimePerangkat';


export default function Dashboard({ auth, warga = [], perangkat: initialPerangkat = [], totalBeras = 0, berasBulanIni = 0, berasBulanLalu = 0, transaksi = [] }) {
    const perangkat = useRealtimePerangkat(initialPerangkat);
    
    // Mengambil data mesin pertama (asumsi ID: BANSOS-M1)
    const mesin = perangkat.length > 0 ? perangkat[0] : null;
    const stokMesin = mesin ? mesin.sisa_stok_beras : 0;
    const statusMesin = mesin ? mesin.status_alat : 'Offline';
    const totalWarga = warga.length;
    
    // --- Efek Auto-Refresh (Realtime Polling) ---
    React.useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: ['warga', 'transaksi', 'totalBeras', 'berasBulanIni', 'berasBulanLalu', 'perangkat'], 
                preserveState: true, 
                preserveScroll: true
            });
        }, 5000); // 5s — backend sudah baca dari MySQL lokal (< 50ms per request)

        return () => clearInterval(interval);
    }, []);

    // --- Fungsi Download CSV ---
    const downloadCSV = () => {
        if (transaksi.length === 0) {
            alert('Tidak ada data transaksi untuk diunduh.');
            return;
        }

        const headers = ['Waktu', 'NIK', 'Nama', 'Berat', 'Status'];
        const rows = transaksi.map(trx => {
            // Formatting timestamp ke YYYY-MM-DD HH:mm:ss sesuai lokalisasi
            const dateStr = new Date(trx.created_at).toLocaleString('id-ID');
            const nik = trx.nik;
            const nama = warga.find(w => w.uid_kartu === trx.uid_kartu)?.nama || trx.nik;
            const berat = trx.jumlah_diambil;
            // Jika berat > 0 berarti sukses, jika tidak berarti ditolak
            const status = trx.jumlah_diambil > 0 ? 'Sukses' : 'Ditolak';

            return `"${dateStr}","${nik}","${nama}","${berat}","${status}"`;
        });

        // Gabungkan header dan baris data menggunakan baris baru (\n)
        const csvContent = "data:text/csv;charset=utf-8," + [headers.join(','), ...rows].join('\n');
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement('a');
        link.setAttribute('href', encodedUri);
        link.setAttribute('download', 'riwayat-transaksi-smart-bansos.csv');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };



    return (
        <AdminLayout title="Dashboard Utama" activePage="dashboard" perangkat={perangkat}>
            <header className="topbar">
                <div>
                    <span className="eyebrow">Monitoring Realtime</span>
                    <h2>Dashboard Utama</h2>
                    <p>Pengelolaan penerima, stok hopper, dan transaksi distribusi beras.</p>
                </div>
            </header>

            <section id="dashboard" className="stats-grid">
                <article className="stat-card primary">
                    <span>Total Warga</span>
                    <strong>{totalWarga}</strong>
                    <small>Penerima terdaftar</small>
                </article>

                <article className="stat-card success">
                    <span>Tersalurkan Bulan Ini</span>
                    <strong>{Number(berasBulanIni || 0).toFixed(2)} kg</strong>
                    <small>Akumulasi transaksi sukses bulan ini</small>
                </article>

                <article className="stat-card warning">
                    <span>Stok Beras Saat Ini</span>
                    <strong>{Number(stokMesin || 0).toFixed(2)} kg</strong>
                    <small>Sisa beras pada hopper dispenser</small>
                </article>

                <article className="stat-card danger">
                    <span>Tersalurkan Bulan Lalu</span>
                    <strong>{Number(berasBulanLalu || 0).toFixed(2)} kg</strong>
                    <small>Total transaksi sukses bulan lalu</small>
                </article>
            </section>

            <section id="transaksi" className="mt-4">
                <div className="section-heading">
                    <div>
                        <span className="eyebrow">Log Mesin IoT</span>
                        <h3>Riwayat Transaksi Realtime</h3>
                    </div>
                    <button className="btn btn-outline-primary" onClick={downloadCSV} id="downloadBtn">Download CSV</button>
                </div>

                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Waktuuu</th>
                                <th>NIK</th>
                                <th>Nama</th>
                                <th>Berat</th>
                            </tr>
                        </thead>
                        <tbody>
                            {transaksi.length > 0 ? transaksi.map((trx) => (
                                <tr key={trx.id_transaksi}>
                                    <td>
                                        {new Date(trx.created_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                    </td>
                                    <td>{trx.nik}</td>
                                    <td>{warga.find(w => w.uid_kartu === trx.uid_kartu)?.nama || trx.nik}</td>
                                    <td className="text-success fw-bold">+{Number(trx.jumlah_diambil).toFixed(2)} kg</td>
                                </tr>
                            )) : (
                                <tr><td colSpan="4" className="text-center text-muted py-3">Belum ada transaksi terekam.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>
        </AdminLayout>
    );
}