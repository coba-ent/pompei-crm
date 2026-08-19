<?php

/**
 * SÓLO LECTURA — foto de arranque del chequeo diario.
 *
 *   php scripts/stock/resumen.php [ultimo_id_movimiento]
 *
 * El argumento es el corte del chequeo anterior (ver el registro en el SKILL). Sin él
 * muestra sólo el estado general, sin el delta.
 */

require __DIR__.'/_comun.php';

use Illuminate\Support\Facades\DB;

$corte = isset($argv[1]) ? (int) $argv[1] : null;
$dep = depositos();

titulo('Reloj');
printf("  ahora local (-03) : %s\n", DB::selectOne('SELECT NOW() h')->h);
printf("  ahora UTC         : %s   <- los created_at están en esta escala\n", DB::selectOne('SELECT UTC_TIMESTAMP() h')->h);

titulo('Movimientos de stock');
$total = DB::table('movimientos_stock')->count();
printf("  total : %d\n", $total);

if ($corte) {
    printf("  nuevos desde el corte %d : %d\n\n", $corte, DB::table('movimientos_stock')->where('id', '>', $corte)->count());

    $filas = DB::table('movimientos_stock')->where('id', '>', $corte)
        ->select('origen_type', 'tipo', 'deposito_id', DB::raw('COUNT(*) c'), DB::raw('SUM(cantidad) neto'))
        ->groupBy('origen_type', 'tipo', 'deposito_id')->get();

    foreach ($filas as $f) {
        printf("    %-28s %-8s %-8s %3d movs   neto %+s\n",
            $f->origen_type ?? 'SIN ORIGEN (ajuste manual)', $f->tipo,
            $dep[$f->deposito_id] ?? $f->deposito_id, $f->c, number_format((float) $f->neto, 0));
    }

    // Un ajuste sin origen ni descripción no lo generó ninguna operación del sistema.
    $sueltos = DB::table('movimientos_stock')->where('id', '>', $corte)
        ->where('tipo', 'ajuste')->whereNull('origen_type')->get();
    if ($sueltos->isNotEmpty()) {
        printf("\n  Ajustes manuales en el período (revisar quién y por qué):\n");
        foreach ($sueltos as $m) {
            printf("    #%-5s producto %-7s %-8s %6s  %s  usuario %s\n", $m->id, $m->producto_id,
                $dep[$m->deposito_id] ?? $m->deposito_id, number_format((float) $m->cantidad, 0),
                mb_substr($m->descripcion ?? '(sin descripción)', 0, 44), $m->usuario_id ?? 'NULL (script)');
        }
    }
}

printf("\n  PRÓXIMO CORTE: movimientos_stock.id = %d\n", DB::table('movimientos_stock')->max('id'));

titulo('Integraciones');
$cfg = DB::table('ml_configuracion')->first();
printf("  modo sólo lectura / creación automática : %d / %d\n", $cfg->modo_solo_lectura, $cfg->creacion_automatica);
printf("  órdenes  %s  %s\n", $cfg->ultima_sync_en, $cfg->ultima_sync_resultado);
printf("  stock    %s  %s\n", $cfg->stock_ultima_sync_en, $cfg->stock_ultima_sync_resultado);

$p = DB::table('ml_publicacion_producto')->selectRaw(
    'COUNT(*) t, SUM(stock_pendiente=1) pend, SUM(stock_requiere_intervencion=1) bloq, SUM(user_product_id IS NULL) sin_up'
)->first();
printf("  publicaciones: %d vinculadas, %d pendientes, %d bloqueadas, %d sin user_product_id\n",
    $p->t, $p->pend, $p->bloq, $p->sin_up);

$o = DB::table('ml_ordenes')->selectRaw('COUNT(*) t, SUM(venta_id IS NULL) sin')->first();
printf("  órdenes ML: %d, %d sin venta (las canceladas son normales)\n", $o->t, $o->sin);

titulo('Salud del stock');
$negativos = DB::table('stocks as s')->join('productos as p', 'p.id', '=', 's.producto_id')
    ->where('p.tipo', 'producto')->where('s.cantidad', '<', 0)->count();
printf("  filas de stock en negativo : %d\n", $negativos);
printf("  (una parte son servicios cargados como producto — no llevan inventario)\n");
