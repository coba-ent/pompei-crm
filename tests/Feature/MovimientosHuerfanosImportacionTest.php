<?php

namespace Tests\Feature;

use App\Models\Cobro;
use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Models\Pago;
use App\Models\Proveedor;
use App\Services\Egresos\Pagos;
use App\Services\Ingresos\Cobranzas;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pagos/cobros importados de Contagram: el movimiento de tesorería existe pero quedó sin el
 * vínculo polimórfico. Antes de esto, anularlos borraba el pago y dejaba el egreso vivo en la
 * cuenta — descuadre silencioso.
 */
class MovimientosHuerfanosImportacionTest extends TestCase
{
    use RefreshDatabase;

    /** Reproduce el estado que dejó la importación: pago cargado + movimiento SIN `origen_type`. */
    private function pagoImportado(CuentaTesoreria $cuenta, float $monto, string $fecha = '2021-11-11'): Pago
    {
        $compra = Compra::factory()->create([
            'proveedor_id' => Proveedor::factory()->create()->id,
            'legacy_id' => 'CMP-'.uniqid(),
        ]);

        $pago = Pago::create([
            'compra_id' => $compra->id,
            'fecha' => $fecha,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => $monto,
        ]);

        MovimientoTesoreria::create([
            'legacy_id' => 'TES-'.$cuenta->id.'-PAG-'.uniqid().'-'.str_replace('-', '', $fecha).'--'.((int) ($monto * 100)),
            'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => $fecha,
            'tipo' => 'pago',
            'monto' => -$monto,
            'detalle' => 'ALEPH',
        ]);

        return $pago;
    }

    private function saldoDeMovimientos(CuentaTesoreria $cuenta): float
    {
        return (float) MovimientoTesoreria::where('cuenta_tesoreria_id', $cuenta->id)->sum('monto');
    }

    public function test_anular_un_pago_importado_ya_no_deja_el_egreso_vivo_en_la_cuenta(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $pago = $this->pagoImportado($cuenta, 683.08);

        $this->assertNull($pago->movimientoTesoreria, 'el escenario exige que NO haya vínculo');
        $this->assertEqualsWithDelta(-683.08, $this->saldoDeMovimientos($cuenta), 0.01);

        app(Pagos::class)->anularPago($pago);

        // El bug: el pago se borraba y esto seguía en -683,08.
        $this->assertEqualsWithDelta(0.0, $this->saldoDeMovimientos($cuenta), 0.01);
        $this->assertSoftDeleted('pagos', ['id' => $pago->id]);
    }

    public function test_dos_pagos_identicos_consumen_un_movimiento_cada_uno(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $primero = $this->pagoImportado($cuenta, 1000);
        $segundo = $this->pagoImportado($cuenta, 1000);

        $this->assertEqualsWithDelta(-2000.0, $this->saldoDeMovimientos($cuenta), 0.01);

        app(Pagos::class)->anularPago($primero);
        $this->assertEqualsWithDelta(-1000.0, $this->saldoDeMovimientos($cuenta), 0.01);

        app(Pagos::class)->anularPago($segundo);
        $this->assertEqualsWithDelta(0.0, $this->saldoDeMovimientos($cuenta), 0.01);
    }

    public function test_anular_un_cobro_importado_tampoco_deja_el_ingreso_vivo(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = \App\Models\Venta::factory()->create();

        $cobro = Cobro::create([
            'venta_id' => $venta->id,
            'fecha' => '2021-11-11',
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 500,
        ]);

        MovimientoTesoreria::create([
            'legacy_id' => 'TES-'.$cuenta->id.'-COB-99-20211111--50000',
            'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2021-11-11',
            'tipo' => 'cobro',
            'monto' => 500,
        ]);

        $this->assertNull($cobro->movimientoTesoreria);
        $this->assertEqualsWithDelta(500.0, $this->saldoDeMovimientos($cuenta), 0.01);

        app(Cobranzas::class)->anularCobro($cobro);

        $this->assertEqualsWithDelta(0.0, $this->saldoDeMovimientos($cuenta), 0.01);
        $this->assertSoftDeleted('cobros', ['id' => $cobro->id]);
    }

