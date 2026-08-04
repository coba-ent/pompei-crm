<?php

namespace Database\Factories;

use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotaCreditoDebito>
 */
class NotaCreditoDebitoFactory extends Factory
{
    protected $model = NotaCreditoDebito::class;

    public function definition(): array
    {
        return [
            'venta_id' => Venta::factory(),
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => $this->faker->randomFloat(2, 100, 5000),
            'tipo_comprobante' => 'B',
            'descripcion' => $this->faker->sentence(),
        ];
    }
}
