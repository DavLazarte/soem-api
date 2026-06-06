<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Acreditacion extends Model
{
    protected $table = 'acreditaciones';
    protected $fillable = [
        'socio_id',
        'periodo_id',
        'monto',
        'estado',
        'acreditado_por',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
    ];

    public function socio()
    {
        return $this->belongsTo(Socio::class);
    }

    public function periodo()
    {
        return $this->belongsTo(Periodo::class);
    }

    public function acreditadoPor()
    {
        return $this->belongsTo(User::class, 'acreditado_por');
    }
}