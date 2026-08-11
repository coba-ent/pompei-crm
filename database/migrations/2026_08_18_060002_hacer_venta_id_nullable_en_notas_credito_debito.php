<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pone `notas_credito_debito.venta_id` en NULL permitido, como el código siempre asumió.
 *
 * La migración que creó la tabla (2026_07_30_060006) ya lo declaraba `->nullable()` y la que agregó
 * `compra_id` (2026_07_31_070005) lo dice explícitamente: *"venta_id existente (ya nullable)"*,
 * porque una nota puede colgar de una venta **o** de una compra, nunca de las dos. Pero en la base
 * quedó NOT NULL: el `->nullable()` se agregó al archivo después de que la tabla ya estuviera creada,
 * así que nunca llegó al esquema.
 *
 * Dos consecuencias, una latente y una concreta:
 * - **Latente**: crear una NC/ND de una compra falla, porque ahí `venta_id` es null por diseño.
 * - **Concreta**: bloquea la migración del histórico. Las 692 NC/ND de Contagram no dicen a qué
 *   venta corrigen —el export no trae ese dato— y son $58M que tienen que estar para que cierre
 *   la caja.
 *
 * Se modifica sólo la nulabilidad; la foreign key y el índice quedan como están.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (tests) no soporta MODIFY COLUMN, y allá la columna ya nace nullable
        // desde la migración de creación — mismo criterio que el resto del proyecto.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE notas_credito_debito MODIFY venta_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // Sólo puede volver atrás si no hay filas sin venta: si no, el ALTER las rompería.
        if (DB::table('notas_credito_debito')->whereNull('venta_id')->exists()) {
            throw new \RuntimeException(
                'Hay notas de crédito/débito sin venta_id (de compras o migradas). '.
                'Volver a NOT NULL las dejaría inconsistentes.'
            );
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE notas_credito_debito MODIFY venta_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
