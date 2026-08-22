<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega 'precio_producto' al enum `logs_auditoria.tipo_operacion` (spec 074, FR-007).
 *
 * El `ALTER TABLE ... MODIFY` es sintaxis exclusiva de MySQL y revienta en SQLite,
 * donde corre la suite (`phpunit.xml` fija DB_CONNECTION=sqlite / :memory:) — de ahí
 * el guard por driver. La contraparte para SQLite es el valor agregado directamente
 * al `enum()` de la migración original `2026_08_07_155244_create_logs_auditoria_table`,
 * que allí se materializa como varchar + CHECK. Hacen falta las dos: con sólo el ALTER
 * se cae la suite, con sólo el enum original MySQL no aprende el valor.
 */
return new class extends Migration
{
    private const ENUM_NUEVO = "'venta', 'presupuesto', 'cobro', 'gasto', 'compra', 'movimiento_tesoreria', 'movimiento_stock', 'precio_producto'";

    private const ENUM_ANTERIOR = "'venta', 'presupuesto', 'cobro', 'gasto', 'compra', 'movimiento_tesoreria', 'movimiento_stock'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE logs_auditoria MODIFY COLUMN tipo_operacion ENUM('.self::ENUM_NUEVO.') NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // `logs_auditoria` es append-only, así que las filas de precio ya escritas no se
        // pueden reasignar a otro tipo: si existen, MySQL fallaría el MODIFY con un error
        // críptico de truncado de dato. Se avisa explícitamente en vez de eso.
        $enUso = DB::table('logs_auditoria')->where('tipo_operacion', 'precio_producto')->count();

        if ($enUso > 0) {
            throw new RuntimeException(
                "No se puede revertir: hay {$enUso} evento(s) de auditoría con tipo_operacion = 'precio_producto'. ".
                'Eliminá o reasigná esas filas antes de revertir esta migración.'
            );
        }

        DB::statement('ALTER TABLE logs_auditoria MODIFY COLUMN tipo_operacion ENUM('.self::ENUM_ANTERIOR.') NOT NULL');
    }
};
