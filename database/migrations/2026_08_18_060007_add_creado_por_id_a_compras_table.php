<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Usuario" (quién cargó la Compra) es un filtro propio en la captura real de Contagram. No
 * existía tracking de usuario creador en `compras` hasta ahora (mismo criterio que
 * 2026_08_16_060003_add_creado_por_id_to_ventas_table.php). Sin backfill: las compras existentes
 * quedan con NULL (data-model.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->foreignId('creado_por_id')->nullable()->after('proveedor_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creado_por_id');
        });
    }
};
