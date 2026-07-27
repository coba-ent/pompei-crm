<?php

namespace Database\Factories;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    public function definition(): array
    {
        $tipo = $this->faker->boolean(80) ? 'producto' : 'servicio';

        return [
            'nombre' => ucfirst($this->faker->unique()->words(3, true)),
            'codigo' => strtoupper($this->faker->unique()->bothify('SKU-####??')),
            'tipo' => $tipo,
            'descripcion' => $this->faker->optional()->sentence(),
            'mostrar_en_ventas' => true,
            'precio_venta' => $this->faker->randomFloat(2, 100, 100000),
            'iva_venta_pct' => '21',
            'mostrar_en_compras' => true,
            'costo' => $this->faker->randomFloat(2, 50, 80000),
            'iva_compra_pct' => '21',
            'activo' => $this->faker->boolean(90),
        ];
    }

    public function servicio(): static
    {
        return $this->state(fn () => ['tipo' => 'servicio']);
    }
}
