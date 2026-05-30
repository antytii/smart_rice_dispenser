import { Head, Link, useForm, router } from '@inertiajs/react';
import React from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import Swal from 'sweetalert2';
import { useRealtimePerangkat } from '@/Hooks/useRealtimePerangkat';

export default function DataWarga({ auth, warga = [], perangkat: initialPerangkat = [] }) {
    const perangkat = useRealtimePerangkat(initialPerangkat);
    
    // Mengambil data mesin pertama (asumsi ID: BANSOS-M1)
    const mesin = perangkat.length > 0 ? perangkat[0] : null;
    const statusMesin = mesin ? mesin.status_alat : 'Offline';

    // --- Efek Auto-Refresh (Realtime Polling) ---
    React.useEffect(() => {
        const interval = setInterval(() => {
            // Cegah polling jika modal sedang terbuka (menghindari bug layar abu-abu)
            const modal = document.getElementById('wargaModal');
            if (modal && modal.classList.contains('show')) {
                return;
            }

            router.reload({
                only: ['warga', 'perangkat'], 
                preserveState: true, 
                preserveScroll: true
            });
        }, 15000); // Diubah dari 3s ke 15s agar tidak membebani browser & kuota Firebase

        return () => clearInterval(interval);
    }, []);

    // --- State & Form Tambah Warga ---
    const [editingUid, setEditingUid] = React.useState(null);
    const [selectedDetail, setSelectedDetail] = React.useState(null); // State untuk detail jatah

    // --- State & Handlers Jatah Manual ---
    const [formJatah, setFormJatah] = React.useState({
        periode_bulan: '',
        jumlah_kg: 10
    });
    const [submittingJatah, setSubmittingJatah] = React.useState(false);

    // Sinkronisasi data detail modal saat data utama (warga prop) berubah
    React.useEffect(() => {
        if (selectedDetail) {
            const updated = warga.find(w => w.uid_kartu === selectedDetail.uid_kartu);
            if (updated) {
                setSelectedDetail(updated);
            }
        }
    }, [warga]);

    // Set default nilai form jatah saat detail modal dibuka
    React.useEffect(() => {
        if (selectedDetail) {
            setFormJatah({
                periode_bulan: new Date().toISOString().substring(0, 7), // Format YYYY-MM
                jumlah_kg: selectedDetail.jatah_bulanan ?? 10
            });
        }
    }, [selectedDetail]);

    const handleTambahJatah = (e) => {
        e.preventDefault();
        if (!selectedDetail) return;

        setSubmittingJatah(true);
        router.post(route('warga.tambah-jatah', selectedDetail.uid_kartu), formJatah, {
            onSuccess: () => {
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: 'Jatah bulanan berhasil ditambahkan.',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000
                });
            },
            onError: (err) => {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal!',
                    text: Object.values(err).join(', '),
                });
            },
            onFinish: () => {
                setSubmittingJatah(false);
            }
        });
    };

    const handleHapusJatah = (uid, periode) => {
        Swal.fire({
            title: 'Hapus Jatah?',
            text: `Hapus jatah warga untuk periode ${periode}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route('warga.hapus-jatah', { uid, periode }), {
                    onSuccess: () => {
                        Swal.fire({
                            icon: 'success',
                            title: 'Terhapus!',
                            text: 'Data jatah berhasil dihapus.',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 3000
                        });
                    }
                });
            }
        });
    };

    const { data, setData, post, put, delete: destroy, processing, errors, reset, clearErrors } = useForm({
        nik: '',
        uid_kartu: '',
        nama: '',
        alamat: '',
        pin: '',
        jatah_ini: 10,
        status: 'Aktif'
    });

    const openAddModal = () => {
        setEditingUid(null);
        clearErrors();
        setData({
            nik: '',
            uid_kartu: '',
            nama: '',
            alamat: '',
            pin: '',
            jatah_ini: 10,
            status: 'Aktif'
        });
    };

    const openEditModal = (w) => {
        setEditingUid(w.uid_kartu);
        clearErrors();
        setData({
            nik: w.nik,
            uid_kartu: w.uid_kartu,
            nama: w.nama,
            alamat: w.alamat || '',
            pin: w.pin || '',
            jatah_ini: w.jatah_bulanan ?? w.jatah_ini ?? 10,
            status: w.status
        });
    };

    const openDetailModal = (wargaItem) => {
        setSelectedDetail(wargaItem);
    };

    const submitWarga = (e) => {
        e.preventDefault();
        if (editingUid) {
            put(route('warga.update', editingUid), {
                onSuccess: () => {
                    document.querySelector('#wargaModal .btn-close')?.click();
                }
            });
        } else {
            post(route('warga.store'), {
                onSuccess: () => {
                    reset();
                    document.querySelector('#wargaModal .btn-close')?.click();
                }
            });
        }
    };

    const hapusWarga = (uid) => {
        Swal.fire({
            title: 'Hapus Data Warga?',
            text: "Data yang dihapus tidak bisa dikembalikan!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                destroy(route('warga.destroy', uid), {
                    onSuccess: () => {
                        Swal.fire(
                            'Terhapus!',
                            'Data warga berhasil dihapus.',
                            'success'
                        );
                    }
                });
            }
        });
    };

    return (
        <AdminLayout title="Data Warga" activePage="data-warga" perangkat={perangkat}>
            <header className="topbar">
                <div>
                    <span className="eyebrow">Manajemen Data</span>
                    <h2>Data Warga</h2>
                    <p>Kelola data penerima bantuan sosial beras di sini.</p>
                </div>
            </header>

            <section id="warga" className="panel mt-4">
                <div className="section-heading">
                    <div>
                        <span className="eyebrow">Master Data</span>
                        <h3>Daftar Warga</h3>
                    </div>
                    <button className="btn btn-primary" data-bs-toggle="modal" data-bs-target="#wargaModal" id="addWargaBtn" onClick={openAddModal}>
                        Tambah Warga
                    </button>
                </div>

                    <div className="table-responsive mt-3">
                        <table className="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>NIK</th>
                                    <th>UID e-KTP</th>
                                    <th>Nama</th>
                                    <th>Status</th>
                                    <th>Belum Diambil</th>
                                    <th>Total Diterima</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {warga.length > 0 ? warga.map((item) => (
                                    <tr key={item.uid_kartu}>
                                        <td>{item.nik}</td>
                                        <td>{item.uid_kartu || '-'}</td>
                                        <td>{item.nama}</td>
                                        <td>
                                            <span className={`badge ${item.status === 'Aktif' ? 'bg-success' : 'bg-danger'}`}>
                                                {item.status}
                                            </span>
                                        </td>
                                        <td>
                                            <span className="fw-bold text-danger">{Number(item.total_belum_diambil || 0).toFixed(2)} kg</span>
                                        </td>
                                        <td>
                                            <span className="fw-bold text-primary">{Number(item.transaksi_sum_jumlah_diambil || 0).toFixed(2)} kg</span>
                                        </td>
                                        <td>
                                            <button className="btn btn-sm btn-outline-info me-2" data-bs-toggle="modal" data-bs-target="#detailModal" onClick={() => openDetailModal(item)}>Detail</button>
                                            <button className="btn btn-sm btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#wargaModal" onClick={() => openEditModal(item)}>Edit</button>
                                            <button className="btn btn-sm btn-outline-danger" onClick={() => hapusWarga(item.uid_kartu)}>Hapus</button>
                                        </td>
                                    </tr>
                                )) : (
                                    <tr><td colSpan="7" className="text-center text-muted py-3">Belum ada data warga.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

            {/* MODAL DETAIL RIWAYAT JATAH */}
            <div className="modal fade" id="detailModal" tabIndex="-1" aria-hidden="true">
                <div className="modal-dialog modal-dialog-centered modal-lg">
                    <div className="modal-content bg-white text-dark border-0" style={{ boxShadow: '0 10px 30px rgba(0,0,0,0.1)' }}>
                        <div className="modal-header border-bottom">
                            <h5 className="modal-title text-dark">
                                Detail Riwayat Jatah: <span className="text-primary">{selectedDetail?.nama}</span>
                            </h5>
                            <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div className="modal-body">
                            {/* Form Tambah Jatah Manual */}
                            <div className="card mb-4 border-0 bg-light">
                                <div className="card-body">
                                    <h6 className="card-title fw-bold text-dark mb-3">Tambah/Edit Periode Jatah Manual</h6>
                                    <form onSubmit={handleTambahJatah} className="row g-3 align-items-end">
                                        <div className="col-md-5">
                                            <label className="form-label text-muted small mb-1">Periode (Bulan - Tahun)</label>
                                            <input 
                                                type="month" 
                                                className="form-control form-control-sm"
                                                value={formJatah.periode_bulan}
                                                onChange={e => setFormJatah(prev => ({ ...prev, periode_bulan: e.target.value }))}
                                                required 
                                            />
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label text-muted small mb-1">Jumlah Jatah (kg)</label>
                                            <input 
                                                type="number" 
                                                step="0.1"
                                                min="0.1"
                                                className="form-control form-control-sm"
                                                value={formJatah.jumlah_kg}
                                                onChange={e => setFormJatah(prev => ({ ...prev, jumlah_kg: e.target.value }))}
                                                required 
                                            />
                                        </div>
                                        <div className="col-md-3">
                                            <button type="submit" className="btn btn-sm btn-primary w-100" disabled={submittingJatah}>
                                                {submittingJatah ? 'Menyimpan...' : 'Simpan Jatah'}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div className="table-responsive">
                                <table className="table table-hover align-middle">
                                    <thead className="table-light">
                                        <tr>
                                            <th>Periode (Tahun-Bulan)</th>
                                            <th>Jumlah Jatah</th>
                                            <th>Status</th>
                                            <th>Waktu Pengambilan</th>
                                            <th className="text-end">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {selectedDetail?.jatah_warga?.length > 0 ? (
                                            selectedDetail.jatah_warga.map((jatah) => (
                                                <tr key={jatah.periode_bulan}>
                                                    <td><span className="fw-bold">{jatah.periode_bulan}</span></td>
                                                    <td>{jatah.jumlah_kg} kg</td>
                                                    <td>
                                                        <span className={`badge ${jatah.status === 'Sudah Diambil' ? 'bg-success' : 'bg-danger'}`}>
                                                            {jatah.status}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        {jatah.diambil_pada ? new Date(jatah.diambil_pada).toLocaleString('id-ID') : '-'}
                                                    </td>
                                                    <td className="text-end">
                                                        <button 
                                                            className="btn btn-sm btn-outline-danger py-0 px-2"
                                                            onClick={() => handleHapusJatah(jatah.uid_kartu, jatah.periode_bulan)}
                                                        >
                                                            Hapus
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr><td colSpan="5" className="text-center text-muted">Belum ada riwayat jatah tercatat.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div className="modal-footer border-top">
                            <button type="button" className="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                        </div>
                    </div>
                </div>
            </div>

            {/* MODAL TAMBAH WARGA */}
            <div className="modal fade" id="wargaModal" tabIndex="-1" aria-hidden="true">
                <div className="modal-dialog modal-dialog-centered">
                    <form className="modal-content" onSubmit={submitWarga}>
                        <div className="modal-header">
                            <h5 className="modal-title">{editingUid ? 'Edit Data Warga' : 'Tambah Data Warga'}</h5>
                            <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div className="modal-body">
                            <div className="row g-3">
                                <div className="col-md-6">
                                    <label htmlFor="nik" className="form-label">NIK</label>
                                    <input type="text" className={`form-control ${errors.nik ? 'is-invalid' : ''}`} id="nik" maxLength="16" 
                                           value={data.nik} onChange={e => setData('nik', e.target.value)} required />
                                    {errors.nik && <div className="invalid-feedback">{errors.nik}</div>}
                                </div>

                                <div className="col-md-6">
                                    <label htmlFor="uid_kartu" className="form-label">UID e-KTP/RFID</label>
                                    <input type="text" className={`form-control ${errors.uid_kartu ? 'is-invalid' : ''}`} id="uid_kartu" 
                                           value={data.uid_kartu} onChange={e => setData('uid_kartu', e.target.value)} required />
                                    {errors.uid_kartu && <div className="invalid-feedback">{errors.uid_kartu}</div>}
                                </div>

                                <div className="col-12">
                                    <label htmlFor="nama" className="form-label">Nama Warga</label>
                                    <input type="text" className={`form-control ${errors.nama ? 'is-invalid' : ''}`} id="nama" 
                                           value={data.nama} onChange={e => setData('nama', e.target.value)} required />
                                    {errors.nama && <div className="invalid-feedback">{errors.nama}</div>}
                                </div>

                                <div className="col-12">
                                    <label htmlFor="alamat" className="form-label">Alamat</label>
                                    <textarea className={`form-control ${errors.alamat ? 'is-invalid' : ''}`} id="alamat" rows="2" 
                                              value={data.alamat} onChange={e => setData('alamat', e.target.value)} required></textarea>
                                    {errors.alamat && <div className="invalid-feedback">{errors.alamat}</div>}
                                </div>

                                <div className="col-md-4">
                                    <label htmlFor="pin" className="form-label">PIN (4 Digit)</label>
                                    <input type="text" className={`form-control ${errors.pin ? 'is-invalid' : ''}`} id="pin" maxLength="4" 
                                           value={data.pin} onChange={e => setData('pin', e.target.value.replace(/\D/g, ''))} required />
                                    {errors.pin && <div className="invalid-feedback">{errors.pin}</div>}
                                </div>

                                <div className="col-md-4">
                                     <label htmlFor="jatah_ini" className="form-label">Kuota/Bulan (kg)</label>
                                     <input type="number" step="0.1" min="0.1" className={`form-control ${errors.jatah_ini ? 'is-invalid' : ''}`} id="jatah_ini" 
                                            value={data.jatah_ini} onChange={e => setData('jatah_ini', e.target.value)} required />
                                    {errors.jatah_ini && <div className="invalid-feedback">{errors.jatah_ini}</div>}
                                </div>

                                <div className="col-md-4">
                                    <label htmlFor="status" className="form-label">Status</label>
                                    <select className="form-select" id="status" 
                                            value={data.status} onChange={e => setData('status', e.target.value)}>
                                        <option value="Aktif">Aktif</option>
                                        <option value="Nonaktif">Nonaktif</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div className="modal-footer">
                            <button type="button" className="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" className="btn btn-primary" disabled={processing}>
                                {processing ? 'Menyimpan...' : 'Simpan'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}