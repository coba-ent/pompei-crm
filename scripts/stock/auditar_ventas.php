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

/**
 * Segundo pase — el punto ciego del primero.
 *
 * Arriba se audita cada Venta contra los movimientos que cuelgan de ELLA. Un ajuste posterior
 * que anule esa salida no aparece, porque pertenece a otro origen (o a ninguno). Fue exactamente
 * lo que tapó la venta 24587 del 19/08: descontó 1 de Full y cuatro minutos después el reflejo
 * de Full lo devolvió, dejando la unidad vendida sin descontar de ningún lado. El primer pase le
 * puso OK.
 *
 * Acá se mira el NETO por producto en toda la ventana, venga de donde venga el movimiento, y se
 * compara contra lo que las Ventas del período dicen haber vendido.
 */
titulo('Neto por producto en la ventana (detecta ajustes que anulan ventas)');

$vendido = [];
foreach ($ventas as $v) {
    foreach (DB::table('venta_items as i')->join('productos as p', 'p.id', '=', 'i.producto_id')
        ->where('i.venta_id', $v->id)->where('p.tipo', '!=', 'servicio')
        ->select('i.producto_id', 'i.cantidad')->get() as $it) {
        $vendido[$it->producto_id] = ($vendido[$it->producto_id] ?? 0) + (float) $it->cantidad;
    }
}

$sospechosos = [];

foreach ($vendido as $pid => $unidades) {
    $movs = DB::table('movimientos_stock')->where('producto_id', $pid)
        ->where('created_at', '>=', $desde)->get();

    $neto = $movs->sum(fn ($m) => (float) $m->cantidad);
    $compras = $movs->filter(fn ($m) => str_contains((string) $m->origen_type, 'Compra'))
        ->sum(fn ($m) => (float) $m->cantidad);

    // Lo esperado: bajó al menos lo vendido, salvo que hayan entrado compras en el medio.
    $esperado = $compras - $unidades;

    if (abs($neto - $esperado) < 0.005) {
        continue;
    }

    $nombre = DB::table('productos')->where('id', $pid)->value('nombre');
    $sospechosos[] = sprintf('  %-7d %-40s vendió %s → neto real %s (esperado %s)',
        $pid, mb_substr($nombre ?? '?', 0, 40), number_format($unidades, 0),
        number_format($neto, 0), number_format($esperado, 0));

    foreach ($movs as $m) {
        $sospechosos[] = sprintf('              #%-5s %-8s %6s  %s', $m->id, $m->tipo,
            number_format((float) $m->cantidad, 0),
            mb_substr($m->descripcion ?? ('origen '.$m->origen_type.'#'.$m->origen_id), 0, 56));
    }
}

if ($sospechosos === []) {
    printf("  Sin anomalías: el stock bajó lo que se vendió en los %d productos del período.\n", count($vendido));
} else {
    printf("  %d producto(s) donde el neto NO coincide con lo vendido:\n\n", count(array_filter($sospechosos, fn ($l) => ! str_starts_with($l, '              '))));
    foreach ($sospechosos as $s) {
        echo "$s\n";
    }
}
