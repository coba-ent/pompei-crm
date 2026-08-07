<?php
/**
 * Actualiza precio/costo/listas de precio y stock de los productos ya importados,
 * desde public/imports/ACTUALIZADOS/Listado de Productos y Servicios 06-08-2026...xlsx
 * (subido como /root/productos_actualizar.csv y /root/productos_nuevos.csv en el VPS),
 * acordado con el usuario el 06/08/2026.
 *
 * Nueva normativa del negocio: sólo se usa el depósito "Local" de ahora en más.
 * - Depósito "Depósito Tiendanube" se BORRA (y su stock).
 * - Depósito "Full" se DESACTIVA (activo=0) y se le vacía el stock.
 * - Todo el stock queda concentrado en "Local" (por eso Stock Total == Local).
 *
 * No es un import de productos nuevos (son los mismos ya cargados) salvo 2 excepciones:
 * el par que antes colisionaba en Id 44804 ya se resolvió del lado de Contagram
 * (BACHA EMB COCINA ahora es Id/codigo 44926, LAVATORIO NICKEL sigue 44804) y se
 * importan ahora como altas nuevas.
 *
 * Alcance de la actualización (decisión explícita): sólo costo, precio_venta, IVA,
 * precios_producto (12 listas) y stock. NO se tocan proveedor/tipo_producto/nombre.
 * Para 36 productos de un cluster de accesorios FV que Contagram renumeró (mismo
 * producto, sufijo de código igual, número inicial distinto), también se actualiza
 * `codigo` al valor nuevo — decisión explícita del usuario.
 *
 * Corre via `php artisan tinker < scripts/actualizar_productos_stock_precios.php`
 * en el VPS.
 */

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

$listasPrecio = \App\Models\ListaPrecio::pluck('id', 'nombre');
$tiposProducto = \App\Models\TipoProducto::pluck('id', 'nombre');
$proveedores = \App\Models\Proveedor::pluck('id', 'nombre');

foreach ($mapaListas as $col => $nombreLista) {
    if (! isset($listasPrecio[$nombreLista])) {
        throw new \Exception("Falta lista de precio en BD: $nombreLista");
    }
}

