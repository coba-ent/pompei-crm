<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Cliente;
use App\Models\Presupuesto;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresupuestoEstadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas rutas están detrás del middleware `permiso:`, y el usuario que autentica
        // `Tests\TestCase` no trae ningún rol. Es el mismo `setUp` que ya usan
        // CompraVencidoTest y otros 140 archivos. No se centraliza en TestCase: varios tests
        // cuentan administradores o prueban la denegación, y adjuntarlo a todos rompe el
        // pivote `rol_usuario` y convierte esos asserts en falsos verdes. `syncWithoutDetaching`
        // y no `attach` porque algunos tests de estos mismos archivos ya lo adjuntan aparte.
        auth()->user()->roles()->syncWithoutDetaching(Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id);
    }

    private function crearPresupuesto(array $overrides = []): Presupuesto
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('presupuestos.store'), array_merge([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100]],
        ], $overrides))->assertOk();

        return Presupuesto::firstOrFail();
    }

    public function test_cambia_estado_a_enviado_y_aceptado(): void
    {
        $presupuesto = $this->crearPresupuesto();

        $this->patchJson(route('presupuestos.estado', $presupuesto), ['estado' => 'enviado'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('presupuestos', ['id' => $presupuesto->id, 'estado' => 'enviado']);

        $this->patchJson(route('presupuestos.estado', $presupuesto), ['estado' => 'aceptado'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('presupuestos', ['id' => $presupuesto->id, 'estado' => 'aceptado']);
    }

    public function test_vencido_se_deriva_por_fecha(): void
    {
        $presupuesto = $this->crearPresupuesto(['fecha_vencimiento' => '2000-01-01']);

        $this->assertTrue($presupuesto->fresh()->esta_vencido);

        $data = $this->getJson(route('presupuestos.data', ['estado' => 'vencido']));
        $data->assertOk();
        $this->assertSame(1, $data->json('recordsFiltered'));
    }

    public function test_rechaza_setear_convertido_manualmente(): void
    {
        $presupuesto = $this->crearPresupuesto();

        $this->patchJson(route('presupuestos.estado', $presupuesto), ['estado' => 'convertido'])
            ->assertStatus(409);

        $this->assertDatabaseHas('presupuestos', ['id' => $presupuesto->id, 'estado' => 'borrador']);
    }
}
