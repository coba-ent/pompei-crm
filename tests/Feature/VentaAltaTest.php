<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Cliente;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentaAltaTest extends TestCase
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

    public function test_post_crea_venta_con_total_correcto_y_pendiente(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $response = $this->postJson(route('ventas.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [
                ['producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100, 'iva_pct' => 21],
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('ventas', [
            'cliente_id' => $cliente->id,
            'total' => 242.00,
            'estado_cobro' => 'pendiente',
        ]);

        $data = $this->getJson(route('ventas.data'));
        $data->assertOk();
        $this->assertSame(1, $data->json('recordsTotal'));
    }

    public function test_rechaza_sin_cliente(): void
    {
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('ventas.store'), [
            'fecha_emision' => '2026-07-18',
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('cliente_id');
    }

    public function test_rechaza_sin_lineas(): void
    {
        $cliente = Cliente::factory()->create();

        $this->postJson(route('ventas.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas');
    }

    public function test_rechaza_cantidad_no_positiva_y_precio_negativo(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('ventas.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [
                ['producto_id' => $prod->id, 'cantidad' => 0, 'precio' => -5],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['lineas.0.cantidad', 'lineas.0.precio']);
    }
}
