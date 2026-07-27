<?php

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'telefono' => $this->faker->numerify('11########'),
            'domicilio' => $this->faker->streetAddress(),
            'localidad' => $this->faker->city(),
            'provincia' => $this->faker->state(),
            'cp' => $this->faker->postcode(),
            'cuit' => null,
            'saldo_inicial' => 0,
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }

    /** Asigna un CUIT válido (con DV correcto) generado a partir de un prefijo. */
    public function conCuit(string $cuit): static
    {
        return $this->state(fn () => ['cuit' => $cuit]);
    }
}
