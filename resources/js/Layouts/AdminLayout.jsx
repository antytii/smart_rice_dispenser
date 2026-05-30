import { Head, Link } from '@inertiajs/react';
import React from 'react';

export default function AdminLayout({ children, title, activePage, perangkat = [] }) {
    // Mengambil data mesin pertama (asumsi ID: BANSOS-M1)
    const mesin = perangkat.length > 0 ? perangkat[0] : null;
    const statusMesin = mesin ? mesin.status_alat : 'Offline';

    return (
        <div className="app-layout">
            <Head title={title} />

            <aside className="sidebar">
                <div className="sidebar-brand">
                    <div className="brand-icon">SB</div>
                    <div>
                        <h1>SMART-BANSOS</h1>
                        <span>Admin Desa</span>
                    </div>
                </div>

                <nav className="sidebar-nav">
                    <Link className={activePage === 'dashboard' ? 'active' : ''} href={route('dashboard')}>Dashboard</Link>
                    <Link className={activePage === 'data-warga' ? 'active' : ''} href={route('data-warga')}>Data Warga</Link>
                    <Link className={activePage === 'grafik' ? 'active' : ''} href={route('grafik')}>Grafik Laporan</Link>
                </nav>

                <div className="sidebar-status">
                    <span className="status-dot" style={{ backgroundColor: statusMesin === 'Online' ? '#22c55e' : '#ef4444', boxShadow: statusMesin === 'Online' ? '0 0 0 6px rgba(34, 197, 94, 0.16)' : '0 0 0 6px rgba(239, 68, 68, 0.16)' }}></span>
                    <div className="d-flex flex-column">
                        <strong>Mesin {statusMesin}</strong>
                        <small>ESP32 {statusMesin === 'Online' ? 'tersinkron' : 'terputus'}</small>
                    </div>
                </div>

                <Link 
                    href={route('logout')} 
                    method="post" 
                    as="button" 
                    className="btn w-100 mt-3"
                    style={{ border: '1px solid rgba(255,255,255,0.2)', color: 'white', padding: '10px' }}
                >
                    Logout
                </Link>
            </aside>

            <main className="main-content">
                {children}
            </main>
        </div>
    );
}
