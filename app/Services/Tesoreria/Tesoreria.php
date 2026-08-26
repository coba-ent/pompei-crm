<?php

namespace App\Services\Tesoreria;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Punto único de la regla de dinero de Tesorería: creación de movimientos,
 * partida doble de las transferencias, y cálculo de saldos/flujo. Los módulos
 * futuros (Ingresos/Egresos) usan `registrarMovimiento()` para impactar una
 * cuenta sin que Tesorería conozca su origen (FR-030, plan.md).
 */
class Tesoreria
{
    public function __construct(private readonly CuentaCorriente $cuentaCorriente = new CuentaCorriente())
    {
    }

    /** Alta de cuenta con saldo inicial ≠ 0 (FR-002): primer asiento de su ledger. */
    public function registrarSaldoInicial(CuentaTesoreria $cuenta, float $monto, Carbon $fecha): MovimientoTesoreria
    {
        return $this->registrarMovimiento($cuenta, $monto, 'saldo_inicial', null, $fecha);
    }

    /**
     * Transferencia interna: dos filas vinculadas por `transferencia_id`, egreso
     * en la cuenta de salida e ingreso en la de entrada, dentro de una
     * transacción atómica (FR-016, research §3).
     *
     * @return array{0: MovimientoTesoreria, 1: MovimientoTesoreria} [salida, entrada]
     */
    public function transferir(
        CuentaTesoreria $salida,
        CuentaTesoreria $entrada,
        float $monto,
        Carbon $fecha,
        ?string $observacion = null
    ): array {
        return DB::transaction(function () use ($salida, $entrada, $monto, $fecha, $observacion) {
            $transferenciaId = (string) Str::uuid();

            $movSalida = $this->registrarMovimiento(
                $salida, -$monto, 'movimiento_entre_cuentas', null, $fecha,
                detalle: $entrada->nombre, observacion: $observacion, transferenciaId: $transferenciaId,
            );

            $movEntrada = $this->registrarMovimiento(
                $entrada, $monto, 'movimiento_entre_cuentas', null, $fecha,
                detalle: $salida->nombre, observacion: $observacion, transferenciaId: $transferenciaId,
            );

            return [$movSalida, $movEntrada];
        });
    }

    /**
     * API pública para que Ingresos/Egresos registren su impacto en una cuenta
     * de tesorería (FR-030) sin acoplarse a la regla de partida doble.
     */
    public function registrarMovimiento(
        CuentaTesoreria $cuenta,
        float $monto,
        string $tipo,
        ?Model $origen = null,
        ?Carbon $fecha = null,
        ?string $detalle = null,
        ?string $nroComprobante = null,
        ?string $observacion = null,
        ?string $transferenciaId = null,
    ): MovimientoTesoreria {
        return $cuenta->movimientos()->create([
            'fecha' => $fecha ?? now(),
            'tipo' => $tipo,
            'monto' => $monto,
            'detalle' => $detalle,
            'nro_comprobante' => $nroComprobante,
            'observacion' => $observacion,
            'transferencia_id' => $transferenciaId,
            'origen_type' => $origen?->getMorphClass(),
            'origen_id' => $origen?->getKey(),
            'usuario_id' => Auth::id(),
        ]);
    }

    /**
     * Busca el movimiento de un pago/cobro **importado de Contagram**, que quedó sin el vínculo
     * polimórfico (`origen_type` NULL) aunque el movimiento sí se cargó.
     *
     * El apareo es por (tipo, cuenta, fecha, monto) — los tres datos que el movimiento conserva.
     * Cuando hay varios candidatos son, por definición, **indistinguibles** entre sí: mismo día,
     * misma cuenta, mismo importe. Devolver el primero es contablemente equivalente a devolver
     * cualquier otro, y es lo que permite que anular no deje el egreso vivo en la cuenta.
     *
     * Devuelve null si no hay ningún candidato: ahí no hay nada que borrar ni que vincular.
     */
    public function movimientoHuerfanoDe(string $tipo, int $cuentaId, Carbon $fecha, float $monto): ?MovimientoTesoreria
    {
        return MovimientoTesoreria::query()
            ->where('tipo', $tipo)
            ->whereNull('origen_type')
            ->where('cuenta_tesoreria_id', $cuentaId)
            ->whereDate('fecha', $fecha->toDateString())
            ->whereRaw('ABS(monto - ?) < 0.005', [$monto])
            ->orderBy('id')
            ->first();
    }

