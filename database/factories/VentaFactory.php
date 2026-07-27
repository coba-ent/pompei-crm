<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venta>
 */
class VentaFactory extends Factory
{
    protected $model = Venta::class;

    public function definition(): array
    {
        $tipo = $this->faker->randomElement(['A', 'B', 'C', 'E']);

        return [
            'cliente_id' => Cliente::factory(),
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => $tipo,
            'nro_comprobante' => Venta::siguienteNroComprobante($tipo),
            'subtotal_sin_descuento' => 0,
            'descuento' => 0,
            'subtotal_con_descuento' => 0,
            'total' => 0,
        ];
    }
}
