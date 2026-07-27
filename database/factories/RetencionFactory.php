<?php

namespace Database\Factories;

use App\Models\Pago;
use App\Models\Retencion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Retencion>
 */
class RetencionFactory extends Factory
{
    protected $model = Retencion::class;

    public function definition(): array
    {
        return [
            'pago_id' => Pago::factory(),
            'fecha' => now()->toDateString(),
            'monto' => $this->faker->randomFloat(2, 10, 1000),
            'tipo_retencion' => 'IVA',
        ];
    }
}
