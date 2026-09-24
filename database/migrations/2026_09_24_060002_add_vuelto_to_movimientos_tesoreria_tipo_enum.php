<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 110: valor `vuelto` en el enum de `movimientos_tesoreria.tipo`.
 *
 * Es el egreso por la plata devuelta al cliente cuando paga con un medio que el CRM no registra tal
 * cual (caso relevado: dólares). Monto **negativo**, como `pago` y `gasto`.
 *
 * **Por qué un tipo propio y no `gasto`**: mapearlo a `gasto` contaminaría el informe de Gastos con
 * plata que no se gastó, que es justamente el problema que esta spec vino a eliminar (FR-005).
 *
 * Se usa `->change()` y no un `ALTER ... MODIFY` crudo, igual que la migración que agregó
 * `ingreso`: SQLite (los tests) no entiende esa sintaxis y **sí valida el enum con un CHECK
 * constraint**, así que el valor nuevo tiene que quedar declarado en los dos motores. Con el ALTER
 * crudo los tests fallaban con "CHECK constraint failed: tipo".
 */
return new class extends Migration
{
    private const TIPOS_ANTERIORES = ['saldo_inicial', 'movimiento_entre_cuentas', 'cobro', 'pago', 'gasto', 'ingreso'];

    private const TIPOS_NUEVOS = ['saldo_inicial', 'movimiento_entre_cuentas', 'cobro', 'pago', 'gasto', 'ingreso', 'vuelto'];

    public function up(): void
    {
        Schema::table('movimientos_tesoreria', function (Blueprint $table) {
            $table->enum('tipo', self::TIPOS_NUEVOS)->change();
        });
    }

    /**
     * Revertir con filas `tipo='vuelto'` vivas las dejaría inválidas, así que se aborta. Quien
     * revierta tiene que reasignarlas o eliminarlas a mano y evaluar el impacto en los saldos — no
     * se hace en silencio acá porque tocar movimientos de tesorería descuadra las cuentas.
     */
    public function down(): void
    {
        if (DB::table('movimientos_tesoreria')->where('tipo', 'vuelto')->exists()) {
            throw new \RuntimeException(
                'Hay movimientos de tipo "vuelto"; sacarlos del enum los dejaría inválidos. '.
                'Reasignalos o eliminalos antes de revertir (y revisá el impacto en los saldos).'
            );
        }

        Schema::table('movimientos_tesoreria', function (Blueprint $table) {
            $table->enum('tipo', self::TIPOS_ANTERIORES)->change();
        });
    }
};
