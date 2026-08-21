<?php

namespace Database\Factories;

use App\Models\AplicacionCredito;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AplicacionCredito>
 */
class AplicacionCreditoFactory extends Factory
{
    protected $model = AplicacionCredito::class;

    public function definition(): array
    {
        return [
            'origen_type' => Venta::class,
            'origen_id' => Venta::factory(),
            'destino_type' => Venta::class,
            'destino_id' => Venta::factory(),
            'monto' => $this->faker->randomFloat(2, 100, 10000),
            'fecha' => now()->toDateString(),
        ];
    }
}
