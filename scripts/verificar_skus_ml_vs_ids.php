<?php
/**
 * Verificacion de seguridad ANTES de remapear productos.id: recorre el catalogo
 * REAL de Mercado Libre (misma logica que VinculadorAutomatico::recorrerCatalogo
 * + detalleDePublicaciones) y compara cada SELLER_SKU contra:
 *   (a) productos.id actual (el roto, secuencial 1..9181)
 *   (b) el Id que se le asignaria tras el fix (derivado de productos.codigo)
 * Sin escribir nada en la base. Pedido explicito del usuario el 06/08/2026 antes
 * de autorizar el remapeo masivo de Ids.
 */

$cliente = app(\App\Services\MercadoLibre\ClienteMercadoLibre::class);
$sellerId = \App\Models\Integraciones\MercadoLibreCuenta::conectada()->value('ml_user_id');

echo "Seller ID: $sellerId\n";

$ids = [];
$scrollId = null;
do {
    $opciones = ['search_type' => 'scan'];
    if ($scrollId !== null) {
        $opciones['scroll_id'] = $scrollId;
    }
    $respuesta = $cliente->obtener('verificacion_skus_scan', "/users/{$sellerId}/items/search", $opciones);
    if ($respuesta->fallo()) {
        echo 'ERROR scan: '.$respuesta->mensajeError."\n";
        exit(1);
    }
    $resultados = $respuesta->datos['results'] ?? [];
    array_push($ids, ...$resultados);
    $scrollId = $respuesta->datos['scroll_id'] ?? null;
} while ($resultados !== []);

echo 'Total publicaciones en ML: '.count($ids)."\n";

$skus = [];
foreach (array_chunk($ids, 20) as $chunk) {
    $respuesta = $cliente->obtener('verificacion_skus_multiget', '/items', ['ids' => implode(',', $chunk)]);
    if ($respuesta->fallo()) {
        echo 'ERROR multiget: '.$respuesta->mensajeError."\n";
        continue;
    }
    foreach ($respuesta->datos as $entrada) {
        $item = $entrada['body'] ?? [];
        if (($item['status'] ?? null) === 'closed' || ! empty($item['variations'])) {
            continue;
        }
        $sku = collect($item['attributes'] ?? [])
            ->first(fn ($a) => ($a['id'] ?? null) === 'SELLER_SKU')['value_name'] ?? null;
        if ($sku === null || trim((string) $sku) === '') {
            continue;
        }
        $skus[] = ['ml_item_id' => $item['id'], 'sku' => trim((string) $sku), 'titulo' => $item['title'] ?? ''];
    }
}

echo 'Publicaciones con SKU (excluye closed/variantes/sin sku): '.count($skus)."\n\n";

// Mapa productos.id actual (roto) -> producto
$idsActuales = \App\Models\Producto::pluck('id')->flip();

// Mapa codigo-derivado (lo que seria el Id correcto post-fix) -> producto
$idsCorregidos = [];
foreach (\App\Models\Producto::pluck('codigo', 'id') as $idActual => $codigo) {
    if (preg_match('/^(\d+)/', trim((string) $codigo), $m)) {
        $idsCorregidos[(int) $m[1]] = $idActual;
    }
}

$matchActual = 0;
$matchCorregido = 0;
$sinMatchNinguno = [];

foreach ($skus as $s) {
    $skuInt = (int) $s['sku'];
    $enActual = isset($idsActuales[$skuInt]);
    $enCorregido = isset($idsCorregidos[$skuInt]);
    if ($enActual) {
        $matchActual++;
    }
    if ($enCorregido) {
        $matchCorregido++;
    }
    if (! $enActual && ! $enCorregido) {
        $sinMatchNinguno[] = $s;
    }
}

echo "=== RESULTADO ===\n";
echo 'SKUs de ML que matchean el productos.id ACTUAL (roto): '.$matchActual.' / '.count($skus)."\n";
echo 'SKUs de ML que matchearian el Id CORREGIDO (post-fix, derivado de codigo): '.$matchCorregido.' / '.count($skus)."\n";
echo 'SKUs que NO matchean ni antes ni despues ('.count($sinMatchNinguno).'):'."\n";
foreach (array_slice($sinMatchNinguno, 0, 30) as $s) {
    echo "  - sku={$s['sku']}  ml_item_id={$s['ml_item_id']}  titulo={$s['titulo']}\n";
}
