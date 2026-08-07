<?php

use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Services\MercadoLibre\ClienteMercadoLibre;

$ids = MercadoLibrePublicacionProducto::pluck('ml_item_id')->unique()->values()->all();

if (empty($ids)) {
    echo "No hay publicaciones vinculadas en ml_publicacion_producto.\n";
    return;
}

echo count($ids)." publicaciones vinculadas.\n";

$cliente = app(ClienteMercadoLibre::class);
$chunks = array_chunk($ids, 20);

foreach ($chunks as $chunk) {
    $respuesta = $cliente->obtener('debug_listing_type', '/items', [
        'ids' => implode(',', $chunk),
        'omitir_guard_funcion' => true,
    ]);

    if ($respuesta->fallo()) {
        echo "Error consultando /items: ".$respuesta->mensajeError."\n";
        continue;
    }

    foreach ($respuesta->datos as $entry) {
        $body = $entry['body'] ?? [];
        $id = $body['id'] ?? ($entry['code'] ?? 'desconocido');
        $listingType = $body['listing_type_id'] ?? 'N/D';
        $titulo = $body['title'] ?? '';
        echo "$id | listing_type_id=$listingType | $titulo\n";
    }
}
