<?php

namespace Database\Factories;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Pago;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pago>
 */
class PagoFactory extends Factory
{
    protected $model = Pago::class;

    public function definition(): array
    {
        return [
            'compra_id' => Compra::factory(),
            'fecha' => now()->toDateString(),
            'cuenta_tesoreria_id' => CuentaTesoreria::factory(),
            'monto' => $this->faker->randomFloat(2, 100, 10000),
        ];
    }
}
