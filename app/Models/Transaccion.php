<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaccion extends Model
{
    protected $table = 'transacciones';

    
    protected $fillable = [
        'socio_id',
        'prestador_id',
        'periodo_id',
        'tipo',
        'monto_total',
        'estado',
        'es_cuotas',
        'anulada_por',
        'motivo_anulacion',
    ];

    protected $casts = [
        'monto_total' => 'decimal:2',
        'es_cuotas'   => 'boolean',
    ];

    public function socio()
    {
        return $this->belongsTo(Socio::class);
    }

    public function prestador()
    {
        return $this->belongsTo(Prestador::class);
    }

    public function periodo()
    {
        return $this->belongsTo(Periodo::class);
    }

    public function cuotas()
    {
        return $this->hasMany(Cuota::class);
    }

    public function anuladaPor()
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function cuotaPendienteDelMes()
    {
        $periodo = Periodo::actual();
        return $this->cuotas()
            ->where('periodo_id', $periodo->id)
            ->where('estado', 'pendiente')
            ->first();
    }
}