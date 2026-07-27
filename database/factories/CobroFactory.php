<?php

namespace Database\Factories;

use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cobro>
 */
class CobroFactory extends Factory
{
    protected $model = Cobro::class;

    public function definition(): array
    {
        return [
            'venta_id' => Venta::factory(),
            'fecha' => now()->toDateString(),
            'cuenta_tesoreria_id' => CuentaTesoreria::factory(),
            'monto' => $this->faker->randomFloat(2, 100, 10000),
        ];
    }
}
