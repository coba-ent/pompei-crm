<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\OtroIngreso;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OtroIngreso>
 */
class OtroIngresoFactory extends Factory
{
    protected $model = OtroIngreso::class;

    public function definition(): array
    {
        return [
            'fecha' => now()->toDateString(),
            'monto' => $this->faker->randomFloat(2, 100, 10000),
            'categoria_id' => Categoria::factory()->state(['tipo' => 'ingreso']),
            'cuenta_tesoreria_id' => CuentaTesoreria::factory(),
            'pendiente' => false,
        ];
    }

    public function pendiente(): static
    {
        return $this->state(fn () => ['pendiente' => true, 'cuenta_tesoreria_id' => null]);
    }
}
