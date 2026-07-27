<?php

namespace Database\Seeders;

use App\Models\CuentaTesoreria;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Cuentas del sistema (Cheque de Terceros/Propio, FR-006) + cuentas del
 * relevamiento (data-model.md §Seed). Saldos iniciales en 0 por defecto.
 */
class CuentasTesoreriaSeeder extends Seeder
{
    public function run(): void
    {
        $cuentas = [
            ['nombre' => 'Cheque de Terceros', 'tipo' => 'a_cobrar', 'es_sistema' => true],
            ['nombre' => 'Cheque Propio', 'tipo' => 'a_pagar', 'es_sistema' => true],
            ['nombre' => 'Caja del Local', 'tipo' => 'efectivo', 'es_sistema' => false],
            ['nombre' => 'Caja General', 'tipo' => 'efectivo', 'es_sistema' => false],
            ['nombre' => 'Banco Galicia', 'tipo' => 'banco', 'es_sistema' => false],
            ['nombre' => 'Banco Santander Río', 'tipo' => 'banco', 'es_sistema' => false],
            ['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'es_sistema' => false],
            ['nombre' => 'AMEX', 'tipo' => 'a_cobrar', 'es_sistema' => false],
            ['nombre' => 'VISA', 'tipo' => 'a_cobrar', 'es_sistema' => false],
            ['nombre' => 'VISA Corporativa', 'tipo' => 'a_pagar', 'es_sistema' => false],
        ];

        foreach ($cuentas as $orden => $datos) {
            $existe = CuentaTesoreria::where('nombre', $datos['nombre'])->exists();
            if ($existe) {
                continue;
            }

            $fecha = Carbon::today();

            CuentaTesoreria::create([
                'nombre' => $datos['nombre'],
                'tipo' => $datos['tipo'],
                'es_sistema' => $datos['es_sistema'],
                'visible' => true,
                'saldo_inicial' => 0,
                'saldo_inicial_fecha' => $fecha,
                'orden' => $orden,
            ]);

            // Saldo inicial en 0: no genera movimiento (FR-002 sólo aplica a montos ≠ 0).
        }
    }
}
