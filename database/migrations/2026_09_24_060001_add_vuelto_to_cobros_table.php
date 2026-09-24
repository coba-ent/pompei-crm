<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 110: vuelto entregado al cliente dentro de la propia cobranza.
 *
 * Puramente aditiva: dos columnas nullable. Los cobros existentes quedan con ambas en NULL, que es
 * el estado "cobranza sin vuelto" y se comporta exactamente como hasta ahora (FR-014).
 *
 * **`cobros.monto` pasa a guardar el NETO imputado a la venta, no el importe recibido**: cuando hay
 * vuelto, lo que el cliente entregó es `monto + vuelto`. Se eligió así para que la fórmula de saldo
 * (`total + ND − NC − cobrado`) siga siendo correcta sin tocar ninguna de sus 5 réplicas SQL — ver
 * el incidente documentado en App\Services\Ingresos\SqlCredito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros', function (Blueprint $table) {
            $table->decimal('vuelto', 14, 2)->nullable()->after('monto');
            // restrictOnDelete como `cuenta_tesoreria_id`: una cuenta con movimientos no se elimina.
            $table->foreignId('cuenta_vuelto_id')->nullable()->after('vuelto')
                ->constrained('cuentas_tesoreria')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cobros', function (Blueprint $table) {
            $table->dropForeign(['cuenta_vuelto_id']);
            $table->dropColumn(['vuelto', 'cuenta_vuelto_id']);
        });
    }
};
