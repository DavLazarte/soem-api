<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cuota extends Model
{
    protected $fillable = [
        'transaccion_id',
        'periodo_id',
        'nro_cuota',
        'monto',
        'estado',
        'cobrada_en',
    ];

    protected $casts = [
        'monto'      => 'decimal:2',
        'cobrada_en' => 'datetime',
    ];

    public function transaccion()
    {
        return $this->belongsTo(Transaccion::class);
    }

    public function periodo()
    {
        return $this->belongsTo(Periodo::class);
    }
}