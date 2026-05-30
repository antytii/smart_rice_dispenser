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
        /* Ini bertindak sebagai <body class="login-page"> di HTML aslimu */
        <div className="login-page">
            <Head title="Login Admin" />

            <main className="login-shell">
                {/* Bagian Visual Kiri */}
                <section className="login-visual">
                    <div className="brand-mark">SB</div>
                    <h1>SMART-BANSOS</h1>
                    <p>Sistem distribusi beras bantuan sosial berbasis IoT dan e-KTP untuk transparansi desa.</p>

                    <div className="login-highlights">
                        <span>Verifikasi e-KTP</span>
                        <span>Stok Hopper</span>
                        <span>Riwayat Realtime</span>
                    </div>
                </section>

                {/* Bagian Form Kanan */}
                <section className="login-card">
                    <div className="mb-4">
                        <span className="eyebrow">Admin Panel</span>
                        <h2 className="h3 mt-2 mb-1">Masuk Dashboard</h2>
                        <p className="text-muted mb-0">Gunakan akun admin perangkat desa.</p>
                    </div>

                    <form onSubmit={submit}>
                        <div className="mb-3">
                            <label className="form-label">Username / Email</label>
                            <input 
                                type="email" 
                                className="form-control form-control-lg w-full" 
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="admin@desa.com"
                                required
                            />
                            {/* Menampilkan pesan error jika login gagal */}
                            {errors.email && <div className="text-red-500 text-sm mt-1">{errors.email}</div>}
                        </div>

                        <div className="mb-3">
                            <label className="form-label">Password</label>
                            <input 
                                type="password" 
                                className="form-control form-control-lg w-full"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="admin123"
                                required
                            />
                        </div>

                        <button 
                            type="submit" 
                            disabled={processing}
                            className="w-full mt-4 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg shadow-md hover:shadow-lg transition-all duration-200 transform hover:-translate-y-0.5"
                        >
                            Masuk
                        </button>
                    </form>
                </section>
            </main>
        </div>
    );
}