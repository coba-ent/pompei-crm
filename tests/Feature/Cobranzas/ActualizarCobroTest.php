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
use Tests\TestCase;

/** US1/US3 (spec 053) — editar cobranzas de una venta sin duplicar movimientos de tesorería. */
class ActualizarCobroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVenta(Cliente $cliente, array $overrides = []): Venta
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $payload = array_merge([
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ], $overrides);

        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    private function cobrar(Venta $venta, CuentaTesoreria $cuenta, float $monto): int
    {
        $resp = $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => $monto,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        return $resp->json('cobro.id');
    }

    public function test_editar_monto_actualiza_cobro_y_movimiento_sin_duplicar(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente); // total = 1210
        $cobroId = $this->cobrar($venta, $cuenta, 500);

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 700,
            'fecha' => now()->toDateString(),
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseCount('cobros', 1);
        $this->assertDatabaseHas('cobros', ['id' => $cobroId, 'monto' => 700.00]);
        $this->assertDatabaseCount('movimientos_tesoreria', 1);
        $this->assertDatabaseHas('movimientos_tesoreria', [
            'origen_type' => Cobro::class,
            'origen_id' => $cobroId,
            'monto' => 700.00,
        ]);
        $this->assertSame(700.0, $cuenta->fresh()->saldoA());
    }

    public function test_editar_cuenta_mueve_el_movimiento_y_deja_de_impactar_en_la_vieja(): void
    {
        $cliente = Cliente::factory()->create();
        $cuentaVieja = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaNueva = CuentaTesoreria::factory()->tipo('banco')->create();
        $venta = $this->crearVenta($cliente);
        $cobroId = $this->cobrar($venta, $cuentaVieja, 400);

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuentaNueva->id,
            'monto' => 400,
            'fecha' => now()->toDateString(),
        ])->assertOk();

        $this->assertSame(0.0, $cuentaVieja->fresh()->saldoA());
        $this->assertSame(400.0, $cuentaNueva->fresh()->saldoA());
    }

    public function test_editar_solo_la_nota_no_altera_monto_fecha_cuenta(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);
        $cobroId = $this->cobrar($venta, $cuenta, 400);
        $fecha = now()->toDateString();

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 400,
            'fecha' => $fecha,
            'nota' => 'corrección de typo',
        ])->assertOk();

        $this->assertDatabaseHas('cobros', [
            'id' => $cobroId, 'monto' => 400.00,
            'cuenta_tesoreria_id' => $cuenta->id, 'nota' => 'corrección de typo',
        ]);
        $this->assertSame($fecha, $cobroId ? \App\Models\Cobro::find($cobroId)->fecha->toDateString() : null);
    }

    public function test_editar_cobro_anulado_es_rechazado(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);
        $cobroId = $this->cobrar($venta, $cuenta, 400);

        $this->deleteJson(route('ventas.cobranzas.destroy', [$venta, $cobroId]))->assertOk();

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 400,
            'fecha' => now()->toDateString(),
        ])->assertStatus(404);
    }

    public function test_editar_cobro_de_otra_venta_responde_404(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $ventaA = $this->crearVenta($cliente);
        $ventaB = $this->crearVenta($cliente);
        $cobroId = $this->cobrar($ventaA, $cuenta, 400);

        $this->putJson(route('ventas.cobranzas.update', [$ventaB, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 400,
            'fecha' => now()->toDateString(),
        ])->assertStatus(404);
    }

    public function test_editar_cobro_sin_movimiento_asociado_no_crea_uno_nuevo(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);
        $cobroId = $this->cobrar($venta, $cuenta, 400);

        MovimientoTesoreria::where('origen_type', Cobro::class)->where('origen_id', $cobroId)->forceDelete();

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 500,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('movimientos_tesoreria', 0);
    }

    public function test_venta_totalmente_cobrada_editar_por_encima_del_total_es_rechazado(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente); // total = 1210
        $cobroId = $this->cobrar($venta, $cuenta, 1210);
        $this->assertSame('cobrada', $venta->fresh()->estadoCobro());

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('monto');

        $this->assertDatabaseHas('cobros', ['id' => $cobroId, 'monto' => 1210.00]);
    }

    public function test_venta_con_saldo_parcial_editar_dentro_del_margen_disponible_es_aceptado(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente); // total = 1210
        $cobroId = $this->cobrar($venta, $cuenta, 500);
        $this->assertSame(710.0, $venta->fresh()->aCobrar());

        // Margen disponible = aCobrar (710) + monto actual (500) = 1210.
        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertOk();

        $this->assertSame(0.0, $venta->fresh()->aCobrar());
    }

    public function test_editar_monto_a_cero_es_rechazado(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);
        $cobroId = $this->cobrar($venta, $cuenta, 400);

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 0,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('monto');
    }

    public function test_editar_con_fecha_invalida_es_rechazado(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);
        $cobroId = $this->cobrar($venta, $cuenta, 400);

        $this->putJson(route('ventas.cobranzas.update', [$venta, $cobroId]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 400,
            'fecha' => 'no-es-una-fecha',
        ])->assertStatus(422)->assertJsonValidationErrors('fecha');
    }
}
