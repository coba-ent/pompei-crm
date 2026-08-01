$cliente = app(\App\Services\MercadoLibre\ClienteMercadoLibre::class);
$cuenta = \App\Models\Integraciones\MercadoLibreCuenta::conectada()->first();

echo "=== Primer llamado: search_type=scan, sin scroll_id ===\n";
$resp = $cliente->obtener('diagnostico_scan', "/users/{$cuenta->ml_user_id}/items/search", ['search_type' => 'scan']);
if ($resp->fallo()) {
    echo "FALLO: " . $resp->mensajeError . "\n";
    exit;
}
echo "paging: " . json_encode($resp->datos['paging'] ?? null) . "\n";
echo "scroll_id presente: " . (isset($resp->datos['scroll_id']) ? 'SI' : 'NO') . "\n";
echo "scroll_id: " . ($resp->datos['scroll_id'] ?? 'N/A') . "\n";
echo "results: " . json_encode($resp->datos['results'] ?? null) . "\n";

$scrollId = $resp->datos['scroll_id'] ?? null;
if ($scrollId) {
    echo "\n=== Segundo llamado: mismo scroll_id ===\n";
    $resp2 = $cliente->obtener('diagnostico_scan_2', "/users/{$cuenta->ml_user_id}/items/search", ['search_type' => 'scan', 'scroll_id' => $scrollId]);
    if ($resp2->fallo()) {
        echo "FALLO: " . $resp2->mensajeError . "\n";
    } else {
        echo "paging: " . json_encode($resp2->datos['paging'] ?? null) . "\n";
        echo "results: " . json_encode($resp2->datos['results'] ?? null) . "\n";
        echo "scroll_id nuevo igual al anterior: " . (($resp2->datos['scroll_id'] ?? null) === $scrollId ? 'SI' : 'NO') . "\n";
    }
}
