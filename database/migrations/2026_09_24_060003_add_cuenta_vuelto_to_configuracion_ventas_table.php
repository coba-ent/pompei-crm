<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 110: cuenta de tesorería por defecto para los vueltos de cobranza.
 *
 * Sólo **preselecciona** el campo en el modal de cobranza: el operador puede elegir otra cuenta en
 * la operación puntual sin que eso modifique este valor (FR-010). Si queda en NULL, el operador
 * tiene que elegir la cuenta cada vez que cargue un vuelto.
 *
 * `nullOnDelete` como el resto de los defaults de esta tabla: si se elimina la cuenta, el default
 * se vacía y la configuración sigue siendo válida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_ventas', function (Blueprint $table) {
            $table->foreignId('cuenta_vuelto_id')->nullable()->after('dias_vto_cobro')
                ->constrained('cuentas_tesoreria')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_ventas', function (Blueprint $table) {
            $table->dropForeign(['cuenta_vuelto_id']);
            $table->dropColumn('cuenta_vuelto_id');
        });
    }
};
