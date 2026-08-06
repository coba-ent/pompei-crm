<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Usuario" (quién cargó el Presupuesto) es un filtro propio en la captura real de Contagram
 * (docs/informe_contagram_ingresos.md §2.2), distinto de "Vendedor". Mismo criterio que
 * 2026_08_16_060003_add_creado_por_id_to_ventas_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->foreignId('creado_por_id')->nullable()->after('vendedor_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('presupuestos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creado_por_id');
        });
    }
};
