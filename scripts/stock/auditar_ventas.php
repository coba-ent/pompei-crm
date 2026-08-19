<?php

/**
 * SÓLO LECTURA — ¿cada Venta descontó lo que debía, del depósito que debía?
 *
 *   php scripts/stock/auditar_ventas.php [fecha_desde_utc]
 *   php scripts/stock/auditar_ventas.php "2026-08-19 00:00:00"
 *
 * Sin argumento toma las últimas 24 horas. La fecha es UTC: `created_at` se guarda en
 * UTC y la app muestra en hora argentina (-03), así que "desde ayer a las 21" en pantalla
 * es "desde hoy a las 00" acá.
 *
 * Compara por NETO: una Venta editada genera entrada + salida que se cancelan, y mirar
 * los movimientos sueltos daría un falso positivo.
 */

require __DIR__.'/_comun.php';

use Illuminate\Support\Facades\DB;

$desde = $argv[1] ?? now()->subDay()->toDateTimeString();
$dep = depositos();

$ventas = DB::table('ventas')->where('created_at', '>=', $desde)->whereNull('deleted_at')
    ->orderBy('id')->get();

titulo(sprintf('Ventas creadas desde %s UTC: %d', $desde, $ventas->count()));

$ok = 0;
$problemas = [];

foreach ($ventas as $v) {
    $items = DB::table('venta_items as i')
        ->join('productos as p', 'p.id', '=', 'i.producto_id')
        ->where('i.venta_id', $v->id)
        ->select('i.producto_id', 'i.cantidad', 'p.nombre', 'p.tipo')->get();

    $neto = [];
    foreach (movimientosDe('Venta', $v->id) as $m) {
        $k = $m->producto_id.'|'.$m->deposito_id;
        $neto[$k] = ($neto[$k] ?? 0) + (float) $m->cantidad;
    }

    printf("\n  venta %-7s %-13s %s  depósito %s\n", $v->id, $v->origen,
        substr($v->created_at, 11, 8), $dep[$v->deposito_id] ?? ($v->deposito_id ?? 'NULL'));

    foreach ($items as $it) {
        // Un servicio no lleva inventario: que no mueva stock es lo correcto.
        if ($it->tipo === 'servicio') {
            printf("        --  %-7d %-38s (servicio, no mueve stock)\n", $it->producto_id, mb_substr($it->nombre, 0, 38));

            continue;
        }

        $esperado = -1 * (float) $it->cantidad;
        $real = $neto[$it->producto_id.'|'.$v->deposito_id] ?? null;
        $bien = $real !== null && abs($real - $esperado) < 0.005;

        $bien ? $ok++ : $problemas[] = "venta {$v->id} / producto {$it->producto_id}";

        printf("        %s  %-7d %-38s vendió %s → movió %s\n", $bien ? 'OK' : '!!', $it->producto_id,
            mb_substr($it->nombre, 0, 38), number_format((float) $it->cantidad, 0),
            $real === null ? 'NADA' : number_format($real, 0));
    }

    // Caso inverso: stock movido sobre productos que no están en la Venta.
    foreach ($neto as $k => $cant) {
        [$pid, $d] = explode('|', $k);
        if (abs($cant) < 0.005 || $items->firstWhere('producto_id', (int) $pid)) {
            continue;
        }
        $problemas[] = "venta {$v->id} / movimiento huérfano producto {$pid}";
        printf("        !!  %-7d %-38s SIN ÍTEM, movió %s en %s\n", $pid, '',
            number_format($cant, 0), $dep[(int) $d] ?? $d);
    }
}

printf("\n\nLíneas correctas: %d\nProblemas: %d\n", $ok, count($problemas));
foreach ($problemas as $p) {
    echo "  - $p\n";
}
