import { Head, Link } from '@inertiajs/react';
import React from 'react';

export default function AdminLayout({ children, title, activePage, perangkat = [] }) {
    const [isSidebarOpen, setIsSidebarOpen] = React.useState(false);
    
    // Mengambil data mesin pertama (asumsi ID: BANSOS-M1)
    const mesin = perangkat.length > 0 ? perangkat[0] : null;
    const statusMesin = mesin ? mesin.status_alat : 'Offline';

    const toggleSidebar = () => setIsSidebarOpen(!isSidebarOpen);

    return (
        <div className={`app-layout ${isSidebarOpen ? 'mobile-open' : ''}`}>
            <Head title={title} />

            {/* Mobile Header - Muncul hanya di layar kecil */}
            <header className="mobile-header">
                <div className="brand-small">
                    <img src="/images/logo.png" className="brand-logo-img" alt="Smart Bansos Logo" />
                    <strong>SMART-BANSOS</strong>
                </div>
                <button className="hamburger" onClick={toggleSidebar}>
                    {isSidebarOpen ? '✕' : '☰'}
                </button>
            </header>

            {/* Overlay untuk menutup sidebar saat diklik di luar area sidebar */}
            {isSidebarOpen && <div className="sidebar-overlay" onClick={toggleSidebar}></div>}

            <aside className={`sidebar ${isSidebarOpen ? 'open' : ''}`}>
                <div className="sidebar-brand">
                    <img src="/images/logo.png" className="brand-logo-img" alt="Smart Bansos Logo" />
                    <div>
                        <h1>SMART-BANSOS</h1>
                        <span>Admin Desa</span>
                    </div>
                </div>

                <nav className="sidebar-nav">
                    <Link onClick={() => setIsSidebarOpen(false)} className={activePage === 'dashboard' ? 'active' : ''} href={route('dashboard')}>Dashboard</Link>
                    <Link onClick={() => setIsSidebarOpen(false)} className={activePage === 'data-warga' ? 'active' : ''} href={route('data-warga')}>Data Warga</Link>
                    <Link onClick={() => setIsSidebarOpen(false)} className={activePage === 'grafik' ? 'active' : ''} href={route('grafik')}>Grafik Laporan</Link>
                </nav>

                <div className="sidebar-status">
                    <span className="status-dot" style={{ backgroundColor: statusMesin === 'Online' ? '#22c55e' : statusMesin === 'Dispensing' ? '#f59e0b' : '#ef4444', boxShadow: statusMesin === 'Online' ? '0 0 0 6px rgba(34, 197, 94, 0.16)' : statusMesin === 'Dispensing' ? '0 0 0 6px rgba(245, 158, 11, 0.16)' : '0 0 0 6px rgba(239, 68, 68, 0.16)' }}></span>
                    <div className="d-flex flex-column">
                        <strong>Mesin {statusMesin === 'Dispensing' ? 'Aktif' : statusMesin}</strong>
                        <small>ESP32 {statusMesin === 'Online' ? 'tersinkron' : statusMesin === 'Dispensing' ? 'sedang digunakan' : 'terputus'}</small>
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
