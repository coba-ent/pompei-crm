<?php

namespace Database\Seeders;

use App\Models\CondicionIva;
use Illuminate\Database\Seeder;

class CondicionIvaSeeder extends Seeder
{
    public function run(): void
    {
        $condiciones = [
            ['nombre' => 'Responsable Inscripto', 'codigo_afip' => '1', 'requiere_cuit' => true],
            ['nombre' => 'Monotributista', 'codigo_afip' => '6', 'requiere_cuit' => true],
            ['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false],
            ['nombre' => 'Exento', 'codigo_afip' => '4', 'requiere_cuit' => false],
            ['nombre' => 'No Categorizado', 'codigo_afip' => '7', 'requiere_cuit' => false],
        ];

        foreach ($condiciones as $condicion) {
            CondicionIva::firstOrCreate(['nombre' => $condicion['nombre']], $condicion);
        }
    }
}
