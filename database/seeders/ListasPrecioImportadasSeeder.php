<?php

namespace Database\Seeders;

use App\Models\ListaPrecio;
use Illuminate\Database\Seeder;

/**
 * Listas de precio propias de este negocio (Tiendanube, Mayorista, Mercado
 * Libre, PVP), relevadas de la planilla de productos ("Copia de TODOS LOS ID
 * 23.07 - Productos.csv"), además de las AHORA 3/6/9/12 ya existentes. Se
 * agregan para que el asistente "Importar Datos" pueda mapear cada columna
 * de precio del CSV a su `lista_precio_id` y volcarla en `precios_producto`.
 */
class ListasPrecioImportadasSeeder extends Seeder
{
    public function run(): void
    {
        $nombres = [
            'Lista de Precios Promoción Tiendanube',
            'Lista de Precios Tiendanube',
            'Mayorista/obras',
            'ML',
            'ML C/IVA',
            'ML Premium',
            'ML Premium C/IVA',
            'ML Sugerido',
            'PVP',
        ];

        foreach ($nombres as $nombre) {
            ListaPrecio::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }
    }
}
