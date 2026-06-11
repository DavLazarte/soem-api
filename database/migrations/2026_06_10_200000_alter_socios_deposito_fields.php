<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('socios', function (Blueprint $table) {
            $table->dropColumn(['permite_negativo', 'tope_negativo', 'acumula_saldo']);
            $table->boolean('deposito_automatico')->default(true)->after('saldo_disponible');
            $table->decimal('saldo_anterior', 10, 2)->default(0)->after('deposito_automatico');
        });
    }

    public function down(): void
    {
        Schema::table('socios', function (Blueprint $table) {
            $table->dropColumn(['deposito_automatico', 'saldo_anterior']);
            $table->boolean('permite_negativo')->default(false);
            $table->decimal('tope_negativo', 10, 2)->nullable();
            $table->boolean('acumula_saldo')->default(true);
        });
    }
};
