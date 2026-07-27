<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_transferencia_ajusta_saldos_y_es_un_unico_movimiento(): void
    {
        $caja = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);
        $banco = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $this->postJson(route('tesoreria.transferencia'), [
            'cuenta_origen_id' => $caja->id,
            'cuenta_destino_id' => $banco->id,
            'monto' => 400,
            'fecha' => '2026-07-18',
            'descripcion' => 'Depósito',
        ])->assertOk()->assertJson(['ok' => true]);

        $tesoreria = new Tesoreria();
        $this->assertSame(600.0, $tesoreria->saldoDe($caja->fresh()));
        $this->assertSame(400.0, $tesoreria->saldoDe($banco->fresh()));

        // Un único registro (FR-012).
        $this->assertSame(1, MovimientoTesoreria::where('tipo', 'transferencia')->count());
    }

    public function test_invariante_suma_de_saldos(): void
    {
        $a = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);
        $b = CuentaTesoreria::factory()->create(['saldo_inicial' => 500]);

        $tesoreria = new Tesoreria();
        $totalAntes = $tesoreria->saldoDe($a) + $tesoreria->saldoDe($b);

        $tesoreria->transferir($a, $b, 300, '2026-07-18');

        $totalDespues = $tesoreria->saldoDe($a->fresh()) + $tesoreria->saldoDe($b->fresh());
        $this->assertSame($totalAntes, $totalDespues);
    }

    public function test_rechaza_origen_igual_destino(): void
    {
        $caja = CuentaTesoreria::factory()->create();

        $this->postJson(route('tesoreria.transferencia'), [
            'cuenta_origen_id' => $caja->id,
            'cuenta_destino_id' => $caja->id,
            'monto' => 100,
            'fecha' => '2026-07-18',
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertSame(0, MovimientoTesoreria::count());
    }

    public function test_rechaza_monto_no_positivo(): void
    {
        $a = CuentaTesoreria::factory()->create();
        $b = CuentaTesoreria::factory()->create();

        $this->postJson(route('tesoreria.transferencia'), [
            'cuenta_origen_id' => $a->id,
            'cuenta_destino_id' => $b->id,
            'monto' => 0,
            'fecha' => '2026-07-18',
        ])->assertStatus(422);

        $this->assertSame(0, MovimientoTesoreria::count());
    }

    public function test_rechaza_cuenta_oculta(): void
    {
        $activa = CuentaTesoreria::factory()->create();
        $oculta = CuentaTesoreria::factory()->oculta()->create();

        $this->postJson(route('tesoreria.transferencia'), [
            'cuenta_origen_id' => $activa->id,
            'cuenta_destino_id' => $oculta->id,
            'monto' => 100,
            'fecha' => '2026-07-18',
        ])->assertStatus(422);
    }
}
