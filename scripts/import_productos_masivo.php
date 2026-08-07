<?php
/**
 * Import masivo de productos desde CSV limpio (public/imports/productos_import.csv
 * subido como /root/productos_import.csv en el VPS), acordado con el usuario
 * el 05/08/2026. Corre via `php artisan tinker < scripts/import_productos_masivo.php`
 * DENTRO del VPS (usa las conexiones/modelos reales, no local).
 *
 * Reglas acordadas:
 * - Proveedor: match exacto por nombre contra proveedores ya existentes (los 31
 *   del Excel ya existen todos, no se crea ninguno nuevo; si algo no matchea se
 *   deja null y se reporta).
 * - tipo_producto_id: match exacto por nombre contra tipos_producto (ya sembrado
 *   con las 31 categorias del Excel, incluidas "Bacha cocina" y "Griferia de
 *   cocina" creadas a mano antes de este script).
 * - Precios: se cargan a precios_producto para las 12 listas con match de nombre
 *   (AHORA 12/3/6/9, Lista TN, Promo TN, Mayorista/obras, ML, ML Premium,
 *   ML Sugerido, PVP, Punto Reposicion). ML C/IVA y ML Premium C/IVA se descartan.
 * - Stock: se carga en 3 depositos (Local, Deposito Tiendanube, Full) segun las
 *   3 columnas del CSV. "Principal" ya fue borrado antes de correr esto.
 * - descripcion: se deja null (la columna Descripcion del Excel era ruido
 *   Nombre+Codigo redundante, no texto real).
 */

$csvPath = '/root/productos_import.csv';

$listasPrecio = \App\Models\ListaPrecio::pluck('id', 'nombre');
$tiposProducto = \App\Models\TipoProducto::pluck('id', 'nombre');
$proveedores = \App\Models\Proveedor::pluck('id', 'nombre');
$depositos = \App\Models\Deposito::pluck('id', 'nombre');

$depositoLocal = $depositos['Local'];
$depositoTn = $depositos['Depósito Tiendanube'];
$depositoFull = $depositos['Full'];

// nombre columna csv => nombre lista_precio en BD
$mapaListas = [
    'lp_ahora12' => 'AHORA 12',
    'lp_ahora3' => 'AHORA 3',
    'lp_ahora6' => 'AHORA 6',
    'lp_ahora9' => 'AHORA 9',
    'lp_promo_tn' => 'Lista de Precios Promoción Tiendanube',
    'lp_tn' => 'Lista de Precios Tiendanube',
    'lp_mayorista' => 'Mayorista/obras',
    'lp_ml' => 'ML',
    'lp_ml_premium' => 'ML Premium',
    'lp_ml_sugerido' => 'ML Sugerido',
    'lp_punto_reposicion' => 'Punto Reposición',
    'lp_pvp' => 'PVP',
];

foreach ($mapaListas as $col => $nombreLista) {
    if (! isset($listasPrecio[$nombreLista])) {
        throw new \Exception("Falta lista de precio en BD: $nombreLista");
    }
}

$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh, 0, ',', '"', '\\');
$idx = array_flip($header);

$creados = 0;
$sinProveedor = [];
$sinTipoProducto = [];
$erroresFila = [];
$lineaCsv = 1;

