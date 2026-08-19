<?php

/**
 * SÓLO LECTURA — stock del CRM contra lo que Mercado Libre publica de verdad.
 *
 *   php scripts/stock/comparar_mercadolibre.php
 *
 * Es el ÚNICO chequeo que detecta una publicación congelada: si nadie la marcó como
 * pendiente, el CRM la da por sincronizada y ningún indicador interno la denuncia.
 *
 * El depósito a comparar depende del tipo de publicación:
 *   - Full     → se compara contra la ubicación `selling_address` del user product,
 *                que es la parte escribible. `meli_facility` la administra Mercado Libre.
 *   - el resto → depósito general contra `available_quantity` del ítem.
 * Comparar todo contra el depósito general marca las Full como desfasadas sin estarlo.
 */

require __DIR__.'/_comun.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$token = mlToken();
$depMl = depositoMlId();
$depFull = depositoFullId();

$pubs = DB::table('ml_publicacion_producto as p')
    ->leftJoin('stocks as s', function ($j) use ($depMl) { $j->on('s.producto_id', '=', 'p.producto_id')->where('s.deposito_id', $depMl); })
    ->leftJoin('stocks as f', function ($j) use ($depFull) { $j->on('f.producto_id', '=', 'p.producto_id')->where('f.deposito_id', $depFull ?? 0); })
    ->select('p.ml_item_id', 'p.titulo_ml', 'p.producto_id', 'p.logistic_type', 'p.user_product_id',
        'p.stock_requiere_intervencion', 'p.stock_pendiente',
        DB::raw('COALESCE(s.cantidad,0) as general'), DB::raw('COALESCE(f.cantidad,0) as full'))
    ->get();

$coinciden = 0;
$desfasadas = [];
$fallidas = 0;
$noActivas = 0;

foreach ($pubs->chunk(20) as $grupo) {
    $r = Http::withToken($token)->get('https://api.mercadolibre.com/items', [
        'ids' => $grupo->pluck('ml_item_id')->implode(','),
        'attributes' => 'id,available_quantity,status,sub_status,user_product_id',
    ]);

    if (! $r->successful()) {
        $fallidas += $grupo->count();

        continue;
    }

    $porId = [];
    foreach ($r->json() as $fila) {
        if (($fila['code'] ?? 200) !== 200) {
            continue;
        }
        $porId[$fila['body']['id']] = $fila['body'];
    }

    foreach ($grupo as $p) {
        $b = $porId[$p->ml_item_id] ?? null;
        if (! $b) {
            $fallidas++;

            continue;
        }

        $esFull = $p->logistic_type === 'fulfillment';
        // ML nunca publica negativos: un −3 en el CRM se corresponde con 0 allá.
        $crm = max(0, (int) round((float) $p->general));

        if ($esFull) {
            $up = $p->user_product_id ?: ($b['user_product_id'] ?? null);
            $ml = null;
            if ($up) {
                $loc = Http::withToken($token)->get("https://api.mercadolibre.com/user-products/{$up}/stock")->json();
                foreach ($loc['locations'] ?? [] as $l) {
                    if (($l['type'] ?? '') === 'selling_address') {
                        $ml = (int) $l['quantity'];
                    }
                }
            }
            if ($ml === null) {
                $fallidas++;

                continue;
            }
        } else {
            $ml = (int) ($b['available_quantity'] ?? 0);
        }

        if (($b['status'] ?? '') !== 'active') {
            $noActivas++;
        }

        if ($crm === $ml) {
            $coinciden++;

            continue;
        }

        $desfasadas[] = sprintf('  %-14s %-32s %-8s CRM %5d → ML %5d   %-9s %-14s%s',
            $p->ml_item_id, mb_substr($p->titulo_ml ?? '', 0, 32), $esFull ? 'FULL' : 'normal',
            (int) round((float) $p->general), $ml, $b['status'] ?? '?',
            implode(',', $b['sub_status'] ?? []),
            $p->stock_requiere_intervencion ? '  [BLOQUEADA]' : '');
    }
}

titulo('CRM vs Mercado Libre');
printf("  publicaciones vinculadas : %d\n", $pubs->count());
printf("  coinciden                : %d\n", $coinciden);
printf("  DESFASADAS               : %d\n", count($desfasadas));
printf("  no activas en ML         : %d\n", $noActivas);
printf("  consultas fallidas       : %d\n", $fallidas);

if ($desfasadas !== []) {
    titulo('Desfasadas');
    foreach ($desfasadas as $d) {
        echo "$d\n";
    }
    echo "\n  Una desfasada SIN [BLOQUEADA] suele ser una publicación congelada:\n";
    echo "  destrabarla con  UPDATE ml_publicacion_producto SET stock_pendiente = 1 WHERE ml_item_id = '...';\n";
}
