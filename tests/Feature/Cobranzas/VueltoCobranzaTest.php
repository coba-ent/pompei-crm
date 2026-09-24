<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 110 — vuelto en la cobranza de una Venta (US1).
 *
 * El caso del negocio: el cliente paga con dólares, el importe convertido supera el saldo, y se le
 * devuelve la diferencia en efectivo en el acto. El sistema registra DOS movimientos de tesorería
 * (ingreso por lo recibido, egreso por el vuelto) e imputa a la venta sólo el neto.
 *
 * ⚠️ Estos tests corren en SQLite, que **no valida ENUMs**: que acá pase `tipo='vuelto'` no prueba
 * que MySQL lo acepte. Esa verificación es `SHOW COLUMNS` contra MySQL — ver quickstart.md §2.
 */
class VueltoCobranzaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    /** Venta de total 1210 (1000 + 21% IVA). */
    private function crearVenta(Cliente $cliente): Venta
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- T009: caso feliz

    public function test_cobranza_con_vuelto_imputa_el_neto_y_registra_dos_movimientos(): void
    {
        $cliente = Cliente::factory()->create();
        $cuentaCobro = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $cuentaVuelto = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente); // total = 1210

        // Recibe 1500, devuelve 290 => neto 1210, que salda exactamente la venta.
        $resp = $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuentaCobro->id,
            'monto' => 1500,
            'vuelto' => 290,
            'cuenta_vuelto_id' => $cuentaVuelto->id,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $cobroId = $resp->json('cobro.id');

        // El cobro guarda el NETO, no lo recibido.
        $this->assertDatabaseHas('cobros', [
            'id' => $cobroId,
            'monto' => 1210.00,
            'vuelto' => 290.00,
            'cuenta_vuelto_id' => $cuentaVuelto->id,
        ]);

        // La venta queda saldada por el neto.
        $this->assertSame(0.0, $venta->fresh()->aCobrar());
        $this->assertSame('cobrada', $venta->fresh()->estadoCobro());

        // Dos movimientos, uno por cada pata.
        $this->assertDatabaseHas('movimientos_tesoreria', [
            'origen_type' => Cobro::class, 'origen_id' => $cobroId,
            'tipo' => 'cobro', 'cuenta_tesoreria_id' => $cuentaCobro->id, 'monto' => 1500.00,
        ]);
        $this->assertDatabaseHas('movimientos_tesoreria', [
            'origen_type' => Cobro::class, 'origen_id' => $cobroId,
            'tipo' => 'vuelto', 'cuenta_tesoreria_id' => $cuentaVuelto->id, 'monto' => -290.00,
        ]);

        // Los saldos reflejan el movimiento físico de la plata.
        $this->assertSame(1500.0, $cuentaCobro->fresh()->saldoA());
        $this->assertSame(-290.0, $cuentaVuelto->fresh()->saldoA());
    }

    public function test_el_vuelto_puede_salir_de_la_misma_cuenta_del_ingreso(): void
    {
        $cliente = Cliente::factory()->create();
        $caja = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $caja->id,
            'monto' => 1500,
            'vuelto' => 290,
            'cuenta_vuelto_id' => $caja->id,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        // Dos movimientos separados, NO uno neteado: el arqueo tiene que coincidir con lo que
        // físicamente pasó por la caja (entraron 1500, salieron 290).
        $this->assertDatabaseCount('movimientos_tesoreria', 2);
        $this->assertSame(1210.0, $caja->fresh()->saldoA());
    }

    /** El accessor expone lo que el cliente entregó, que no es lo que guarda `monto`. */
    public function test_recibido_reconstruye_el_importe_entregado(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $resp = $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500, 'vuelto' => 290, 'cuenta_vuelto_id' => $cuenta->id,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $cobro = Cobro::findOrFail($resp->json('cobro.id'));

        $this->assertSame(1500.0, $cobro->recibido());
        $this->assertSame(1210.0, (float) $cobro->monto);
        $this->assertTrue($cobro->tieneVuelto());
    }

    // ---------------------------------------------------------------- T010: rechazos

    public function test_rechaza_vuelto_mayor_o_igual_al_importe_recibido(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500, 'vuelto' => 1500, 'cuenta_vuelto_id' => $cuenta->id,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('vuelto');

        $this->assertDatabaseCount('cobros', 0);
    }

    public function test_rechaza_vuelto_sin_cuenta_de_vuelto(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500, 'vuelto' => 290,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('cuenta_vuelto_id');

        $this->assertDatabaseCount('cobros', 0);
    }

    /** FR-007: el neto tiene que saldar EXACTAMENTE la venta — no alcanza con no pasarse. */
    public function test_rechaza_neto_que_deja_saldo_pendiente(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente); // 1210

        // Recibe 1500, devuelve 500 => neto 1000, deja 210 pendientes. Se rechaza.
        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500, 'vuelto' => 500, 'cuenta_vuelto_id' => $cuenta->id,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('monto');

        $this->assertDatabaseCount('cobros', 0);
    }

    public function test_rechaza_neto_que_supera_el_saldo(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente); // 1210

        // Neto 1400 > 1210: esta spec NO habilita sobrepagos.
        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500, 'vuelto' => 100, 'cuenta_vuelto_id' => $cuenta->id,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('monto');

        $this->assertDatabaseCount('cobros', 0);
    }

    /** Sin vuelto la validación sigue siendo la de siempre: no se puede superar el saldo. */
    public function test_sin_vuelto_sigue_sin_poder_superar_el_saldo(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 5000,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('monto');
    }

    // ---------------------------------------------------------------- T012: compatibilidad

    public function test_cobranza_sin_vuelto_se_comporta_como_antes(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $resp = $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $cobroId = $resp->json('cobro.id');

        $this->assertDatabaseHas('cobros', ['id' => $cobroId, 'monto' => 1210.00, 'vuelto' => null, 'cuenta_vuelto_id' => null]);

        // UN solo movimiento, como siempre.
        $this->assertDatabaseCount('movimientos_tesoreria', 1);
        $this->assertSame(1210.0, $cuenta->fresh()->saldoA());
        $this->assertSame('cobrada', $venta->fresh()->estadoCobro());

        $cobro = Cobro::findOrFail($cobroId);
        $this->assertFalse($cobro->tieneVuelto());
        $this->assertSame(1210.0, $cobro->recibido());
        $this->assertNotNull($cobro->movimientoTesoreria);
        $this->assertNull($cobro->movimientoVuelto);
    }

    /** Un vuelto en cero es "sin vuelto": no debe crear el segundo movimiento. */
    public function test_vuelto_en_cero_no_crea_movimiento_de_vuelto(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210, 'vuelto' => 0,
            'fecha' => now()->toDateString(),
        ])->assertCreated();

        $this->assertDatabaseCount('movimientos_tesoreria', 1);
    }

    // ---------------------------------------------------------------- T011: atomicidad

    /** FR-008: o quedan los dos movimientos, o no queda ninguno. */
    public function test_si_falla_el_segundo_movimiento_no_queda_nada(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $venta = $this->crearVenta($cliente);

        // Una cuenta de vuelto inexistente hace fallar la FK después de crear el cobro y el
        // primer movimiento. La transacción tiene que revertir todo.
        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1500, 'vuelto' => 290, 'cuenta_vuelto_id' => 999999,
            'fecha' => now()->toDateString(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('cobros', 0);
        $this->assertDatabaseCount('movimientos_tesoreria', 0);
        $this->assertSame(0.0, $cuenta->fresh()->saldoA());
    }
}
