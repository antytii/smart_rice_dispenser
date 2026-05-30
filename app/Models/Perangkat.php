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
}