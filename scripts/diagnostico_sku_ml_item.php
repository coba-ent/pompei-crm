$cliente = app(\App\Services\MercadoLibre\ClienteMercadoLibre::class);

echo "=== GET /items/MLA3690559588 ===\n";
$resp = $cliente->obtener('diagnostico_sku', '/items/MLA3690559588');
if ($resp->fallo()) {
    echo "FALLO: " . $resp->mensajeError . "\n";
} else {
    $item = $resp->datos;
    echo "seller_id: " . ($item['seller_id'] ?? 'N/A') . "\n";
    echo "seller_custom_field: " . var_export($item['seller_custom_field'] ?? null, true) . "\n";
    $skuAttr = collect($item['attributes'] ?? [])->first(fn ($a) => ($a['id'] ?? null) === 'SELLER_SKU');
    echo "attributes[SELLER_SKU]: " . var_export($skuAttr, true) . "\n";
    echo "variations count: " . count($item['variations'] ?? []) . "\n";
    foreach (($item['variations'] ?? []) as $v) {
        $vSku = collect($v['attributes'] ?? [])->first(fn ($a) => ($a['id'] ?? null) === 'SELLER_SKU');
        echo "  variation id={$v['id']} seller_custom_field=" . var_export($v['seller_custom_field'] ?? null, true) . " attr_sku=" . var_export($vSku, true) . "\n";
    }
}

echo "\n=== GET /users/{seller}/items/search?seller_sku=9006 ===\n";
$sellerId = $resp->fallo() ? null : ($resp->datos['seller_id'] ?? null);
if ($sellerId) {
    $resp2 = $cliente->obtener('diagnostico_sku_search', "/users/{$sellerId}/items/search", ['seller_sku' => '9006']);
    if ($resp2->fallo()) {
        echo "FALLO: " . $resp2->mensajeError . "\n";
    } else {
        echo "paging: " . json_encode($resp2->datos['paging'] ?? null) . "\n";
        echo "results: " . json_encode($resp2->datos['results'] ?? null) . "\n";
    }
} else {
    echo "No se pudo determinar seller_id.\n";
}
