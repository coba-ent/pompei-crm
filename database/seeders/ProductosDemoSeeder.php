<?php

namespace Database\Seeders;

use App\Models\Producto;
use Illuminate\Database\Seeder;

/**
 * Genera ~1.000 productos para validar el rendimiento del listado (SC-005).
 * Sólo para entornos de desarrollo/demo.
 */
class ProductosDemoSeeder extends Seeder
{
    public function run(): void
    {
        Producto::factory()->count(1000)->create();
    }
}
