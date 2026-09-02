<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Presupuesto;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adónde vuelve el formulario después de guardar.
 *
 * El JS hace `window.location.href = resp.redirect || rutas.index`, así que una respuesta sin
 * `redirect` manda al listado en silencio. El alta ya devolvía la ficha del presupuesto, pero
 * `update()` no, y editar terminaba en el listado: había que buscar de nuevo el presupuesto
 * para ver cómo había quedado.
 */
class PresupuestoRedirectGuardadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );
    }

    private function payload(Cliente $cliente, array $overrides = []): array
    {
        return array_merge([
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 10000, 'iva_pct' => '21'],
            ],
        ], $overrides);
    }

    public function test_editar_devuelve_la_ficha_del_presupuesto_y_no_el_listado(): void
    {
        $cliente = Cliente::factory()->create();
        $presupuesto = Presupuesto::factory()->create(['cliente_id' => $cliente->id]);

        $resp = $this->putJson(route('presupuestos.update', $presupuesto), $this->payload($cliente, [
            'items' => [
                ['descripcion' => 'Producto editado', 'cantidad' => 2, 'precio_unitario' => 5000, 'iva_pct' => '21'],
            ],
        ]));

        $resp->assertOk();
        $resp->assertJsonPath('redirect', route('presupuestos.show', $presupuesto));
        $this->assertNotSame(route('presupuestos.index'), $resp->json('redirect'));
    }

    public function test_el_alta_tambien_devuelve_la_ficha(): void
    {
        $cliente = Cliente::factory()->create();

        $resp = $this->postJson(route('presupuestos.store'), $this->payload($cliente));

        $resp->assertCreated();
        $nuevo = Presupuesto::latest('id')->first();
        $resp->assertJsonPath('redirect', route('presupuestos.show', $nuevo));
    }
}
