<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aplicación de saldo a favor (spec 072).
 *
 * Registra que el saldo a favor de un comprobante —el que le dejó una Nota de Crédito— se imputó a
 * otro comprobante del mismo cliente/proveedor. **No es dinero**: no tiene cuenta de tesorería y no
 * genera `movimientos_tesoreria`. Es una transferencia de saldo entre dos comprobantes, y por eso
 * el saldo de cuenta corriente del cliente queda idéntico antes y después (FR-003a).
 *
 * Sin esta tabla el único camino para imputar el crédito era borrar la cobranza vieja, que es lo
 * que hacía desaparecer la plata del registro (caso FLORENCIA, 20/08/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicaciones_credito', function (Blueprint $tabla) {
            $tabla->id();

            // Morph en los DOS extremos: ambos son Venta o ambos son Compra (nunca cruzados).
            // Ver data-model.md — cuatro columnas nullables con reglas cruzadas sería más frágil.
            $tabla->string('origen_type');
            $tabla->unsignedBigInteger('origen_id');
            $tabla->string('destino_type');
            $tabla->unsignedBigInteger('destino_id');

            // NC del origen que justifica el crédito. Nullable: si el origen tiene varias se guarda
            // la más antigua con remanente, y queda nulo si no puede atribuirse a ninguna.
            $tabla->foreignId('nota_credito_debito_id')->nullable()
                ->constrained('notas_credito_debito')->nullOnDelete();

            $tabla->decimal('monto', 14, 2);
            $tabla->date('fecha');
            $tabla->text('nota')->nullable();

            // Auditoría: quién la aplicó.
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();

            $tabla->timestamps();

            // Borrado lógico (constitución, principio III): anular una aplicación libera el crédito
            // en el origen sin perder la evidencia de que existió.
            $tabla->softDeletes();

            $tabla->index(['origen_type', 'origen_id']);
            $tabla->index(['destino_type', 'destino_id']);
            $tabla->index('nota_credito_debito_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_credito');
    }
};
