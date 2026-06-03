<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Perangkat extends Model
{
    use HasFactory;

    // Kustomisasi Primary Key
    protected $primaryKey = 'id_alat';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id_alat', 'sisa_stok_beras', 'persentase_stok', 'status_alat', 'last_ping'
    ];

    protected $casts = [
        'sisa_stok_beras' => 'float',
        'persentase_stok' => 'float',
        'last_ping' => 'datetime',
    ];

    /**
     * Accessor: Mengecek apakah alat benar-benar online.
     * Jika alat tidak mengirim heartbeat (last_ping) lebih dari 30 detik, 
     * status otomatis berubah ke 'Offline' tanpa mengubah database.
     */
    public function getStatusAlatAttribute($value)
    {
        if ($this->last_ping && $this->last_ping->diffInSeconds(now()) >= 30) {
            return 'Offline';
        }

        return $value;
    }
}