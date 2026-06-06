<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'accion',
        'modelo',
        'modelo_id',
        'valores_antes',
        'valores_despues',
        'ip',
    ];

    protected $casts = [
        'valores_antes'   => 'array',
        'valores_despues' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Helper estático para registrar desde cualquier lado
    public static function registrar(string $accion, Model $modelo, array $antes = [], array $despues = []): void
    {
        static::create([
            'user_id'         => auth()->id(),
            'accion'          => $accion,
            'modelo'          => class_basename($modelo),
            'modelo_id'       => $modelo->getKey(),
            'valores_antes'   => $antes,
            'valores_despues' => $despues,
            'ip'              => request()->ip(),
        ]);
    }
}