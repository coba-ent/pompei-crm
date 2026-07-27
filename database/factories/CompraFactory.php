<?php

namespace Database\Factories;

use App\Models\Compra;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Compra>
 */
class CompraFactory extends Factory
{
    protected $model = Compra::class;

    public function definition(): array
    {
        $tipo = $this->faker->randomElement(['A', 'B', 'C']);

        return [
            'proveedor_id' => Proveedor::factory(),
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => $tipo,
            'nro_comprobante' => Compra::siguienteNroComprobante($tipo),
            'subtotal_sin_descuento' => 0,
            'descuento' => 0,
            'subtotal_con_descuento' => 0,
            'total' => 0,
        ];
    }
}
