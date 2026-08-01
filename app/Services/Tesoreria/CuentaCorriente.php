<?php

namespace App\Services\Tesoreria;

use App\Models\Compra;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * Mismo aging que {@see self::aging()}, pero acumulado por cliente/proveedor
     * en vez de en un único total global (data-model.md "Vista derivada: fila
     * de Saldos Clientes"). Excluye a quienes ya no tienen saldo pendiente (FR-002).
     *
     * @return Collection<int, array{cliente_id?: int, cliente_nombre?: string, proveedor_id?: int, proveedor_nombre?: string, a_vencer: float, vencido_0_30: float, vencido_31_60: float, vencido_61_90: float, vencido_mas_90: float, total: float}>
     */
    public function porCliente(string $tipo, ?Carbon $fecha = null): Collection
    {
        $fecha = $fecha ?? Carbon::today();

        if ($tipo === 'cliente') {
            $documentos = Venta::with(['cliente', 'cobros', 'notasCreditoDebito'])->get();
            $campoVencimiento = 'fecha_vto_cobro';
            $campoEntidad = 'cliente_id';
        } else {
            $documentos = Compra::with(['proveedor', 'pagos', 'notasCreditoDebito'])->get();
            $campoVencimiento = 'fecha_vto_pago';
            $campoEntidad = 'proveedor_id';
        }

        $acumulado = [];

        foreach ($documentos as $documento) {
            $saldo = $tipo === 'cliente' ? $documento->aCobrar() : $documento->aPagar();

            if ($saldo <= self::TOLERANCIA) {
                continue;
            }

            $entidadId = $documento->{$campoEntidad};
            $entidad = $tipo === 'cliente' ? $documento->cliente : $documento->proveedor;

            if (! isset($acumulado[$entidadId])) {
                $acumulado[$entidadId] = [
                    $campoEntidad => $entidadId,
                    ($tipo === 'cliente' ? 'cliente_nombre' : 'proveedor_nombre') => $entidad?->nombre,
                    'a_vencer' => 0.0,
                    'vencido_0_30' => 0.0,
                    'vencido_31_60' => 0.0,
                    'vencido_61_90' => 0.0,
                    'vencido_mas_90' => 0.0,
                ];
            }

            $vencimiento = $documento->{$campoVencimiento};

            if ($vencimiento === null || Carbon::parse($vencimiento)->greaterThanOrEqualTo($fecha)) {
                $acumulado[$entidadId]['a_vencer'] += $saldo;

                continue;
            }

            $diasVencido = Carbon::parse($vencimiento)->diffInDays($fecha);

            if ($diasVencido <= 30) {
                $acumulado[$entidadId]['vencido_0_30'] += $saldo;
            } elseif ($diasVencido <= 60) {
                $acumulado[$entidadId]['vencido_31_60'] += $saldo;
            } elseif ($diasVencido <= 90) {
                $acumulado[$entidadId]['vencido_61_90'] += $saldo;
            } else {
                $acumulado[$entidadId]['vencido_mas_90'] += $saldo;
            }
        }

        return collect($acumulado)
            ->map(function (array $fila) {
                foreach (['a_vencer', 'vencido_0_30', 'vencido_31_60', 'vencido_61_90', 'vencido_mas_90'] as $bucket) {
                    $fila[$bucket] = round($fila[$bucket], 2);
                }

                $fila['total'] = round(
                    $fila['a_vencer'] + $fila['vencido_0_30'] + $fila['vencido_31_60']
                    + $fila['vencido_61_90'] + $fila['vencido_mas_90'],
                    2
                );

                return $fila;
            })
            ->filter(fn (array $fila) => abs($fila['total']) > self::TOLERANCIA)
            ->values();
    }
}
