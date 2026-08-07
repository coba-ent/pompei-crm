<?php
/**
 * Crea categorias y subcategorias de tipo 'gasto' desde
 * public/imports/gastos/Gastos categorias.xlsx (subido como
 * /root/gastos_categorias.json en el VPS), acordado con el usuario
 * el 05/08/2026. Se excluye la subcategoria "Prueba" (basura) que
 * traia el Excel bajo "Empleados".
 *
 * Corre via `php artisan tinker < scripts/import_categorias_gastos.php`
 * en el VPS.
 */

$data = json_decode(file_get_contents('/root/gastos_categorias.json'), true);

$creadasCat = 0;
$creadasSub = 0;

foreach ($data as $cat => $subs) {
    $categoria = \App\Models\Categoria::create([
        'tipo' => 'gasto',
        'categoria_padre_id' => null,
        'nombre' => $cat,
    ]);
    $creadasCat++;

    foreach ($subs as $sub) {
        \App\Models\Categoria::create([
            'tipo' => 'gasto',
            'categoria_padre_id' => $categoria->id,
            'nombre' => $sub,
        ]);
        $creadasSub++;
    }
}

echo "Categorias creadas: $creadasCat\n";
echo "Subcategorias creadas: $creadasSub\n";
echo "Total categorias tipo=gasto en BD: " . \App\Models\Categoria::where('tipo', 'gasto')->count() . "\n";
