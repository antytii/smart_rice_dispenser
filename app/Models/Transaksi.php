<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaksi extends Model
{
    use HasFactory;

    protected $primaryKey = 'id_transaksi';

    protected $fillable = [
        'uid_kartu', 'nik', 'jumlah_diambil', 'waktu_ambil'
    ];

    // Relasi: Satu Transaksi dimiliki oleh satu Warga (Many-to-One)
    public function warga()
    {
        return $this->belongsTo(Warga::class, 'uid_kartu', 'uid_kartu');
    }
}