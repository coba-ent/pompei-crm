echo "=== ANTES ===\n";
echo "Ventas: " . \App\Models\Venta::withTrashed()->count() . "\n";
echo "Vinculaciones ML: " . \App\Models\Integraciones\MercadoLibrePublicacionProducto::count() . " | TN: " . \App\Models\Integraciones\TiendanubeVarianteProducto::count() . "\n";
echo "Ordenes ML: " . \App\Models\Integraciones\MercadoLibreOrden::count() . " (convertidas: " . \App\Models\Integraciones\MercadoLibreOrden::where('estado_conversion', 'convertida')->count() . ")\n";
echo "Ordenes TN: " . \App\Models\Integraciones\TiendanubeOrden::count() . " (convertidas: " . \App\Models\Integraciones\TiendanubeOrden::where('estado_conversion', 'convertida')->count() . ")\n";

echo "\n=== Borrando ventas (revierte stock/cobros/tesoreria via VentaObserver, despues purga fisica) ===\n";
$totalVentas = \App\Models\Venta::withTrashed()->count();
\App\Models\Venta::withTrashed()->get()->each(function ($venta) {
    if (! $venta->trashed()) {
        $venta->delete();
    }
});
\App\Models\Venta::onlyTrashed()->forceDelete();
echo "Ventas borradas: $totalVentas\n";

echo "\n=== Borrando vinculaciones ===\n";
$vML = \App\Models\Integraciones\MercadoLibrePublicacionProducto::count();
$vTN = \App\Models\Integraciones\TiendanubeVarianteProducto::count();
\App\Models\Integraciones\MercadoLibrePublicacionProducto::query()->delete();
\App\Models\Integraciones\TiendanubeVarianteProducto::query()->delete();
echo "Vinculaciones ML borradas: $vML | TN borradas: $vTN\n";

echo "\n=== Desvinculando items de ordenes (producto_id -> null, ya no hay vinculaciones) ===\n";
\App\Models\Integraciones\MercadoLibreOrdenItem::query()->update(['producto_id' => null]);
\App\Models\Integraciones\TiendanubeOrdenItem::query()->update(['producto_id' => null]);

echo "\n=== Recalculando estado_conversion de ordenes existentes ===\n";
$evaluadorMl = app(\App\Services\MercadoLibre\EvaluadorConvertibilidad::class);
$n = 0;
\App\Models\Integraciones\MercadoLibreOrden::with('items')->chunk(50, function ($ordenes) use ($evaluadorMl, &$n) {
    foreach ($ordenes as $orden) {
        [$estado, $motivo, $detalle] = $evaluadorMl->evaluar($orden, false);
        $orden->update([
            'estado_conversion' => $estado->value,
            'motivo' => $motivo?->value,
            'motivo_detalle' => $detalle,
            'creacion_automatica' => false,
            'convertida_en' => null,
            'convertida_por' => null,
        ]);
        $n++;
    }
});
echo "Ordenes ML recalculadas: $n\n";

$evaluadorTn = app(\App\Services\Tiendanube\EvaluadorConvertibilidad::class);
$n = 0;
\App\Models\Integraciones\TiendanubeOrden::with('items')->chunk(50, function ($ordenes) use ($evaluadorTn, &$n) {
    foreach ($ordenes as $orden) {
        [$estado, $motivo, $detalle] = $evaluadorTn->evaluar($orden, false);
        $orden->update([
            'estado_conversion' => $estado->value,
            'motivo' => $motivo?->value,
            'motivo_detalle' => $detalle,
            'creacion_automatica' => false,
            'convertida_en' => null,
            'convertida_por' => null,
        ]);
        $n++;
    }
});
echo "Ordenes TN recalculadas: $n\n";

echo "\n=== Reiniciando marca de ultima sincronizacion ===\n";
\App\Models\Integraciones\MercadoLibreConfiguracion::actual()->update(['ultima_sync_en' => null, 'ultima_sync_resultado' => null]);
\App\Models\Integraciones\TiendanubeConfiguracion::actual()->update(['ultima_sync_en' => null, 'ultima_sync_resultado' => null]);

echo "\n=== DESPUES ===\n";
echo "Ventas: " . \App\Models\Venta::withTrashed()->count() . "\n";
echo "Vinculaciones ML: " . \App\Models\Integraciones\MercadoLibrePublicacionProducto::count() . " | TN: " . \App\Models\Integraciones\TiendanubeVarianteProducto::count() . "\n";
echo "Ordenes ML listas para convertir: " . \App\Models\Integraciones\MercadoLibreOrden::where('estado_conversion', 'lista')->count() . "\n";
echo "Ordenes TN listas para convertir: " . \App\Models\Integraciones\TiendanubeOrden::where('estado_conversion', 'lista')->count() . "\n";
echo "\nListo.\n";
