<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'role',
        'estado',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Roles
    const ROLE_ADMIN        = 'admin';
    const ROLE_SOCIO        = 'socio';
    const ROLE_PRESTADOR    = 'prestador';
    const ROLE_ADMIN_SOCIOS = 'admin_socios';

    public function isAdmin()       { return $this->role === self::ROLE_ADMIN; }
    public function isSocio()       { return $this->role === self::ROLE_SOCIO; }
    public function isPrestador()   { return $this->role === self::ROLE_PRESTADOR; }
    public function isAdminSocios() { return $this->role === self::ROLE_ADMIN_SOCIOS; }
    public function isAnyAdmin()    { return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_ADMIN_SOCIOS]); }

    // Relaciones
    public function socio()
    {
        return $this->hasOne(Socio::class);
    }

    public function prestador()
    {
        return $this->hasOne(Prestador::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}