<?php

namespace App\Services\Tesoreria;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Proveedor;
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

    /**
     * Clasifica un saldo en un bucket de antigüedad según `$vencimiento` (fecha
     * de referencia) comparado contra `$fecha` (hoy, salvo test). Fecha nula
     * cae en "a_vencer" (research.md R5) — mismo criterio para Venta/Compra
     * (`fecha_vto_cobro`/`fecha_vto_pago`) y para saldo inicial
     * (`saldo_inicial_fecha`), sin duplicar la lógica de corte 30/60/90 (R1).
     *
     * @return string una de: 'a_vencer', '0_30', '31_60', '61_90', 'mas_90'
     */
    private function clasificarBucket(mixed $vencimiento, Carbon $fecha): string
    {
        if ($vencimiento === null || Carbon::parse($vencimiento)->greaterThanOrEqualTo($fecha)) {
            return 'a_vencer';
        }

        $diasVencido = Carbon::parse($vencimiento)->diffInDays($fecha);

        if ($diasVencido <= 30) {
            return '0_30';
        }

        if ($diasVencido <= 60) {
            return '31_60';
        }

        if ($diasVencido <= 90) {
            return '61_90';
        }

        return 'mas_90';
    }

    /** @return Collection<int, object{saldo_inicial: string, saldo_inicial_fecha: ?string}> */
    private function entidadesConSaldoInicial(string $tipo): Collection
    {
        $modelo = $tipo === 'cliente' ? Cliente::class : Proveedor::class;

        return $modelo::where('saldo_inicial', '!=', 0)->get(['id', 'nombre', 'saldo_inicial', 'saldo_inicial_fecha']);
    }

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

            $bucket = $this->clasificarBucket($documento->{$campoVencimiento}, $fecha);
            $buckets[$bucket] += $saldo;

            if ($bucket !== 'a_vencer') {
                $buckets['vencido'] += $saldo;
            }
        }

        // Saldo inicial de Cliente/Proveedor (spec 031): a diferencia de los
        // documentos, no se descarta por `saldo <= TOLERANCIA` sino sólo si es
        // ≈0 (research.md R2) — un saldo negativo (a favor) resta del total.
        foreach ($this->entidadesConSaldoInicial($tipo) as $entidad) {
            $saldo = (float) $entidad->saldo_inicial;

            if (abs($saldo) <= self::TOLERANCIA) {
                continue;
            }

            $bucket = $this->clasificarBucket($entidad->saldo_inicial_fecha, $fecha);
            $buckets[$bucket] += $saldo;

            if ($bucket !== 'a_vencer') {
                $buckets['vencido'] += $saldo;
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

            $bucket = $this->clasificarBucket($documento->{$campoVencimiento}, $fecha);
            $clave = $bucket === 'a_vencer' ? 'a_vencer' : "vencido_{$bucket}";
            $acumulado[$entidadId][$clave] += $saldo;
        }

        // Saldo inicial de Cliente/Proveedor (spec 031): crea la fila si el
        // Cliente/Proveedor todavía no tiene ninguna Venta/Compra (R3), y suma
        // con signo (positivo o negativo) sin descartar por `saldo <= TOLERANCIA`
        // — sólo si es ≈0 (research.md R2, FR-005).
        foreach ($this->entidadesConSaldoInicial($tipo) as $entidad) {
            $saldo = (float) $entidad->saldo_inicial;

            if (abs($saldo) <= self::TOLERANCIA) {
                continue;
            }

            if (! isset($acumulado[$entidad->id])) {
                $acumulado[$entidad->id] = [
                    $campoEntidad => $entidad->id,
                    ($tipo === 'cliente' ? 'cliente_nombre' : 'proveedor_nombre') => $entidad->nombre,
                    'a_vencer' => 0.0,
                    'vencido_0_30' => 0.0,
                    'vencido_31_60' => 0.0,
                    'vencido_61_90' => 0.0,
                    'vencido_mas_90' => 0.0,
                ];
            }

            $bucket = $this->clasificarBucket($entidad->saldo_inicial_fecha, $fecha);
            $clave = $bucket === 'a_vencer' ? 'a_vencer' : "vencido_{$bucket}";
            $acumulado[$entidad->id][$clave] += $saldo;
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
