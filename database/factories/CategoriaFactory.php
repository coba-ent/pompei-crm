<?php

namespace Database\Factories;

use App\Models\Categoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Categoria>
 */
class CategoriaFactory extends Factory
{
    protected $model = Categoria::class;

    public function definition(): array
    {
        return [
            'tipo' => 'gasto',
            'categoria_padre_id' => null,
            'nombre' => $this->faker->unique()->words(2, true),
        ];
    }
}
