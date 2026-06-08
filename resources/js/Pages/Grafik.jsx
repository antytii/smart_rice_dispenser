import { Head, Link, router } from '@inertiajs/react';
import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useRealtimePerangkat } from '@/Hooks/useRealtimePerangkat';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js';
import { Line, Bar } from 'react-chartjs-2';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  Title,
  Tooltip,
  Legend
);

export default function Grafik({ auth, warga = [], perangkat: initialPerangkat = [], labelsHarian = [], distribusiHarian = [] }) {
    const perangkat = useRealtimePerangkat(initialPerangkat);
    // --- Efek Auto-Refresh (Realtime Polling) ---
    React.useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: ['warga', 'perangkat', 'labelsHarian', 'distribusiHarian'], 
                preserveState: true, 
                preserveScroll: true
            });
        }, 5000); 

        return () => clearInterval(interval);
    }, []);

    // Data dummy sementara
    const totalWarga = warga.length;
    const activeWarga = warga.filter(w => w.status === 'Aktif').length;
    const inactiveWarga = totalWarga - activeWarga;

    const dailyData = {
        labels: labelsHarian.length > 0 ? labelsHarian : ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'],
        datasets: [
            {
                label: 'Distribusi Beras (kg)',
                data: distribusiHarian.length > 0 ? distribusiHarian : [0, 0, 0, 0, 0, 0, 0],
                borderColor: 'rgb(53, 162, 235)',
                backgroundColor: 'rgba(53, 162, 235, 0.5)',
                tension: 0.3
            },
        ],
    };

    // Kita menggunakan Bar Chart untuk stok menggunakan persentase agar selalu fix di angka 100%
    const mesin = perangkat.length > 0 ? perangkat[0] : null;
    const sisaPersen = mesin ? parseFloat(mesin.persentase_stok) : 0;
    
    // Pastikan persentase tidak melebihi 100 atau kurang dari 0
    const validSisaPersen = Math.max(0, Math.min(100, sisaPersen));
    const persenKosong = 100 - validSisaPersen;

    const stockData = {
        labels: ['Kapasitas Mesin'],
        datasets: [
            {
                label: 'Sisa Beras (%)',
                data: [validSisaPersen],
                backgroundColor: 'rgba(53, 162, 235, 0.8)',
            },
            {
                label: 'Kapasitas Kosong (%)',
                data: [persenKosong],
                backgroundColor: 'rgba(200, 200, 200, 0.3)',
            }
        ],
    };

    const recipientData = {
        labels: ['Penerima Aktif', 'Penerima Nonaktif'],
        datasets: [
            {
                label: 'Jumlah Warga',
                data: [activeWarga, inactiveWarga],
                backgroundColor: [
                    'rgba(34, 197, 94, 0.7)',
                    'rgba(239, 68, 68, 0.7)',
                ],
                borderColor: [
                    'rgb(22, 163, 74)',
                    'rgb(220, 38, 38)',
                ],
                borderWidth: 1,
                borderRadius: 6,
            },
        ],
    };

    return (
        <AdminLayout title="Grafik Laporan" activePage="grafik" perangkat={perangkat}>
            <header className="topbar">
                <div>
                    <span className="eyebrow">Analisis Data</span>
                    <h2>Grafik Laporan</h2>
                    <p>Visualisasi tren distribusi beras dan sisa stok.</p>
                </div>
            </header>

            <section className="charts-grid mt-4">
                <article className="panel chart-panel wide">
                    <div className="section-heading compact">
                        <div>
                            <h3>Distribusi Harian</h3>
                        </div>
                    </div>
                        <div style={{ position: 'relative', height: '280px', width: '100%' }}>
                            <Line data={dailyData} options={{ maintainAspectRatio: false }} />
                        </div>
                    </article>

                    <article className="panel chart-panel">
                        <div className="section-heading compact">
                            <div>
                                <h3>Stok Beras</h3>
                            </div>
                        </div>
                        <div className="hopper-wrapper">
                            <div className="hopper-tank">
                                <div className="hopper-fill" style={{ height: `${validSisaPersen}%` }}></div>
                                <div className="hopper-label">{validSisaPersen.toFixed(1)}%</div>
                            </div>
                            <div className="hopper-stats">
                                <h4>{Number(sisaPersen > 0 ? mesin.sisa_stok_beras : 0).toFixed(2)} kg</h4>
                                <p>Tersisa dari kapasitas {mesin ? mesin.kapasitas_maksimal : 0} kg</p>
                            </div>
                        </div>
                    </article>

                    <article className="panel chart-panel">
                        <div className="section-heading compact">
                            <div>
                                <h3>Jumlah Penerima</h3>
                            </div>
                        </div>
                        <div style={{ position: 'relative', height: '280px', width: '100%' }}>
                            <Bar 
                                data={recipientData} 
                                options={{ 
                                    maintainAspectRatio: false,
                                    plugins: { legend: { display: false } },
                                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                                }} 
                            />
                        </div>
                    </article>
                </section>
        </AdminLayout>
    );
}