    public function test_editar_un_pago_importado_lo_vincula_y_ajusta_el_movimiento(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $pago = $this->pagoImportado($cuenta, 1000);

        app(Pagos::class)->actualizarPago($pago, 700, $cuenta, Carbon::parse('2021-11-11'));

        // Queda vinculado para siempre y el movimiento acompaña al nuevo importe.
        $movimiento = $pago->fresh()->movimientoTesoreria;
        $this->assertNotNull($movimiento, 'la edición tiene que dejar el vínculo puesto');
        $this->assertEqualsWithDelta(-700.0, (float) $movimiento->monto, 0.01);
        $this->assertEqualsWithDelta(-700.0, $this->saldoDeMovimientos($cuenta), 0.01);
    }

    public function test_el_comando_revincula_y_es_idempotente(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $pago = $this->pagoImportado($cuenta, 1234.56);

        // Sin --aplicar no escribe nada.
        $this->artisan('tesoreria:revincular-movimientos')->assertSuccessful();
        $this->assertNull($pago->fresh()->movimientoTesoreria);

        $this->artisan('tesoreria:revincular-movimientos --aplicar')->assertSuccessful();
        $this->assertNotNull($pago->fresh()->movimientoTesoreria);

        $saldo = $this->saldoDeMovimientos($cuenta);

        // Segunda corrida: ya no queda nada pendiente y los saldos no se mueven.
        $this->artisan('tesoreria:revincular-movimientos --aplicar')->assertSuccessful();
        $this->assertEqualsWithDelta($saldo, $this->saldoDeMovimientos($cuenta), 0.01);
        $this->assertSame(1, MovimientoTesoreria::where('cuenta_tesoreria_id', $cuenta->id)->count());
    }

    public function test_un_pago_ya_vinculado_no_se_lleva_un_segundo_movimiento(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        // Pago nativo: nace con su movimiento vinculado.
        $compra = Compra::factory()->create(['proveedor_id' => Proveedor::factory()->create()->id]);
        $sano = app(Pagos::class)->registrarPago($compra, 750, $cuenta, Carbon::parse('2021-11-11'));
        $this->assertNotNull($sano->movimientoTesoreria);

        // Y un huérfano que calza EXACTO con él: mismo día, misma cuenta, mismo importe. Si el
        // comando tratara al pago sano como pendiente, se llevaría este movimiento de arriba y
        // quedaría con dos.
        MovimientoTesoreria::create([
            'legacy_id' => 'TES-'.$cuenta->id.'-PAG-777-20211111--75000',
            'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2021-11-11',
            'tipo' => 'pago',
            'monto' => -750,
        ]);

        $this->artisan('tesoreria:revincular-movimientos --aplicar')->assertSuccessful();

        $this->assertSame(
            1,
            MovimientoTesoreria::where('origen_type', $sano->getMorphClass())->where('origen_id', $sano->id)->count(),
            'un pago que ya tenía su movimiento no puede terminar con dos',
        );
        $this->assertSame(1, MovimientoTesoreria::whereNull('origen_type')->count(), 'el huérfano ajeno sigue libre');
    }

    public function test_un_pago_sin_ningun_movimiento_no_inventa_uno(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $compra = Compra::factory()->create(['proveedor_id' => Proveedor::factory()->create()->id]);

        $pago = Pago::create([
            'compra_id' => $compra->id,
            'fecha' => '2021-11-11',
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 999,
        ]);

        $this->assertNull(app(Tesoreria::class)->movimientoHuerfanoDe('pago', $cuenta->id, Carbon::parse('2021-11-11'), -999));

        app(Pagos::class)->anularPago($pago);
        $this->assertSoftDeleted('pagos', ['id' => $pago->id]);
        $this->assertEqualsWithDelta(0.0, $this->saldoDeMovimientos($cuenta), 0.01);
    }
}
