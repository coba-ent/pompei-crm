<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para que los listados aguanten el histórico de Contagram (24.000 ventas, 2.500 compras).
 *
 * Todas las tablas transaccionales se listan **ordenadas por `created_at` descendente** (es el
 * orden por defecto de los DataTables), y ninguna tenía índice en esa columna: MySQL resolvía cada
 * carga con `type=ALL` + `Using filesort`, o sea escaneando y ordenando la tabla entera para
 * mostrar 10 filas. Con datos de prueba era gratis; con el histórico, no.
 *
 * `fecha_emision`/`fecha` van indexadas porque son el filtro por período de los paneles de filtros
 * y de los informes.
 *
 * Los índices sobre las claves foráneas de cobros/pagos ya existen por la FK, así que los
 * subselects de los KPIs quedan cubiertos.
 */
return new class extends Migration
{
    /** tabla => columnas a indexar (una entrada por índice). */
    private const INDICES = [
        'ventas' => ['created_at', 'fecha_emision', 'fecha_vto_cobro'],
        'compras' => ['created_at', 'fecha_emision', 'fecha_vto_pago'],
        'presupuestos' => ['created_at', 'fecha_emision'],
        'cobros' => ['created_at', 'fecha'],
        'pagos' => ['created_at', 'fecha'],
        'gastos' => ['created_at', 'fecha'],
        'movimientos_stock' => ['created_at'],
        'movimientos_tesoreria' => ['created_at'],
        'notas_credito_debito' => ['created_at', 'fecha_emision'],
        'remitos' => ['created_at'],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($columnas as $columna) {
                // Defensivo a propósito: la migración corre sobre bases que ya divergieron del
                // código (ver 2026_08_18_060002), así que no se asume ni la columna ni el índice.
                if (! Schema::hasColumn($tabla, $columna) || $this->existeIndice($tabla, $columna)) {
                    continue;
                }

                Schema::table($tabla, fn (Blueprint $t) => $t->index($columna));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna) && $this->existeIndice($tabla, $columna)) {
                    Schema::table($tabla, fn (Blueprint $t) => $t->dropIndex([$columna]));
                }
            }
        }
    }

    private function existeIndice(string $tabla, string $columna): bool
    {
        return collect(Schema::getIndexes($tabla))
            ->contains(fn (array $i) => $i['columns'] === [$columna]);
    }
};
