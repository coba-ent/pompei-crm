<?php

namespace Database\Factories;

use App\Models\CuentaTesoreria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuentaTesoreria>
 */
class CuentaTesoreriaFactory extends Factory
{
    protected $model = CuentaTesoreria::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->unique()->words(2, true),
            'tipo' => $this->faker->randomElement(['a_cobrar', 'a_pagar', 'banco', 'efectivo']),
            'visible' => true,
            'es_sistema' => false,
            'saldo_inicial' => 0,
            'saldo_inicial_fecha' => now()->toDateString(),
            'orden' => null,
        ];
    }

    public function tipo(string $tipo): static
    {
        return $this->state(fn () => ['tipo' => $tipo]);
    }

    public function oculta(): static
    {
        return $this->state(fn () => ['visible' => false]);
    }

    public function sistema(): static
    {
        return $this->state(fn () => ['es_sistema' => true]);
    }
}
