<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Presupuesto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Presupuesto>
 */
class PresupuestoFactory extends Factory
{
    protected $model = Presupuesto::class;

    public function definition(): array
    {
        return [
            'nro_presupuesto' => (string) $this->faker->unique()->numberBetween(1, 999999),
            'cliente_id' => Cliente::factory(),
            'fecha_emision' => now()->toDateString(),
            'fecha_validez' => now()->addDays(15)->toDateString(),
            'estado' => 'pendiente',
            'subtotal_sin_descuento' => 0,
            'descuento' => 0,
            'subtotal_con_descuento' => 0,
            'total' => 0,
        ];
    }

    public function aceptado(): static
    {
        return $this->state(fn () => ['estado' => 'aceptado']);
    }

    public function rechazado(): static
    {
        return $this->state(fn () => ['estado' => 'rechazado']);
    }
}
