<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Presupuesto;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US1 — alta/edición de Presupuesto, estados, "Vencido" derivado, idempotencia (SC-007). */
class PresupuestoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function payload(Cliente $cliente, array $overrides = []): array
    {
        return array_merge([
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'items' => [
                ['descripcion' => 'Camisa', 'cantidad' => 1, 'precio_unitario' => 100, 'iva_pct' => '21'],
            ],
        ], $overrides);
    }

    public function test_alta_crea_presupuesto_pendiente_con_totales_correctos(): void
    {
        $cliente = Cliente::factory()->create();

        $response = $this->postJson(route('presupuestos.store'), $this->payload($cliente));

        $response->assertCreated()->assertJsonPath('ok', true);
        $presupuesto = Presupuesto::firstOrFail();
        $this->assertSame('pendiente', $presupuesto->estado);
        $this->assertSame(121.0, (float) $presupuesto->total);
        $this->assertCount(1, $presupuesto->items);
    }

    public function test_doble_submit_con_mismo_token_no_duplica(): void
    {
        $cliente = Cliente::factory()->create();
        $payload = $this->payload($cliente);

        $this->postJson(route('presupuestos.store'), $payload)->assertCreated();
        $this->postJson(route('presupuestos.store'), $payload)->assertCreated();

        $this->assertSame(1, Presupuesto::count());
    }

    public function test_cambio_de_estado_directo(): void
    {
        $presupuesto = Presupuesto::factory()->create();

        $this->patchJson(route('presupuestos.estado', $presupuesto), ['estado' => 'aceptado'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('aceptado', $presupuesto->fresh()->estado);
    }

    public function test_vencido_es_derivado_de_fecha_validez_pasada_y_pendiente(): void
    {
        $presupuesto = Presupuesto::factory()->create([
            'estado' => 'pendiente',
            'fecha_validez' => now()->subDay(),
        ]);

        $this->assertTrue($presupuesto->vencido());
        $this->assertSame('vencido', $presupuesto->estado_visual);

        $presupuesto->update(['estado' => 'aceptado']);
        $this->assertFalse($presupuesto->fresh()->vencido());
    }

    public function test_edicion_actualiza_items_y_totales(): void
    {
        $cliente = Cliente::factory()->create();
        $this->postJson(route('presupuestos.store'), $this->payload($cliente))->assertCreated();
        $presupuesto = Presupuesto::firstOrFail();

        $this->putJson(route('presupuestos.update', $presupuesto), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'items' => [
                ['descripcion' => 'Camisa', 'cantidad' => 2, 'precio_unitario' => 100, 'iva_pct' => '21'],
            ],
        ])->assertOk()->assertJsonPath('ok', true);

        $presupuesto->refresh();
        $this->assertSame(242.0, (float) $presupuesto->total);
        $this->assertCount(1, $presupuesto->items);
    }
}
