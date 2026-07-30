<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen de la Venta (FR-035, FR-035a) — data-model.md §6.
 *
 * 'tiendanube' (spec 017) se agrega acá directamente en vez de sólo en la
 * migración ALTER dedicada (2026_08_09_060006): en SQLite (tests) el enum se
 * implementa como CHECK constraint fijado en la creación de la columna — igual
 * criterio que 'ingreso' en `categorias.tipo` (2026_07_26_060001). La ALTER de
 * MySQL sigue siendo necesaria para la base ya deployada en producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->enum('origen', ['manual', 'presupuesto', 'mercadolibre', 'tiendanube'])->default('manual')->after('presupuesto_id');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
