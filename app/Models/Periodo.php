<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Periodo extends Model
{
    protected $fillable = ['nombre', 'mes', 'anio', 'estado'];

    public function acreditaciones()
    {
        return $this->hasMany(Acreditacion::class);
    }

    public function transacciones()
    {
        return $this->hasMany(Transaccion::class);
    }

    public function cuotas()
    {
        return $this->hasMany(Cuota::class);
    }

    // Helper para obtener o crear el período actual
    public static function actual(): self
    {
        return static::firstOrCreate(
            ['mes' => now()->month, 'anio' => now()->year],
            [
                'nombre' => now()->translatedFormat('F Y'),
                'estado' => 'abierto',
            ]
        );
    }

    public function estaAbierto(): bool
    {
        return $this->estado === 'abierto';
    }
}