\Illuminate\Support\Facades\DB::beginTransaction();
try {
    // --- 1) Depositos: borrar Deposito Tiendanube, desactivar y vaciar Full ---
    $depositoTn = \App\Models\Deposito::where('nombre', 'Depósito Tiendanube')->firstOrFail();
    $depositoFull = \App\Models\Deposito::where('nombre', 'Full')->firstOrFail();
    $depositoLocal = \App\Models\Deposito::where('nombre', 'Local')->firstOrFail();

    \Illuminate\Support\Facades\DB::table('stocks')->where('deposito_id', $depositoTn->id)->delete();
    $depositoTn->delete();

    \Illuminate\Support\Facades\DB::table('stocks')->where('deposito_id', $depositoFull->id)->delete();
    $depositoFull->update(['activo' => false]);

    echo "Depositos: 'Depósito Tiendanube' borrado, 'Full' desactivado y vaciado.\n";

    // --- 2) Actualizar productos existentes (precio/costo/listas/stock/codigo) ---
    $fh = fopen('/root/productos_actualizar.csv', 'r');
    $header = fgetcsv($fh, 0, ',', '"', '\\');

    $actualizados = 0;
    $sinMatchCodigo = [];
    $codigosActualizados = 0;
    $lineaCsv = 1;

    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $lineaCsv++;
        if (count($row) !== count($header)) {
            continue;
        }
        $r = array_combine($header, $row);

        $producto = \App\Models\Producto::where('codigo', $r['codigo_db'])->first();
        if (! $producto) {
            $sinMatchCodigo[] = $r['codigo_db'];
            continue;
        }

        $updateData = [
            'costo' => (float) $r['costo'],
            'precio_venta' => (float) $r['precio_venta'],
            'iva_compra_pct' => (string) (int) $r['iva_compras'],
            'iva_venta_pct' => (string) (int) $r['iva_ventas'],
        ];
        if (trim($r['codigo_nuevo']) !== '' && trim($r['codigo_nuevo']) !== $producto->codigo) {
            $updateData['codigo'] = trim($r['codigo_nuevo']);
            $codigosActualizados++;
        }
        $producto->update($updateData);

        // Precios por lista: borrar y recargar
        \App\Models\PrecioProducto::where('producto_id', $producto->id)->delete();
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

        // Stock: sólo Local (los otros depositos ya no existen / estan vacios)
        \App\Models\Stock::where('producto_id', $producto->id)->delete();
        $cantidadLocal = trim($r['stock_local']);
        if ($producto->tipo === 'producto' && $cantidadLocal !== '' && $cantidadLocal !== '-') {
            \App\Models\Stock::create([
                'producto_id' => $producto->id,
                'variante_id' => null,
                'deposito_id' => $depositoLocal->id,
                'cantidad' => (float) $cantidadLocal,
            ]);
        }

        $actualizados++;
    }
    fclose($fh);

    echo "Productos actualizados: $actualizados\n";
    echo "Codigos actualizados (renumerados por Contagram): $codigosActualizados\n";
    echo 'Sin match de codigo ('.count($sinMatchCodigo)."):\n";
    foreach ($sinMatchCodigo as $c) {
        echo "  - $c\n";
    }

    // --- 3) Productos nuevos (el par 44804/44926 ya resuelto) ---
    $fh2 = fopen('/root/productos_nuevos.csv', 'r');
    $header2 = fgetcsv($fh2, 0, ',', '"', '\\');
    $creados = 0;

    while (($row = fgetcsv($fh2, 0, ',', '"', '\\')) !== false) {
        if (count($row) !== count($header2)) {
            continue;
        }
        $r = array_combine($header2, $row);

        $tipo = strtolower(trim($r['tipo'])) === 'servicio' ? 'servicio' : 'producto';
        $tipoProductoId = $tiposProducto[trim($r['tipo_producto'])] ?? null;
        $proveedorId = $proveedores[trim($r['proveedor'])] ?? null;

        $producto = \App\Models\Producto::create([
            'nombre' => $r['nombre'],
            'codigo' => trim($r['codigo_nuevo']) !== '' ? trim($r['codigo_nuevo']) : null,
            'tipo' => $tipo,
            'tipo_producto_id' => $tipoProductoId,
            'proveedor_id' => $proveedorId,
            'mostrar_en_ventas' => strtolower(trim($r['mostrar_ventas'])) === 'si',
            'precio_venta' => (float) $r['precio_venta'],
            'iva_venta_pct' => (string) (int) $r['iva_ventas'],
            'mostrar_en_compras' => strtolower(trim($r['mostrar_compras'])) === 'si',
            'costo' => (float) $r['costo'],
            'iva_compra_pct' => (string) (int) $r['iva_compras'],
            'activo' => strtolower(trim($r['activo'])) === 'si',
        ]);

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

        $cantidadLocal = trim($r['stock_local']);
        if ($tipo === 'producto' && $cantidadLocal !== '' && $cantidadLocal !== '-') {
            \App\Models\Stock::create([
                'producto_id' => $producto->id,
                'variante_id' => null,
                'deposito_id' => $depositoLocal->id,
                'cantidad' => (float) $cantidadLocal,
            ]);
        }

        $creados++;
    }
    fclose($fh2);

    echo "Productos nuevos creados: $creados\n";

    \Illuminate\Support\Facades\DB::commit();
} catch (\Throwable $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    echo 'ERROR, rollback completo: '.$e->getMessage()."\n";
    throw $e;
}

echo "=== VERIFICACION FINAL ===\n";
echo 'Total productos: '.\App\Models\Producto::count()."\n";
echo 'Total depositos activos: '.\App\Models\Deposito::where('activo', true)->count()."\n";
echo 'Total stocks (deberian ser todos en Local): '.\App\Models\Stock::count()."\n";
echo 'Stocks fuera de Local: '.\App\Models\Stock::where('deposito_id', '!=', $depositoLocal->id)->count()."\n";
