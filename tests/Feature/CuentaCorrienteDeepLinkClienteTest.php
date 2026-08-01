<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Cta Cte" en el menú de fila de Clientes navega a
 * informes.cuenta-corriente.index?cliente_id=X (deep-link al tab Movimientos
 * pre-filtrado por ese cliente).
 */
class CuentaCorrienteDeepLinkClienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El botón "Cta Cte" está gateado por @can('informes.ver') (mismo
        // esquema que el ítem del sidebar) — sin rol Admin queda oculto.
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_sin_cliente_id_abre_en_saldos_clientes_sin_preseleccion(): void
    {
        $resp = $this->get(route('informes.cuenta-corriente.index'))->assertOk();

        $resp->assertSee('id="tab-saldos-clientes-btn" data-bs-toggle="tab" data-bs-target="#tab-saldos-clientes" type="button" role="tab" aria-controls="tab-saldos-clientes" aria-selected="true"', false);
    }

    public function test_con_cliente_id_preselecciona_el_filtro_y_abre_en_movimientos(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'CRISTIAN 1156071555']);

        $resp = $this->get(route('informes.cuenta-corriente.index', ['cliente_id' => $cliente->id]))->assertOk();

        $resp->assertSee('id="tab-movimientos-btn" data-bs-toggle="tab" data-bs-target="#tab-movimientos" type="button" role="tab" aria-controls="tab-movimientos" aria-selected="true"', false);
        $resp->assertSee('<option value="'.$cliente->id.'" selected>CRISTIAN 1156071555</option>', false);
    }

    public function test_boton_cta_cte_aparece_en_el_menu_de_fila_de_clientes(): void
    {
        $cliente = Cliente::factory()->create();

        $resp = $this->getJson(route('clientes.data'));
        $resp->assertOk();

        $fila = collect($resp->json('data'))->firstWhere('id', $cliente->id);
        $this->assertStringContainsString(
            route('informes.cuenta-corriente.index', ['cliente_id' => $cliente->id]),
            $fila['acciones']
        );
    }
}
