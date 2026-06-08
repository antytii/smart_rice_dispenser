import { Head, useForm } from '@inertiajs/react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'));
    };

    return (
        <div className="login-page">
            <Head title="Login Admin" />

            <main className="login-shell">
                {/* Bagian Visual Kiri */}
                <section className="login-visual">
                    <img src="/images/logo.png" className="brand-logo-img-login" alt="Smart Bansos Logo" />
                    <h1>SMART-BANSOS</h1>
                    <p>Sistem distribusi beras bantuan sosial berbasis IoT dan e-KTP untuk transparansi desa.</p>

                    <div className="login-highlights">
                        <span>✦ Verifikasi e-KTP</span>
                        <span>📦 Stok Hopper</span>
                        <span>📊 Riwayat Realtime</span>
                    </div>
                </section>

                {/* Bagian Form Kanan */}
                <section className="login-card">
                    <div className="mb-4">
                        <span className="eyebrow">ADMIN PANEL</span>
                        <h2 className="mt-2 mb-1">Masuk Dashboard</h2>
                        <p className="text-muted mb-0">Gunakan akun admin perangkat desa.</p>
                    </div>

                    <form onSubmit={submit}>
                        <div className="mb-3">
                            <label className="form-label">Username / Email</label>
                            <input 
                                type="email" 
                                className="form-control form-control-lg w-100" 
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="admin@desa.com"
                                required
                            />
                            {errors.email && <div className="text-danger mt-1" style={{ fontSize: '13px' }}>{errors.email}</div>}
                        </div>

                        <div className="mb-4">
                            <label className="form-label">Password</label>
                            <input 
                                type="password" 
                                className="form-control form-control-lg w-100"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="••••••••"
                                required
                            />
                        </div>

                        <button 
                            type="submit" 
                            disabled={processing}
                            className="w-100"
                        >
                            {processing ? 'Memproses...' : 'Masuk'}
                        </button>
                    </form>

                    <p className="text-center mt-4 mb-0" style={{ fontSize: '12px', color: '#94a3b8' }}>
                        Smart-Bansos &middot; Sistem Distribusi Beras Desa
                    </p>
                </section>
            </main>
        </div>
    );
}