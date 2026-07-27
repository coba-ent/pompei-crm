<?php

namespace Database\Factories;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoTesoreria>
 */
class MovimientoTesoreriaFactory extends Factory
{
    protected $model = MovimientoTesoreria::class;

    public function definition(): array
    {
        return [
            'cuenta_tesoreria_id' => CuentaTesoreria::factory(),
            'fecha' => now()->toDateString(),
            'tipo' => 'saldo_inicial',
            'monto' => $this->faker->randomFloat(2, -1000, 1000),
            'detalle' => null,
            'nro_comprobante' => null,
            'observacion' => null,
            'transferencia_id' => null,
            'usuario_id' => null,
        ];
    }

    public function tipo(string $tipo): static
    {
        return $this->state(fn () => ['tipo' => $tipo]);
    }

    public function monto(float $monto): static
    {
        return $this->state(fn () => ['monto' => $monto]);
    }

    public function fecha(string $fecha): static
    {
        return $this->state(fn () => ['fecha' => $fecha]);
    }
}
