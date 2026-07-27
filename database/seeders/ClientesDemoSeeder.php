<?php

namespace Database\Seeders;

use App\Models\Cliente;
use Illuminate\Database\Seeder;

/**
 * Genera ~1.000 clientes de prueba para validar la performance del listado
 * (SC-005). Sólo para pruebas locales — no se corre en producción.
 */
class ClientesDemoSeeder extends Seeder
{
    public function run(): void
    {
        Cliente::factory()->count(1000)->create();
    }
}
