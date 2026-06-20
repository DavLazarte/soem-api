<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacciones', function (Blueprint $table) {
            $table->text('detalle')->nullable()->after('estado');
            $table->unsignedBigInteger('editada_por')->nullable()->after('anulada_por');
            $table->text('motivo_edicion')->nullable()->after('motivo_anulacion');
            
            $table->foreign('editada_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transacciones', function (Blueprint $table) {
            $table->dropForeign(['editada_por']);
            $table->dropColumn(['detalle', 'editada_por', 'motivo_edicion']);
        });
    }
};
