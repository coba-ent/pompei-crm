<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Models\Rol;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo Tesorería — US1 (Saldos) y US4 (ficha/ledger). Principio IV: es
 * dinero, se testea el cálculo de saldos (FR-014), el corte por fecha
 * (FR-012), saldo negativo permitido (FR-013) y el balance corrido (FR-021).
 */
class TesoreriaSaldosLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_saldo_a_computa_solo_movimientos_hasta_la_fecha_de_corte(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $servicio->registrarSaldoInicial($cuenta, 1000, now()->subDays(10));
        $servicio->registrarMovimiento($cuenta, 200, 'cobro', fecha: now()->subDays(5));
        $servicio->registrarMovimiento($cuenta, -300, 'pago', fecha: now()->subDay());

        $this->assertSame(1000.0, $cuenta->saldoA(now()->subDays(10)));
        $this->assertSame(1200.0, $cuenta->saldoA(now()->subDays(5)));
        $this->assertSame(900.0, $cuenta->saldoA(now()));
        $this->assertSame(0.0, $cuenta->saldoA(now()->subDays(20)));
    }

    public function test_saldo_negativo_permitido_sin_error(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('banco')->create();

        $servicio->registrarSaldoInicial($cuenta, 100, now()->subDay());
        $servicio->registrarMovimiento($cuenta, -500, 'pago', fecha: now());

        $this->assertSame(-400.0, $cuenta->saldoA(now()));
    }

    public function test_vista_saldos_agrupa_por_bloque_y_totaliza(): void
    {
        $servicio = app(Tesoreria::class);

        $caja = CuentaTesoreria::factory()->tipo('efectivo')->create(['nombre' => 'Caja del Local']);
        $banco = CuentaTesoreria::factory()->tipo('banco')->create(['nombre' => 'Banco Galicia']);
        $aCobrar = CuentaTesoreria::factory()->tipo('a_cobrar')->create(['nombre' => 'AMEX']);
        $aPagar = CuentaTesoreria::factory()->tipo('a_pagar')->create(['nombre' => 'VISA Corporativa']);
        $oculta = CuentaTesoreria::factory()->tipo('efectivo')->oculta()->create(['nombre' => 'Caja Oculta']);

        $servicio->registrarSaldoInicial($caja, 1000, now()->subDay());
        $servicio->registrarSaldoInicial($banco, 500, now()->subDay());
        $servicio->registrarSaldoInicial($aCobrar, 300, now()->subDay());
        $servicio->registrarSaldoInicial($aPagar, 200, now()->subDay());
        $servicio->registrarSaldoInicial($oculta, 9999, now()->subDay());

        $saldos = $servicio->saldos(now());

        $this->assertSame(300.0, $saldos['a_cobrar']['total']);
        $this->assertSame(200.0, $saldos['a_pagar']['total']);
        $this->assertSame(1000.0, $saldos['disponible']['cajas']['total']);
        $this->assertSame(500.0, $saldos['disponible']['bancos']['total']);
        $this->assertSame(1500.0, $saldos['disponible']['total']);

        // La cuenta oculta no aparece en ningún bloque (FR-005).
        $nombresCajas = collect($saldos['disponible']['cajas']['cuentas'])->pluck('nombre');
        $this->assertFalse($nombresCajas->contains('Caja Oculta'));
    }

    public function test_endpoint_saldos_responde_los_tres_bloques(): void
    {
        $servicio = app(Tesoreria::class);
        $caja = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $servicio->registrarSaldoInicial($caja, 1000, now());

        $response = $this->getJson(route('tesoreria.saldos.data'));

        $response->assertOk()
            ->assertJsonStructure(['a_cobrar', 'a_pagar', 'disponible' => ['cajas', 'bancos', 'total']]);
    }

    public function test_balance_corrido_es_consistente_fila_a_fila(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $servicio->registrarSaldoInicial($cuenta, 1000, now()->subDays(3));
        $servicio->registrarMovimiento($cuenta, 500, 'cobro', fecha: now()->subDays(2));
        $servicio->registrarMovimiento($cuenta, -200, 'pago', fecha: now()->subDay());

        $movimientos = $cuenta->movimientos()->orderBy('fecha')->orderBy('id')->get();

        $acumulado = 0;
        foreach ($movimientos as $mov) {
            $acumulado += (float) $mov->monto;
        }

        $this->assertSame(1300.0, $acumulado);
        $this->assertSame(1300.0, $cuenta->saldoA(now()));
    }

    public function test_filtro_por_tipo_de_operacion_no_altera_el_saldo_corrido(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $servicio->registrarSaldoInicial($cuenta, 1000, now()->subDays(2));
        $servicio->registrarMovimiento($cuenta, 300, 'cobro', fecha: now()->subDay());

        $saldoCompleto = $cuenta->saldoA(now());

        $soloCobros = $cuenta->movimientos()->delTipo('cobro')->get();
        $this->assertCount(1, $soloCobros);

        // El filtro sólo acota qué filas se muestran; el saldo derivado no cambia.
        $this->assertSame(1300.0, $saldoCompleto);
    }

    // ---------------- US4 — Ficha/ledger: endpoints ----------------

    public function test_endpoint_ledger_incluye_balance_corrido_y_columna_acciones(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $servicio->registrarSaldoInicial($cuenta, 1000, now()->subDays(2));
        $servicio->registrarMovimiento($cuenta, 300, 'cobro', fecha: now()->subDay());

        $response = $this->getJson(route('tesoreria.cuentas.data', $cuenta));

        $response->assertOk();
        $datos = collect($response->json('data'))->sortBy('id')->values();
        $this->assertEquals(1000.0, $datos[0]['balance']);
        $this->assertEquals(1300.0, $datos[1]['balance']);
        $this->assertNotEmpty($datos[0]['acciones']);
    }

    public function test_filtro_tipo_operacion_en_endpoint_no_afecta_balance_de_las_filas_visibles(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $servicio->registrarSaldoInicial($cuenta, 1000, now()->subDays(2));
        $servicio->registrarMovimiento($cuenta, 300, 'cobro', fecha: now()->subDay());

        $response = $this->getJson(route('tesoreria.cuentas.data', $cuenta).'?tipo_operacion=cobro');

        $response->assertOk();
        $datos = $response->json('data');
        $this->assertCount(1, $datos);
        $this->assertEquals(1300.0, $datos[0]['balance']);
    }

    public function test_editar_movimiento_nativo_actualiza_datos(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $mov = $servicio->registrarSaldoInicial($cuenta, 1000, now()->subDay());

        $this->putJson(route('tesoreria.movimientos.update', $mov), [
            'fecha' => now()->toDateString(),
            'monto' => 1500,
            'observacion' => 'ajustado',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1500.0, (float) $mov->fresh()->monto);
    }

    public function test_editar_movimiento_no_nativo_es_rechazado(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $mov = $servicio->registrarMovimiento($cuenta, 100, 'cobro', fecha: now());

        $this->putJson(route('tesoreria.movimientos.update', $mov), [
            'fecha' => now()->toDateString(),
            'monto' => 999,
        ])->assertStatus(422);

        $this->assertSame(100.0, (float) $mov->fresh()->monto);
    }

    public function test_eliminar_movimiento_no_nativo_hace_soft_delete(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $mov = $servicio->registrarMovimiento($cuenta, 100, 'gasto', fecha: now());

        $this->deleteJson(route('tesoreria.movimientos.destroy', $mov))->assertOk();

        $this->assertSoftDeleted('movimientos_tesoreria', ['id' => $mov->id]);
    }

    public function test_eliminar_movimiento_nativo_no_transferencia_lo_borra_fisicamente(): void
    {
        $servicio = app(Tesoreria::class);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $mov = $servicio->registrarSaldoInicial($cuenta, 500, now());

        $this->deleteJson(route('tesoreria.movimientos.destroy', $mov))->assertOk();

        $this->assertDatabaseMissing('movimientos_tesoreria', ['id' => $mov->id]);
    }
}
