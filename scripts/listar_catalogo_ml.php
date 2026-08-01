$cliente = app(\App\Services\MercadoLibre\ClienteMercadoLibre::class);
$cuenta = \App\Models\Integraciones\MercadoLibreCuenta::conectada()->first();

if (! $cuenta) {
    echo "No hay cuenta de Mercado Libre conectada.\n";
    exit;
}

echo "Cuenta conectada: {$cuenta->nickname} (ml_user_id={$cuenta->ml_user_id})\n\n";

echo "=== Paginando /users/{$cuenta->ml_user_id}/items/search ===\n";
$ids = [];
$offset = 0;
do {
    $resp = $cliente->obtener('listar_catalogo', "/users/{$cuenta->ml_user_id}/items/search", ['offset' => $offset, 'limit' => 50]);
    if ($resp->fallo()) {
        echo "FALLO paginando: " . $resp->mensajeError . "\n";
        break;
    }
    $resultados = $resp->datos['results'] ?? [];
    $total = $resp->datos['paging']['total'] ?? 0;
    $ids = array_merge($ids, $resultados);
    echo "  offset={$offset} trajo " . count($resultados) . " ids (total reportado: {$total})\n";
    $offset += 50;
} while ($offset < $total);

echo "\nTotal ids recolectados: " . count($ids) . "\n";

if (empty($ids)) {
    echo "Sin items para multiget.\n";
    exit;
}

echo "\n=== Multiget /items?ids=... (chunks de 20) ===\n";
foreach (array_chunk($ids, 20) as $chunk) {
    $resp = $cliente->obtener('listar_catalogo_multiget', '/items', ['ids' => implode(',', $chunk)]);
    if ($resp->fallo()) {
        echo "FALLO multiget: " . $resp->mensajeError . "\n";
        continue;
    }
    foreach ($resp->datos as $entrada) {
        $codigo = $entrada['code'] ?? null;
        $body = $entrada['body'] ?? [];
        if ($codigo !== 200) {
            echo "  " . ($body['id'] ?? '?') . " -> error http {$codigo}\n";
            continue;
        }
        $skuAttr = collect($body['attributes'] ?? [])->first(fn ($a) => ($a['id'] ?? null) === 'SELLER_SKU');
        $sku = $skuAttr['value_name'] ?? null;
        $nVariaciones = count($body['variations'] ?? []);
        echo "  {$body['id']} | status={$body['status']} | title=\"" . ($body['title'] ?? '') . "\" | SKU=" . var_export($sku, true) . " | variaciones={$nVariaciones}\n";
        if ($nVariaciones > 0) {
            foreach ($body['variations'] as $v) {
                $vSku = collect($v['attributes'] ?? [])->first(fn ($a) => ($a['id'] ?? null) === 'SELLER_SKU');
                echo "      variacion {$v['id']} SKU=" . var_export($vSku['value_name'] ?? null, true) . "\n";
            }
        }
    }
}
