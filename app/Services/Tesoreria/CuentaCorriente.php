<?php

namespace App\Services\Tesoreria;

use App\Models\Compra;
use App\Models\Venta;
use Illuminate\Support\Carbon;

/**
 * Aging de Cuenta Corriente (Clientes/Proveedores) — cálculo mínimo para el
 * dashboard (spec 010), reutilizable después por una futura spec de Informes
 * (Cta Cte). No persiste nada: se apoya en `Venta::aCobrar()`/`Compra::aPagar()`
 * ya derivados y testeados (research.md §2), sin duplicar esa lógica.
 */
class CuentaCorriente
{
    private const TOLERANCIA = 0.005;

    /** @return array{total: float, buckets: array{a_vencer: float, vencido: float, "0_30": float, "31_60": float, "61_90": float, mas_90: float}} */
    public function aging(string $tipo, ?Carbon $fecha = null): array
    {
        $fecha = $fecha ?? Carbon::today();

        if ($tipo === 'cliente') {
            $documentos = Venta::with(['cobros', 'notasCreditoDebito'])->get();
            $campoVencimiento = 'fecha_vto_cobro';
        } else {
            $documentos = Compra::with(['pagos', 'notasCreditoDebito'])->get();
            $campoVencimiento = 'fecha_vto_pago';
        }

        $buckets = [
            'a_vencer' => 0.0,
            'vencido' => 0.0,
            '0_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            'mas_90' => 0.0,
        ];

        foreach ($documentos as $documento) {
            $saldo = $tipo === 'cliente' ? $documento->aCobrar() : $documento->aPagar();

            if ($saldo <= self::TOLERANCIA) {
                continue;
            }

            $vencimiento = $documento->{$campoVencimiento};

            if ($vencimiento === null || Carbon::parse($vencimiento)->greaterThanOrEqualTo($fecha)) {
                $buckets['a_vencer'] += $saldo;

                continue;
            }

            $diasVencido = Carbon::parse($vencimiento)->diffInDays($fecha);
            $buckets['vencido'] += $saldo;

            if ($diasVencido <= 30) {
                $buckets['0_30'] += $saldo;
            } elseif ($diasVencido <= 60) {
                $buckets['31_60'] += $saldo;
            } elseif ($diasVencido <= 90) {
                $buckets['61_90'] += $saldo;
            } else {
                $buckets['mas_90'] += $saldo;
            }
        }

        foreach ($buckets as $clave => $valor) {
            $buckets[$clave] = round($valor, 2);
        }

        return [
            'total' => round($buckets['a_vencer'] + $buckets['vencido'], 2),
            'buckets' => $buckets,
        ];
    }
}
