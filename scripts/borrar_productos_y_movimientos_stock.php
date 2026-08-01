echo "=== ANTES ===\n";
echo "Productos: " . \App\Models\Producto::count() . "\n";
echo "Movimientos de stock: " . \App\Models\MovimientoStock::count() . "\n";
$bloqueantes = \DB::table('nota_credito_debito_items')->count();
echo "Items de NC/ND que referencian productos (restrictOnDelete): $bloqueantes\n";

if ($bloqueantes > 0) {
    echo "\nABORTADO: existen nota_credito_debito_items con producto_id (restrictOnDelete impide borrar esos productos).\n";
    echo "No se borro nada. Revisar esos registros antes de reintentar.\n";
    exit;
}

echo "\n=== Borrando productos (cascadea a movimientos_stock, stocks, producto_variantes, precios_producto, vinculaciones ML/TN; deja null en items de ventas/compras/presupuestos/ordenes) ===\n";
\App\Models\Producto::query()->delete();

echo "\n=== DESPUES ===\n";
echo "Productos: " . \App\Models\Producto::count() . "\n";
echo "Movimientos de stock: " . \App\Models\MovimientoStock::count() . "\n";
echo "\nListo.\n";
