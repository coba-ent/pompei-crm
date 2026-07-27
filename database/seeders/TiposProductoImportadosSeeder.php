<?php

namespace Database\Seeders;

use App\Models\TipoProducto;
use Illuminate\Database\Seeder;

/**
 * Valores de "Tipo de Producto" propios de este negocio (rubros: Griferia,
 * Repuesto, Sanitario, etc.), relevados de la planilla de productos ("Copia
 * de TODOS LOS ID 23.07 - Productos.csv"). Se agregan como valores custom al
 * catálogo `tipos_producto` (el modal de alta permite "Crear Tipo de
 * Producto" con valores propios además de los 4 precargados por Contagram —
 * ver TipoProductoSeeder) para que el asistente "Importar Datos" pueda
 * resolver esa columna por nombre exacto sin auto-crearla.
 *
 * Se cargan literales, sin normalizar duplicados aparentes (Griferia vs
 * Griferias, Repuesto vs Repuestos, Accesorio de baño vs Accesorios de
 * baño...): unificarlos rompería el match exacto del importador contra el
 * valor que trae cada fila de la planilla. Si más adelante se decide
 * consolidarlos, hacerlo después de importar (reasignando productos), no acá.
 */
class TiposProductoImportadosSeeder extends Seeder
{
    public function run(): void
    {
        $nombres = [
            'Accesorio cocina',
            'Accesorio de baño',
            'Accesorio de cocina',
            'Accesorios',
            'Accesorios de baño',
            'Accesorios de cocina',
            'Bacha de baño',
            'Bañera',
            'Combo - Griferia',
            'Griferia',
            'Griferia cocina',
            'Griferia de baño',
            'Griferias',
            'Higiene Corporal',
            'Higiene Manos',
            'Ideal',
            'Lavaderos',
            'Mampara',
            'Mano de obra',
            'Marmol',
            'Mueble de baño',
            'Muebles',
            'Pileta de cocina',
            'Repuesto',
            'Repuestos',
            'Sanitario',
            'Sanitarios',
            'Sanitarios Varios',
            'Tapa asiento',
            'Tapas Asiento',
        ];

        foreach ($nombres as $nombre) {
            TipoProducto::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
        }
    }
}
