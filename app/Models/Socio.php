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
        'permite_negativo',
        'tope_negativo',
        'acumula_saldo',
    ];

    protected $casts = [
        'saldo_disponible' => 'decimal:2',
        'tope_negativo'    => 'decimal:2',
        'permite_negativo' => 'boolean',
        'acumula_saldo'    => 'boolean',
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

    // Helpers
    public function nombreCompleto(): string
    {
        return "{$this->nombre} {$this->apellido}";
    }

    public function tieneSaldoDisponible(float $monto): bool
    {
        if ($this->permite_negativo) {
            $tope = $this->tope_negativo ?? Setting::get(Setting::TOPE_NEGATIVO, 0);
            return ($this->saldo_disponible - $monto) >= ($tope * -1);
        }
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