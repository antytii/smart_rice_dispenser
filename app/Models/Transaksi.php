<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Transaksi extends Model
{
    use HasFactory;

    protected $primaryKey = 'id_transaksi';

    protected $fillable = [
        'uid_kartu', 'nik', 'jumlah_diambil', 'keterangan', 'waktu_ambil'
    ];

    protected $casts = [
        'jumlah_diambil' => 'float',
        'waktu_ambil' => 'datetime',
    ];

    // Relasi: Satu Transaksi dimiliki oleh satu Warga (Many-to-One)
    public function warga()
    {
        return $this->belongsTo(Warga::class, 'uid_kartu', 'uid_kartu');
    }

    /**
     * Scope: Filter transaksi berdasarkan bulan & tahun
     */
    public function scopeByMonth($query, int $month, int $year)
    {
        return $query->whereMonth('created_at', $month)->whereYear('created_at', $year);
    }

    /**
     * Scope: Filter transaksi berdasarkan tanggal (untuk grafik harian)
     */
    public function scopeByDate($query, string $date)
    {
        return $query->whereDate('created_at', $date);
    }
}