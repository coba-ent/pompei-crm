<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de Precios promocional de Tiendanube (spec 102): se publica como
 * `promotional_price` en el mismo PUT que ya manda `price`. Nace en `null` a
 * propósito — sin ella configurada la feature queda inerte (FR-000a) y el
 * deploy no cambia nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_conexion_rest', function (Blueprint $table) {
            $table->foreignId('lista_precio_promocional_id')->nullable()->after('lista_precio_id')->constrained('listas_precio')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tn_conexion_rest', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lista_precio_promocional_id');
        });
    }
};
