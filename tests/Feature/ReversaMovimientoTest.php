<?php

namespace Tests\Feature;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReversaMovimientoTest extends TestCase
{
    use RefreshDatabase;

    private Tesoreria $tesoreria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tesoreria = new Tesoreria();
    }

    public function test_reversa_devuelve_saldos_al_estado_previo(): void
    {
        $a = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);
        $b = CuentaTesoreria::factory()->create(['saldo_inicial' => 200]);

        $saldoAAntes = $this->tesoreria->saldoDe($a);
        $saldoBAntes = $this->tesoreria->saldoDe($b);

        $mov = $this->tesoreria->transferir($a, $b, 300, '2026-07-18');

        $this->postJson(route('tesoreria.reversar', $mov))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame($saldoAAntes, $this->tesoreria->saldoDe($a->fresh()));
        $this->assertSame($saldoBAntes, $this->tesoreria->saldoDe($b->fresh()));

        // Ambos registros quedan en el historial.
        $this->assertSame(2, MovimientoTesoreria::count());
    }

    public function test_no_reversar_dos_veces(): void
    {
        $a = CuentaTesoreria::factory()->create(['saldo_inicial' => 1000]);
        $b = CuentaTesoreria::factory()->create();

        $mov = $this->tesoreria->transferir($a, $b, 100, '2026-07-18');

        $this->postJson(route('tesoreria.reversar', $mov))->assertOk();
        $this->postJson(route('tesoreria.reversar', $mov))
            ->assertStatus(409)->assertJsonPath('ok', false);

        // Sólo una reversa (original + una contramov).
        $this->assertSame(2, MovimientoTesoreria::count());
    }

    public function test_reversa_de_ajuste(): void
    {
        $a = CuentaTesoreria::factory()->create(['saldo_inicial' => 500]);

        $ajuste = $this->tesoreria->ajustar($a, 'entrada', 100, '2026-07-18');
        $this->assertSame(600.0, $this->tesoreria->saldoDe($a->fresh()));

        $this->postJson(route('tesoreria.reversar', $ajuste))->assertOk();
        $this->assertSame(500.0, $this->tesoreria->saldoDe($a->fresh()));
    }
}
