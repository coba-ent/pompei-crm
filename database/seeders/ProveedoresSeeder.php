<?php

namespace Database\Seeders;

use App\Models\Proveedor;
use Illuminate\Database\Seeder;

/**
 * Proveedores reales del negocio, relevados de la planilla de productos
 * ("Copia de TODOS LOS ID 23.07 - Productos.csv"). Se precargan para que el
 * asistente "Importar Datos" > Productos & Servicios pueda resolver la
 * columna "Proveedor" por nombre (no la auto-crea — ver
 * documentacion_principal_crm.md §2.6 "Notas Técnicas").
 *
 * "Pompei" y "Pompei SRL" se mantienen como dos registros separados (no se
 * unifican) porque la planilla los usa como valores distintos en la columna
 * Proveedor; unificarlos rompería el match exacto de la fila que trae "Pompei".
 */
class ProveedoresSeeder extends Seeder
{
    public function run(): void
    {
        $nombres = [
            'Aquaflex',
            'ARDAN',
            'CORDENONS',
            'DERVICH',
            'DGC',
            'Docla',
            'Ferrum',
            'FI-AMOBLAMIENTOS',
            'FV',
            'Global',
            'GOOD LOOKING',
            'Ideal',
            'JOHNSON ACEROS SA',
            'JPD AMOBLAMIENTO',
            'KURYMAR',
            'MARMOLERIA MUNIZ',
            'Mauricio',
            'MI PILETA',
            'PADILAK',
            'Peirano',
            'Peisa',
            'PIAZZA',
            'POLYQUARTZ',
            'Pompei',
            'Pompei SRL',
            'RAO',
            'SANITAR',
            'Seikai',
            'Shawer',
            'Spartan',
            'ULTRAGRIF',
        ];

        foreach ($nombres as $nombre) {
            Proveedor::firstOrCreate(['nombre' => $nombre]);
        }
    }
}
