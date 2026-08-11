<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prepara `movimientos_tesoreria` para recibir los 48.222 movimientos históricos de Contagram.
 *
 * **1. `legacy_id`** — clave de idempotencia. No puede ser el `Id` de Contagram: ese Id identifica
 * la entidad de origen (el cobro, el pago), no el movimiento, y **se repite 22.823 veces** sobre
 * 48.222 filas. Un mismo "Movimiento entre Cuentas" aparece además dos veces, una en la cuenta que
 * envía y otra en la que recibe, con el mismo Id. La combinación `cuenta + operación + Id + monto`
 * sí es única (verificado: 48.222 claves para 48.222 filas), y es la que se usa.
 *
 * **2. `ingreso` en el enum `tipo`** — Contagram tiene 61 movimientos de operación "Ingreso" (los
 * "Otros Ingresos" del CRM) y el enum no los contemplaba. Mapearlos a `cobro` sería más simple pero
 * los mezclaría con los cobros de ventas y distorsionaría cualquier informe que los separe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_tesoreria', function (Blueprint $table) {
            $table->string('legacy_id', 64)->nullable()->unique()->after('id');
        });

        // `change()` en vez de un ALTER ... MODIFY crudo: SQLite (tests) no entiende esa
        // sintaxis y además sí valida el enum con un CHECK, así que el valor 'ingreso'
        // tiene que quedar declarado en los dos motores, no sólo en MySQL.
        Schema::table('movimientos_tesoreria', function (Blueprint $table) {
            $table->enum('tipo', [
                'saldo_inicial', 'movimiento_entre_cuentas', 'cobro', 'pago', 'gasto', 'ingreso',
            ])->change();
        });
    }

    public function down(): void
    {
        if (DB::table('movimientos_tesoreria')->where('tipo', 'ingreso')->exists()) {
            throw new \RuntimeException(
                'Hay movimientos de tipo "ingreso"; sacarlos del enum los dejaría inválidos.'
            );
        }

        Schema::table('movimientos_tesoreria', function (Blueprint $table) {
            $table->enum('tipo', [
                'saldo_inicial', 'movimiento_entre_cuentas', 'cobro', 'pago', 'gasto',
            ])->change();
        });

        Schema::table('movimientos_tesoreria', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
