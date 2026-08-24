<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo congelado por línea, para que el CMV del Informe de Ventas deje de moverse (spec 075).
 *
 * ## Por qué la columna es NULLABLE y SIN DEFAULT
 *
 * Es la decisión más cara de equivocar de toda la feature, así que queda escrita acá y no sólo en
 * `data-model.md §1`. Los dos valores significan cosas distintas:
 *
 * - `NULL` = "esta línea no tiene costo congelado" ⇒ el CMV cae al promedio ponderado de compras.
 *   Es el estado de todas las líneas históricas, y es lo que garantiza cero regresión (SC-003).
 * - `0`    = "esta línea tiene costo congelado y vale cero" ⇒ el CMV es 0. Es el caso del producto
 *   sin costo cargado y el de la línea sin producto asociado (FR-007), y reproduce a Contagram.
 *
 * Un `default 0` haría los dos casos indistinguibles: el fallback no se activaría nunca y **toda**
 * venta histórica pasaría a aportar 0 al CMV. Por el mismo motivo esta migración NO hace ningún
 * `UPDATE` de datos: las filas existentes tienen que quedar en `NULL` a propósito.
 *
 * Precisión `decimal(14,2)`, alineada con `productos.costo` y `venta_items.precio_unitario`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_items', function (Blueprint $table) {
            $table->decimal('costo_unitario', 14, 2)->nullable()->after('precio_unitario');
        });

        Schema::table('nota_credito_debito_items', function (Blueprint $table) {
            $table->decimal('costo_unitario', 14, 2)->nullable()->after('precio');
        });
    }

    public function down(): void
    {
        Schema::table('venta_items', function (Blueprint $table) {
            $table->dropColumn('costo_unitario');
        });

        Schema::table('nota_credito_debito_items', function (Blueprint $table) {
            $table->dropColumn('costo_unitario');
        });
    }
};
