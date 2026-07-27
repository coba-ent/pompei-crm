<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Models\Rol;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US3 — Movimiento entre Cuentas (partida doble). FR-016/FR-018/FR-024,
 * SC-002 (invariante: el total disponible no cambia).
 */
class TesoreriaTransferenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_transferir_crea_dos_filas_vinculadas_con_signos_opuestos(): void
    {
        $servicio = app(Tesoreria::class);
        $salida = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $entrada = CuentaTesoreria::factory()->tipo('efectivo')->create();

        [$movSalida, $movEntrada] = $servicio->transferir($salida, $entrada, 500, now(), 'fondeo');

        $this->assertSame(-500.0, (float) $movSalida->monto);
        $this->assertSame(500.0, (float) $movEntrada->monto);
        $this->assertNotNull($movSalida->transferencia_id);
        $this->assertSame($movSalida->transferencia_id, $movEntrada->transferencia_id);
        $this->assertSame('movimiento_entre_cuentas', $movSalida->tipo);
    }

    public function test_total_disponible_no_cambia_con_una_transferencia(): void
    {
        $servicio = app(Tesoreria::class);
        $salida = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $entrada = CuentaTesoreria::factory()->tipo('banco')->create();

        $servicio->registrarSaldoInicial($salida, 1000, now()->subDay());
        $servicio->registrarSaldoInicial($entrada, 500, now()->subDay());

        $totalAntes = $servicio->saldos()['disponible']['total'];

        $servicio->transferir($salida, $entrada, 300, now(), null);

        $totalDespues = $servicio->saldos()['disponible']['total'];

        $this->assertSame($totalAntes, $totalDespues);
        $this->assertSame(700.0, $salida->saldoA());
        $this->assertSame(800.0, $entrada->saldoA());
    }

    public function test_endpoint_transferencia_rechaza_misma_cuenta_y_monto_no_positivo(): void
    {
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->postJson(route('tesoreria.transferencias.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 100,
            'cuenta_salida_id' => $cuenta->id,
            'cuenta_entrada_id' => $cuenta->id,
        ])->assertStatus(422);

        $otra = CuentaTesoreria::factory()->tipo('banco')->create();
        $this->postJson(route('tesoreria.transferencias.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 0,
            'cuenta_salida_id' => $cuenta->id,
            'cuenta_entrada_id' => $otra->id,
        ])->assertStatus(422);
    }

    public function test_endpoint_transferencia_exitosa_actualiza_saldos(): void
    {
        $salida = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $entrada = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $response = $this->postJson(route('tesoreria.transferencias.store'), [
            'fecha' => now()->toDateString(),
            'monto' => 250,
            'cuenta_salida_id' => $salida->id,
            'cuenta_entrada_id' => $entrada->id,
            'observacion' => 'test',
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);
        $this->assertSame(-250.0, $salida->saldoA());
        $this->assertSame(250.0, $entrada->saldoA());
    }

    public function test_borrar_transferencia_revierte_ambas_patas(): void
    {
        $servicio = app(Tesoreria::class);
        $salida = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $entrada = CuentaTesoreria::factory()->tipo('efectivo')->create();

        [$movSalida] = $servicio->transferir($salida, $entrada, 400, now(), null);

        $this->deleteJson(route('tesoreria.movimientos.destroy', $movSalida))
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(0, MovimientoTesoreria::withTrashed()->where('transferencia_id', $movSalida->transferencia_id)->count());
        $this->assertSame(0.0, $salida->saldoA());
        $this->assertSame(0.0, $entrada->saldoA());
    }
}