\Illuminate\Support\Facades\DB::beginTransaction();
try {
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $lineaCsv++;
        if (count($row) !== count($header)) {
            $erroresFila[] = "Linea $lineaCsv: columnas desalineadas (" . count($row) . " vs " . count($header) . ")";
            continue;
        }
        $r = array_combine($header, $row);

        // Id 44578 -> forzado a Peirano por acuerdo explicito con el usuario
        $nombreProveedor = trim($r['proveedor']);
        if ($nombreProveedor === '' && (int) $r['id'] === 44578) {
            $nombreProveedor = 'Peirano';
        }
        $proveedorId = $nombreProveedor !== '' ? ($proveedores[$nombreProveedor] ?? null) : null;
        if ($nombreProveedor !== '' && $proveedorId === null) {
            $sinProveedor[] = $r['nombre'] . ' (proveedor: ' . $nombreProveedor . ')';
        }

        $nombreTipoProd = trim($r['tipo_producto']);
        $tipoProductoId = $nombreTipoProd !== '' ? ($tiposProducto[$nombreTipoProd] ?? null) : null;
        if ($nombreTipoProd !== '' && $tipoProductoId === null) {
            $sinTipoProducto[] = $r['nombre'] . ' (categoria: ' . $nombreTipoProd . ')';
        }

        $tipo = strtolower(trim($r['tipo'])) === 'servicio' ? 'servicio' : 'producto';

        $producto = \App\Models\Producto::create([
            'nombre' => $r['nombre'],
            'codigo' => trim($r['codigo']) !== '' ? $r['codigo'] : null,
            'tipo' => $tipo,
            'tipo_producto_id' => $tipoProductoId,
            'proveedor_id' => $proveedorId,
            'descripcion' => null,
            'mostrar_en_ventas' => strtolower(trim($r['mostrar_ventas'])) === 'si',
            'precio_venta' => (float) $r['precio_venta'],
            'iva_venta_pct' => (string) (int) $r['iva_ventas'],
            'mostrar_en_compras' => strtolower(trim($r['mostrar_compras'])) === 'si',
            'costo' => (float) $r['costo'],
            'iva_compra_pct' => (string) (int) $r['iva_compras'],
            'activo' => strtolower(trim($r['activo'])) === 'si',
        ]);

        // Precios por lista
        $preciosInsert = [];
        foreach ($mapaListas as $col => $nombreLista) {
            $valor = trim($r[$col]);
            if ($valor === '') {
                continue;
            }
            $preciosInsert[] = [
                'producto_id' => $producto->id,
                'lista_precio_id' => $listasPrecio[$nombreLista],
                'precio' => (float) $valor,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($preciosInsert) {
            \App\Models\PrecioProducto::insert($preciosInsert);
        }

        // Stock por deposito (solo Producto; Servicio no tiene stock real)
        if ($tipo === 'producto') {
            $stockInsert = [];
            $mapaStock = [
                'stock_local' => $depositoLocal,
                'stock_deposito_tn' => $depositoTn,
                'stock_full' => $depositoFull,
            ];
            foreach ($mapaStock as $col => $depositoId) {
                $cantidad = trim($r[$col]);
                if ($cantidad === '' || $cantidad === '-') {
                    continue;
                }
                $stockInsert[] = [
                    'producto_id' => $producto->id,
                    'variante_id' => null,
                    'deposito_id' => $depositoId,
                    'cantidad' => (float) $cantidad,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($stockInsert) {
                \App\Models\Stock::insert($stockInsert);
            }
        }

        $creados++;
    }

    \Illuminate\Support\Facades\DB::commit();
} catch (\Throwable $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    fclose($fh);
    echo "ERROR, rollback completo: " . $e->getMessage() . "\n";
    throw $e;
}

fclose($fh);

echo "=== RESULTADO ===\n";
echo "Productos creados: $creados\n";
echo "Filas con proveedor sin match (" . count($sinProveedor) . "):\n";
foreach (array_slice($sinProveedor, 0, 20) as $s) {
    echo "  - $s\n";
}
echo "Filas con categoria sin match (" . count($sinTipoProducto) . "):\n";
foreach (array_slice($sinTipoProducto, 0, 20) as $s) {
    echo "  - $s\n";
}
echo "Errores de fila (" . count($erroresFila) . "):\n";
foreach (array_slice($erroresFila, 0, 20) as $e) {
    echo "  - $e\n";
}
echo "Total productos en BD ahora: " . \App\Models\Producto::count() . "\n";
echo "Total precios_producto: " . \App\Models\PrecioProducto::count() . "\n";
echo "Total stocks: " . \App\Models\Stock::count() . "\n";
