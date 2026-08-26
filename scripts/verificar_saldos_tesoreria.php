<?php

/**
 * Foto de control de Tesorería para correr ANTES y DESPUÉS de
 * `tesoreria:revincular-movimientos --aplicar`.
 *
 * La re-vinculación sólo escribe `origen_type`/`origen_id`: no toca un solo importe, así que las
 * dos fotos tienen que salir IDÉNTICAS. Si algún número se mueve, la corrida hizo algo que no
 * debía y hay que restaurar el backup.
 *
 * Uso:  php artisan tinker --execute="require 'scripts/verificar_saldos_tesoreria.php';"
 */

use Illuminate\Support\Facades\DB;

$saldos = DB::table('movimientos_tesoreria')
    ->whereNull('deleted_at')
    ->select('cuenta_tesoreria_id', DB::raw('COUNT(*) as movimientos'), DB::raw('SUM(monto) as saldo'))
    ->groupBy('cuenta_tesoreria_id')
    ->orderBy('cuenta_tesoreria_id')
    ->get();

echo str_pad('CUENTA', 10).str_pad('MOVIMIENTOS', 14).'SALDO'.PHP_EOL;
echo str_repeat('-', 45).PHP_EOL;

foreach ($saldos as $s) {
    echo str_pad((string) $s->cuenta_tesoreria_id, 10)
        .str_pad((string) $s->movimientos, 14)
        .number_format((float) $s->saldo, 2, ',', '.').PHP_EOL;
}

echo str_repeat('-', 45).PHP_EOL;
echo 'TOTAL MOVIMIENTOS: '.DB::table('movimientos_tesoreria')->whereNull('deleted_at')->count().PHP_EOL;
echo 'SUMA GENERAL:      '.number_format((float) DB::table('movimientos_tesoreria')->whereNull('deleted_at')->sum('monto'), 2, ',', '.').PHP_EOL;
echo 'PAGOS VIVOS:       '.DB::table('pagos')->whereNull('deleted_at')->count().PHP_EOL;
echo 'COBROS VIVOS:      '.DB::table('cobros')->whereNull('deleted_at')->count().PHP_EOL;
echo 'HUÉRFANOS pago:    '.DB::table('movimientos_tesoreria')->where('tipo', 'pago')->whereNull('origen_type')->whereNull('deleted_at')->count().PHP_EOL;
echo 'HUÉRFANOS cobro:   '.DB::table('movimientos_tesoreria')->where('tipo', 'cobro')->whereNull('origen_type')->whereNull('deleted_at')->count().PHP_EOL;
