<?php

namespace Database\Seeders;

use App\Models\Deposito;
use Illuminate\Database\Seeder;

/**
 * Depósitos propios de este negocio (canales de venta/logística: Tiendanube,
 * Full, Local), relevados de la planilla de productos ("Copia de TODOS LOS
 * ID 23.07 - Productos.csv"), además de "Principal" (DepositoSeeder). Se
 * agregan para que el asistente "Importar Datos" pueda mapear la columna de
 * stock de cada depósito y cargar el stock inicial real por depósito.
 */
class DepositosImportadosSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Depósito Tiendanube', 'Full', 'Local'] as $nombre) {
            Deposito::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }
    }
}
