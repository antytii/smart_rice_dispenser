<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Perangkat extends Model
{
    use HasFactory;

    // Kustomisasi Primary Key
    protected $primaryKey = 'id_alat';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id_alat', 'sisa_stok_beras', 'persentase_stok', 'status_alat'
    ];

    /**
     * Accessor: Mengecek apakah alat benar-benar online.
     * Jika alat tidak mengirim data lebih dari 2 menit, status akan dikembalikan sebagai 'Offline'
     * secara otomatis tanpa perlu mengubah database.
     */
    public function getStatusAlatAttribute($value)
    {
        // Pastikan updated_at ada dan alat sudah lewat 2 menit tanpa laporan
        if ($this->updated_at && $this->updated_at->diffInMinutes(now()) >= 2) {
            return 'Offline';
        }

        return $value;
    }
}