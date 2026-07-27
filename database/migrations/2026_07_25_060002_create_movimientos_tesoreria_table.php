<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger de tesorería (spec 007). Una fila = un asiento en una cuenta; el signo
 * de `monto` da ingreso/egreso (data-model §2). Origen polimórfico para que
 * Ventas/Compras/Gastos enganchen luego sin que Tesorería los conozca (FR-030).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_tesoreria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_tesoreria_id')->constrained('cuentas_tesoreria')->cascadeOnDelete();
            $table->date('fecha');
            $table->enum('tipo', ['saldo_inicial', 'movimiento_entre_cuentas', 'cobro', 'pago', 'gasto']);
            $table->decimal('monto', 14, 2); // con signo: positivo = ingreso, negativo = egreso
            $table->string('detalle')->nullable();
            $table->string('nro_comprobante')->nullable();
            $table->text('observacion')->nullable();
            $table->string('transferencia_id')->nullable();
            $table->nullableMorphs('origen');
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cuenta_tesoreria_id', 'fecha', 'id']);
            $table->index('tipo');
            $table->index('transferencia_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_tesoreria');
    }
};
