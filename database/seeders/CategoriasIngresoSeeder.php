<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;

/** Categorías tipo=ingreso observadas en Contagram (informe_contagram_ingresos.md §4.4). */
class CategoriasIngresoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Aportes Socios', 'Otros Ingresos', 'Préstamos Financieros', 'Saldo Inicial'] as $nombre) {
            Categoria::updateOrCreate(
                ['tipo' => 'ingreso', 'nombre' => $nombre],
                ['es_sistema' => true, 'activo' => true],
            );
        }
    }
}
