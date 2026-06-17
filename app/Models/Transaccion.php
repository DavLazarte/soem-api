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
        'tipo',         // compra | anulacion | ajuste | manual
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

    protected $appends = [
        'monto_cobrado'
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

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'modelo_id')->where('modelo', 'Transaccion');
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

    public function getMontoCobradoAttribute()
    {
        if (!$this->es_cuotas) {
            return $this->estado === 'confirmada' ? $this->monto_total : 0;
        }

        // Sum of all paid quotas
        return $this->cuotas()->where('estado', 'cobrada')->sum('monto');
    }
}