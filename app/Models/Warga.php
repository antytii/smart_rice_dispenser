<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warga extends Model
{
    use HasFactory;

    // Kustomisasi Primary Key
    protected $primaryKey = 'uid_kartu';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uid_kartu', 
        'nik',
        'nama',
        'alamat',
        'pin',
        'jatah_bulanan',
        'jatah_lalu', 
        'jatah_ini',  
        'status'
    ];

    // Relasi: Satu Warga punya banyak Transaksi (One-to-Many)
    public function transaksi()
    {
        return $this->hasMany(Transaksi::class, 'uid_kartu', 'uid_kartu');
    }

    // Relasi: Satu Warga punya banyak Jatah Bulanan
    public function jatah_warga()
    {
        return $this->hasMany(JatahWarga::class, 'uid_kartu', 'uid_kartu');
    }
}