<?php
/**
 * Restaura productos.precio_venta y las 10 listas de precio (todas MENOS "ML" y
 * "ML Premium", que el usuario administra a mano y ya estan correctas) desde el
 * Excel de referencia (/root/restaurar_precios.csv), matcheando por Id.
 *
 * Motivo: se detecto que ~52-57 productos del cluster de accesorios FV renumerado
 * (Id 44454-44520) tenian precio_venta y varias listas con valores incorrectos
 * (mismatch de fila durante la actualizacion del 06/08). Se restaura TODO el
 * universo matched (no solo los detectados) para garantizar consistencia total
 * contra la fuente de verdad, sin depender de haber encontrado el 100% de los
 * afectados con las comparaciones manuales.
 */

$listasPrecio = \App\Models\ListaPrecio::pluck('id', 'nombre');

$mapaListas = [
    'AHORA 12' => 'AHORA 12',
    'AHORA 3' => 'AHORA 3',
    'AHORA 6' => 'AHORA 6',
    'AHORA 9' => 'AHORA 9',
    'Lista de Precios Promocion Tiendanube' => 'Lista de Precios Promoción Tiendanube',
    'Lista de Precios Tiendanube' => 'Lista de Precios Tiendanube',
    'Mayorista/obras' => 'Mayorista/obras',
    'ML Sugerido' => 'ML Sugerido',
    'Punto Reposicion' => 'Punto Reposición',
    'PVP' => 'PVP',
];

foreach ($mapaListas as $colCsv => $nombreLista) {
    if (! isset($listasPrecio[$nombreLista])) {
        throw new \Exception("Falta lista de precio en BD: $nombreLista");
    }
}

$fh = fopen('/root/restaurar_precios.csv', 'r');
$header = fgetcsv($fh, 0, ',', '"', '\\');

$actualizados = 0;
$sinMatch = 0;

\Illuminate\Support\Facades\DB::beginTransaction();
try {
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }
        $r = array_combine($header, $row);
        $id = (int) $r['id'];

        $producto = \App\Models\Producto::find($id);
        if (! $producto) {
            $sinMatch++;
            continue;
        }

        $producto->update(['precio_venta' => (float) $r['precio_venta']]);

        foreach ($mapaListas as $colCsv => $nombreLista) {
            $valor = trim($r[$colCsv]);
            $listaId = $listasPrecio[$nombreLista];
            if ($valor === '') {
                \App\Models\PrecioProducto::where('producto_id', $id)
                    ->where('lista_precio_id', $listaId)->delete();

                continue;
            }
            \App\Models\PrecioProducto::updateOrCreate(
                ['producto_id' => $id, 'lista_precio_id' => $listaId],
                ['precio' => (float) $valor]
            );
        }

        $actualizados++;
    }

    \Illuminate\Support\Facades\DB::commit();
} catch (\Throwable $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    fclose($fh);
    echo 'ERROR, rollback completo: '.$e->getMessage()."\n";
    throw $e;
}

fclose($fh);

echo "Productos actualizados: $actualizados\n";
echo "Sin match de Id: $sinMatch\n";
