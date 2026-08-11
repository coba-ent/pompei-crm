<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `legacy_id` en compras — el número que el comprobante tenía en Contagram.
 *
 * No es sólo la clave de idempotencia del importador. Es un dato **de consulta permanente**: los
 * comprobantes en papel, los remitos y los reclamos de proveedores traen el número viejo, así que
 * hay que poder encontrar la compra por ese número años después. Mismo criterio que en ventas.
 *
 * Formato `{año}-{familia}-{Id}` (ej. `2023-FC-850`): el Id de Contagram se repite entre años y
 * entre familias de comprobante, así que solo no identifica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->string('legacy_id', 40)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
