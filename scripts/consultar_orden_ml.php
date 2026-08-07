<?php
$cliente = app(\App\Services\MercadoLibre\ClienteMercadoLibre::class);
$r = $cliente->obtener('consulta_manual_orden', '/orders/2000017799757158', []);
if ($r->fallo()) {
    echo 'ERROR: '.$r->mensajeError.PHP_EOL;
} else {
    echo json_encode($r->datos, JSON_PRETTY_PRINT).PHP_EOL;
}
