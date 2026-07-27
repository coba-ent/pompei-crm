<?php

namespace Database\Seeders;

use App\Models\TipoProducto;
use Illuminate\Database\Seeder;

/**
 * Tipos de producto precargados por Contagram al iniciar una cuenta.
 */
class TipoProductoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Compra y Venta', 'Consignación', 'Fabricado', 'Insumo'] as $nombre) {
            TipoProducto::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }
    }
}
