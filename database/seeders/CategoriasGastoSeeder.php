<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;

/** Categorías/subcategorías tipo=gasto observadas en Contagram (informe_contagram_egresos.md §3.4). */
class CategoriasGastoSeeder extends Seeder
{
    public function run(): void
    {
        $arbol = [
            'Empleados' => [],
            'Impuestos' => [],
            'Marketing' => ['Facebook Ad\'s', 'Material de Promoción'],
            'Oficina' => ['Alquiler', 'Luz'],
            'Otros Gastos' => [],
            'Servicios Profesionales' => [],
        ];

        foreach ($arbol as $nombre => $subcategorias) {
            $categoria = Categoria::updateOrCreate(
                ['tipo' => 'gasto', 'nombre' => $nombre, 'categoria_padre_id' => null],
                ['es_sistema' => true, 'activo' => true],
            );

            foreach ($subcategorias as $subnombre) {
                Categoria::updateOrCreate(
                    ['tipo' => 'gasto', 'nombre' => $subnombre, 'categoria_padre_id' => $categoria->id],
                    ['es_sistema' => true, 'activo' => true],
                );
            }
        }
    }
}
