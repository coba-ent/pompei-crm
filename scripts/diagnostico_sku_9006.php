echo "Producto id=9006 en CRM:\n";
$producto = \App\Models\Producto::find(9006);
echo $producto ? ("  Existe: " . $producto->nombre . " (activo: " . ($producto->activo ? 'si' : 'no') . ")\n") : "  NO EXISTE\n";

echo "\nItems de orden para MLA3690559588:\n";
$items = \App\Models\Integraciones\MercadoLibreOrdenItem::where('ml_item_id', 'MLA3690559588')->orderByDesc('id')->get();
foreach ($items as $item) {
    echo "  id={$item->id} sku_vendedor=" . var_export($item->sku_vendedor, true) . " ml_variation_id=" . var_export($item->ml_variation_id, true) . " creado=" . $item->created_at . "\n";
}
if ($items->isEmpty()) {
    echo "  (sin items)\n";
}

echo "\nItems de orden para MLA1927008393:\n";
$items2 = \App\Models\Integraciones\MercadoLibreOrdenItem::where('ml_item_id', 'MLA1927008393')->orderByDesc('id')->get();
foreach ($items2 as $item) {
    echo "  id={$item->id} sku_vendedor=" . var_export($item->sku_vendedor, true) . " ml_variation_id=" . var_export($item->ml_variation_id, true) . " creado=" . $item->created_at . "\n";
}
