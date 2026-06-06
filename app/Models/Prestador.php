<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prestador extends Model
{
    use SoftDeletes;
    
    protected $table = 'prestadores';

    protected $fillable = [
        'user_id',
        'nombre',
        'direccion',
        'telefono',
        'estado',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transacciones()
    {
        return $this->hasMany(Transaccion::class);
    }

    public function transaccionesDelMes()
    {
        return $this->transacciones()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->where('estado', 'confirmada');
    }
}