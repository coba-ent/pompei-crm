<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\MovimientoTesoreria;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 110 (US3) — editar y anular una cobranza con vuelto sin dejar movimientos inconsistentes.
 *
 * El test de anulación es la verificación de la barrera más importante de la spec: desde que un
 * cobro puede tener dos movimientos con el mismo `origen`, un `morphOne` sin filtrar por `tipo`
 * devolvería cualquiera de los dos y anular podría dejar el ingreso vivo en la cuenta.
 */
class VueltoEdicionAnulacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    /** Venta de total 1210. */
    private function crearVenta(): Venta
    {
        $cliente = Cliente::factory()->create();
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21']],
        ])->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    private function cobrarConVuelto(Venta $venta, CuentaTesoreria $cuenta, CuentaTesoreria $cuentaVuelto): int
    {
        return $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500, 'vuelto' => 290, 'cuenta_vuelto_id' => $cuentaVuelto->id,
            'fecha' => now()->toDateString(),
        ])->assertCreated()->json('cobro.id');
    }

    /** Movimientos VIVOS de un cobro, por tipo. */
    private function movimientos(int $cobroId): array
    {
        return MovimientoTesoreria::where('origen_type', Cobro::class)
            ->where('origen_id', $cobroId)
            ->get()->pluck('monto', 'tipo')->map(fn ($m) => (float) $m)->all();
    }

    // ---------------------------------------------------------------- T024: anulación (crítico)

    public function test_anular_una_cobranza_con_vuelto_no_deja_ningun_movimiento_vivo(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaVuelto = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta();
        $cobroId = $this->cobrarConVuelto($venta, $cuenta, $cuentaVuelto);

        $this->assertCount(2, $this->movimientos($cobroId));

        $this->deleteJson(route('ventas.cobranzas.destroy', [$venta, $cobroId]))->assertOk();

        // Ni ingreso ni vuelto: si quedara alguno, la cuenta tendría saldo fantasma.
        $this->assertCount(0, $this->movimientos($cobroId));
        $this->assertSame(0.0, $cuenta->fresh()->saldoA());
        $this->assertSame(0.0, $cuentaVuelto->fresh()->saldoA());
        $this->assertSoftDeleted('cobros', ['id' => $cobroId]);
    }

    /** La relación tiene que devolver el movimiento de INGRESO, no el de vuelto. */
    public function test_la_relacion_movimiento_tesoreria_devuelve_el_ingreso_y_no_el_vuelto(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaVuelto = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta();
        $cobro = Cobro::findOrFail($this->cobrarConVuelto($venta, $cuenta, $cuentaVuelto));

        $this->assertSame('cobro', $cobro->movimientoTesoreria->tipo);
        $this->assertSame(1500.0, (float) $cobro->movimientoTesoreria->monto);
        $this->assertSame('vuelto', $cobro->movimientoVuelto->tipo);
        $this->assertSame(-290.0, (float) $cobro->movimientoVuelto->monto);
    }

    // ---------------------------------------------------------------- T023: edición

    public function test_editar_actualiza_ambos_movimientos(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaVuelto = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta();
        $cobroId = $this->cobrarConVuelto($venta, $cuenta, $cuentaVuelto);

        // Recibe 2000 y devuelve 790 => el neto sigue siendo 1210.
        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 2000, 'vuelto' => 790, 'cuenta_vuelto_id' => $cuentaVuelto->id,
            'fecha' => now()->toDateString(),
        ])->assertOk();

        $this->assertSame(['cobro' => 2000.0, 'vuelto' => -790.0], $this->movimientos($cobroId));
        $this->assertDatabaseHas('cobros', ['id' => $cobroId, 'monto' => 1210.00, 'vuelto' => 790.00]);
        $this->assertSame(0.0, $venta->fresh()->aCobrar());
    }

    public function test_agregar_vuelto_a_una_cobranza_que_no_lo_tenia_crea_el_movimiento(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaVuelto = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta();

        $cobroId = $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 1210, 'fecha' => now()->toDateString(),
        ])->assertCreated()->json('cobro.id');

        $this->assertCount(1, $this->movimientos($cobroId));

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500, 'vuelto' => 290, 'cuenta_vuelto_id' => $cuentaVuelto->id,
            'fecha' => now()->toDateString(),
        ])->assertOk();

        $this->assertSame(['cobro' => 1500.0, 'vuelto' => -290.0], $this->movimientos($cobroId));
    }

    /** Quitar el vuelto tiene que revertir el egreso: si no, queda vivo en la cuenta. */
    public function test_quitar_el_vuelto_revierte_el_movimiento_de_vuelto(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaVuelto = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta();
        $cobroId = $this->cobrarConVuelto($venta, $cuenta, $cuentaVuelto);

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210, 'vuelto' => 0,
            'fecha' => now()->toDateString(),
        ])->assertOk();

        $this->assertSame(['cobro' => 1210.0], $this->movimientos($cobroId));
        $this->assertDatabaseHas('cobros', ['id' => $cobroId, 'monto' => 1210.00, 'vuelto' => null, 'cuenta_vuelto_id' => null]);
        $this->assertSame(0.0, $cuentaVuelto->fresh()->saldoA());
    }

    public function test_editar_rechaza_un_neto_que_no_salda_la_venta(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaVuelto = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta();
        $cobroId = $this->cobrarConVuelto($venta, $cuenta, $cuentaVuelto);

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 2000, 'vuelto' => 1000, 'cuenta_vuelto_id' => $cuentaVuelto->id,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('monto');

        // Nada cambió.
        $this->assertSame(['cobro' => 1500.0, 'vuelto' => -290.0], $this->movimientos($cobroId));
    }
}
