<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('legajo')->unique();
            $table->string('nombre');
            $table->string('apellido');
            $table->string('celular')->nullable();
            $table->enum('estado', ['activo', 'inactivo', 'suspendido'])->default('activo');
            $table->decimal('saldo_disponible', 10, 2)->default(0);
            $table->boolean('permite_negativo')->default(false);
            $table->decimal('tope_negativo', 10, 2)->nullable();
            $table->boolean('acumula_saldo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socios');
    }
};