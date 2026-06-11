<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestamos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('socio_id')->constrained()->cascadeOnDelete();
            $table->decimal('monto_total', 10, 2);
            $table->unsignedTinyInteger('cantidad_cuotas'); // 1, 2 o 3
            $table->decimal('monto_cuota', 10, 2);
            $table->unsignedTinyInteger('cuotas_pagadas')->default(0);
            $table->enum('estado', ['vigente', 'finalizado', 'cancelado'])->default('vigente');
            $table->foreignId('aprobado_por')->constrained('users');
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamos');
    }
};
