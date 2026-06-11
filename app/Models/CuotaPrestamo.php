<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuotaPrestamo extends Model
{
    protected $table = 'cuotas_prestamo';

    protected $fillable = [
        'prestamo_id',
        'nro_cuota',
        'monto',
        'estado',
        'periodo_id',
        'pagada_en',
    ];

    protected $casts = [
        'monto'     => 'decimal:2',
        'pagada_en' => 'datetime',
    ];

    // Relaciones
    public function prestamo()
    {
        return $this->belongsTo(Prestamo::class);
    }

    public function periodo()
    {
        return $this->belongsTo(Periodo::class);
    }
}
