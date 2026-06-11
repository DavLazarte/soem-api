<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prestamo extends Model
{
    protected $fillable = [
        'socio_id',
        'monto_total',
        'cantidad_cuotas',
        'monto_cuota',
        'cuotas_pagadas',
        'estado',
        'aprobado_por',
        'observaciones',
    ];

    protected $casts = [
        'monto_total'  => 'decimal:2',
        'monto_cuota'  => 'decimal:2',
    ];

    // Relaciones
    public function socio()
    {
        return $this->belongsTo(Socio::class);
    }

    public function cuotasPrestamo()
    {
        return $this->hasMany(CuotaPrestamo::class);
    }

    public function aprobador()
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    // Helpers
    public function actualizarEstado(): void
    {
        $pagadas = $this->cuotasPrestamo()->where('estado', 'pagada')->count();
        $this->cuotas_pagadas = $pagadas;

        if ($pagadas >= $this->cantidad_cuotas) {
            $this->estado = 'finalizado';
        }

        $this->save();
    }
}
