<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `productos.proveedor_id` ya existe (unsignedBigInteger nullable indexado) pero
 * sin FK real: la migración de productos corrió antes de que `proveedores`
 * existiera, así que su `if (Schema::hasTable('proveedores'))` nunca se cumplió
 * (research.md §1). Esta migración agrega la FK como defensa en profundidad —
 * la regla de negocio real ("no eliminar con productos asociados") se aplica en
 * ProveedorController::destroy(), no depende de este constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->foreign('proveedor_id')->references('id')->on('proveedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropForeign(['proveedor_id']);
        });
    }
};
