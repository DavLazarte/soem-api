<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transacciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('socio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prestador_id')->constrained('prestadores')->cascadeOnDelete();
            $table->foreignId('periodo_id')->constrained()->cascadeOnDelete();
            $table->enum('tipo', ['compra', 'anulacion', 'ajuste'])->default('compra');
            $table->decimal('monto_total', 10, 2);
            $table->enum('estado', ['pendiente', 'confirmada', 'anulada'])->default('confirmada');
            $table->boolean('es_cuotas')->default(false);
            $table->foreignId('anulada_por')->nullable()->constrained('users');
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transacciones');
    }
};