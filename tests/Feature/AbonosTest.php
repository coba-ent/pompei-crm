<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbonosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['negocio.abonos_activo' => true]);
        Categoria::create(['tipo' => 'ingreso', 'nombre' => 'Abono', 'es_sistema' => false, 'activo' => true]);
    }

    public function test_post_crea_abono_valido(): void
    {
        $cliente = Cliente::factory()->create();

        $response = $this->postJson(route('abonos.store'), [
            'cliente_id' => $cliente->id,
            'monto' => 30000,
            'frecuencia' => 'mensual',
            'dia_cobro' => 10,
        ]);

        $response->assertStatus(201)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('abonos', ['cliente_id' => $cliente->id, 'monto' => 30000.00, 'activo' => true]);
    }

    public function test_rechaza_sin_cliente(): void
    {
        $this->postJson(route('abonos.store'), [
            'monto' => 30000, 'frecuencia' => 'mensual', 'dia_cobro' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('cliente_id');
    }

    public function test_rechaza_mensual_sin_dia_cobro(): void
    {
        $cliente = Cliente::factory()->create();

        $this->postJson(route('abonos.store'), [
            'cliente_id' => $cliente->id, 'monto' => 30000, 'frecuencia' => 'mensual',
        ])->assertStatus(422)->assertJsonValidationErrors('dia_cobro');
    }

    public function test_generar_ahora_es_idempotente(): void
    {
        $cliente = Cliente::factory()->create();
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $store = $this->postJson(route('abonos.store'), [
            'cliente_id' => $cliente->id,
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 5000,
            'frecuencia' => 'mensual',
            'dia_cobro' => 10,
        ]);
        $abonoId = $store->json('abono.id');

        $this->postJson(route('abonos.generar', $abonoId))
            ->assertStatus(201)
            ->assertJson(['ok' => true]);

        $this->assertSame(5000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
        $this->assertDatabaseCount('otros_ingresos', 1);

        $this->postJson(route('abonos.generar', $abonoId))
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicado' => true]);

        $this->assertDatabaseCount('otros_ingresos', 1);
        $this->assertSame(5000.0, app(Tesoreria::class)->saldoDe($cuenta->fresh()));
    }

    public function test_baja_abono_es_logica_y_conserva_historial(): void
    {
        $cliente = Cliente::factory()->create();

        $store = $this->postJson(route('abonos.store'), [
            'cliente_id' => $cliente->id, 'monto' => 5000, 'frecuencia' => 'mensual', 'dia_cobro' => 10,
        ]);
        $abonoId = $store->json('abono.id');

        $this->deleteJson(route('abonos.destroy', $abonoId))->assertOk();

        $this->assertDatabaseHas('abonos', ['id' => $abonoId, 'activo' => false]);
    }

    public function test_rutas_bloqueadas_si_la_funcion_avanzada_esta_desactivada(): void
    {
        config(['negocio.abonos_activo' => false]);

        $this->getJson(route('abonos.index'))->assertStatus(403);
    }
}