    /**
     * Saldos por bloque para la vista Saldos (FR-010/FR-011/FR-012), a fecha de
     * corte (default hoy). Sólo cuentas visibles.
     *
     * @return array{a_cobrar: array, a_pagar: array, disponible: array}
     */
    public function saldos(?Carbon $fecha = null): array
    {
        $cuentas = CuentaTesoreria::visibles()->orderBy('orden')->orderBy('nombre')->get();

        $saldosPorCuenta = $cuentas->mapWithKeys(fn (CuentaTesoreria $c) => [$c->id => $c->saldoA($fecha)]);

        $bloque = function ($tipos) use ($cuentas, $saldosPorCuenta) {
            $items = $cuentas->whereIn('tipo', (array) $tipos)->map(fn (CuentaTesoreria $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'saldo' => $saldosPorCuenta[$c->id],
            ])->values();

            return [
                'cuentas' => $items,
                'total' => (float) $items->sum('saldo'),
            ];
        };

        $cajas = $bloque('efectivo');
        $bancos = $bloque('banco');

        $aCobrar = $bloque('a_cobrar');

        // A Pagar se muestra en positivo, como en Contagram: el bloque ya dice que es deuda, y su
        // total es la suma de las deudas (verificado contra el panel real: 22.223.085,07 +
        // 30.000,31 + 212.175,83 + 174.574,63 = 22.639.835,84). Los movimientos siguen guardados
        // en negativo — esto es sólo la convención de presentación del bloque, y por eso invierte
        // también un saldo a favor, que pasa a verse negativo.
        $aPagar = $bloque('a_pagar');
        $aPagar['cuentas'] = $aPagar['cuentas']->map(fn (array $c) => [...$c, 'saldo' => -$c['saldo']]);
        $aPagar['total'] = -$aPagar['total'];

        $saldoCtaCteClientes = $this->cuentaCorriente->aging('cliente', $fecha)['total'];
        $aCobrar['cuentas']->prepend(['id' => null, 'nombre' => 'Saldo Cta Cte Clientes', 'saldo' => $saldoCtaCteClientes]);
        $aCobrar['total'] += $saldoCtaCteClientes;

        $saldoCtaCteProveedores = $this->cuentaCorriente->aging('proveedor', $fecha)['total'];
        $aPagar['cuentas']->prepend(['id' => null, 'nombre' => 'Saldo Cta Cte Proveedores', 'saldo' => $saldoCtaCteProveedores]);
        $aPagar['total'] += $saldoCtaCteProveedores;

        return [
            'a_cobrar' => $aCobrar,
            'a_pagar' => $aPagar,
            'disponible' => [
                'cajas' => $cajas,
                'bancos' => $bancos,
                'total' => $cajas['total'] + $bancos['total'],
            ],
        ];
    }

    /**
     * Informe de flujo de caja (FR-026/FR-027/FR-028): Cobros = tipo `cobro` **e `ingreso`**
     * (spec 055 — los Otros Ingresos suman dentro de "Cobros", como ya declaraba el banner de
     * la pantalla; el `tipo` los sigue distinguiendo para cualquier análisis posterior),
     * Pagos = tipo `pago`/`gasto` (los gastos pendientes no generan movimiento
     * de tesorería hasta pagarse, así que ya quedan excluidos por construcción
     * — data-model.md §Cálculos clave). Desglose por cuenta siempre completo;
     * `cuentasActivas` (ids), si se pasa, sólo afecta el total mostrado.
     *
     * @param  array<int>|null  $cuentasActivas
     * @return array{total_cobros: float, total_pagos: float, resultado: float, cobros: array, pagos: array}
     */
    public function flujo(Carbon $desde, Carbon $hasta, ?array $cuentasActivas = null): array
    {
        $desglose = function (array $tipos, bool $absoluto) use ($desde, $hasta) {
            return MovimientoTesoreria::query()
                ->join('cuentas_tesoreria', 'cuentas_tesoreria.id', '=', 'movimientos_tesoreria.cuenta_tesoreria_id')
                ->whereIn('movimientos_tesoreria.tipo', $tipos)
                ->whereBetween('movimientos_tesoreria.fecha', [$desde->toDateString(), $hasta->toDateString()])
                ->groupBy('cuentas_tesoreria.id', 'cuentas_tesoreria.nombre')
                ->select('cuentas_tesoreria.id as cuenta_id', 'cuentas_tesoreria.nombre')
                ->selectRaw(($absoluto ? '-' : '').'SUM(movimientos_tesoreria.monto) as monto')
                ->get()
                ->map(fn ($fila) => [
                    'cuenta_id' => (int) $fila->cuenta_id,
                    'nombre' => $fila->nombre,
                    'monto' => (float) $fila->monto,
                ])
                ->values()
                ->all();
        };

        $cobros = $desglose(['cobro', 'ingreso'], absoluto: false);
        $pagos = $desglose(['pago', 'gasto'], absoluto: true);

        $filtrar = fn (array $filas) => $cuentasActivas === null
            ? $filas
            : array_values(array_filter($filas, fn ($f) => in_array($f['cuenta_id'], $cuentasActivas, true)));

        // El cast no es cosmético: sobre un desglose vacío `array_sum()` devuelve `int 0`,
        // y el contrato de este método declara floats.
        $totalCobros = (float) array_sum(array_column($filtrar($cobros), 'monto'));
        $totalPagos = (float) array_sum(array_column($filtrar($pagos), 'monto'));

        return [
            'total_cobros' => $totalCobros,
            'total_pagos' => $totalPagos,
            'resultado' => $totalCobros - $totalPagos,
            'cobros' => $cobros,
            'pagos' => $pagos,
        ];
    }
}
