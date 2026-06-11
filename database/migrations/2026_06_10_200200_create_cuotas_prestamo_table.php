<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuotas_prestamo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestamo_id')->constrained('prestamos')->cascadeOnDelete();
            $table->unsignedTinyInteger('nro_cuota'); // 1, 2 o 3
            $table->decimal('monto', 10, 2);
            $table->enum('estado', ['pendiente', 'pagada', 'anulada'])->default('pendiente');
            $table->foreignId('periodo_id')->nullable()->constrained('periodos')->nullOnDelete();
            $table->timestamp('pagada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuotas_prestamo');
    }
};
