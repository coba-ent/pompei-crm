<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Usuario" (quién cargó la Venta) es un filtro propio en la captura real de Contagram, distinto
 * de "Vendedor" (ver nota en modelo_datos.md §ventas/presupuestos, vendedor_id). No existía
 * ningún tracking de usuario creador en `ventas` hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('creado_por_id')->nullable()->after('vendedor_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creado_por_id');
        });
    }
};
