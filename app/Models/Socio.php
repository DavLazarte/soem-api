<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Socio extends Model
{
    use SoftDeletes;

    protected $table = 'socios';

    protected $fillable = [
        'user_id',
        'legajo',
        'nombre',
        'apellido',
        'celular',
        'estado',
        'saldo_disponible',
        'deposito_automatico',
        'saldo_anterior',
    ];

    protected $casts = [
        'saldo_disponible'    => 'decimal:2',
        'saldo_anterior'      => 'decimal:2',
        'deposito_automatico' => 'boolean',
    ];

    // Relaciones
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function acreditaciones()
    {
        return $this->hasMany(Acreditacion::class);
    }

    public function transacciones()
    {
        return $this->hasMany(Transaccion::class);
    }

    public function prestamos()
    {
        return $this->hasMany(Prestamo::class);
    }

    // Helpers
    public function nombreCompleto(): string
    {
        return "{$this->nombre} {$this->apellido}";
    }

    public function tieneSaldoDisponible(float $monto): bool
    {
        return $this->saldo_disponible >= $monto;
    }

    public function cuotasPendientes()
    {
        return $this->transacciones()
            ->where('es_cuotas', true)
            ->whereHas('cuotas', fn($q) => $q->where('estado', 'pendiente'))
            ->with(['cuotas' => fn($q) => $q->where('estado', 'pendiente'), 'prestador'])
            ->get();
    }
}