<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresupuestoAltaTest extends TestCase
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

    public function test_post_crea_presupuesto_con_total_correcto_y_borrador(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $response = $this->postJson(route('presupuestos.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [
                ['producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100, 'iva_pct' => 21],
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('presupuestos', [
            'cliente_id' => $cliente->id,
            'total' => 242.00,
            'estado' => 'borrador',
        ]);

        $data = $this->getJson(route('presupuestos.data'));
        $data->assertOk();
        $this->assertSame(1, $data->json('recordsTotal'));
    }

    public function test_no_genera_ningun_movimiento_de_stock(): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $prod = Producto::factory()->create(['tipo' => 'producto']);
        Stock::create(['producto_id' => $prod->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $this->postJson(route('presupuestos.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'deposito_id' => $deposito->id,
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 3, 'precio' => 100]],
        ])->assertOk();

        $this->assertDatabaseCount('movimientos_stock', 0);
        $this->assertEqualsWithDelta(10.0, (float) Stock::first()->cantidad, 0.001);
    }

    public function test_rechaza_sin_cliente(): void
    {
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('presupuestos.store'), [
            'fecha_emision' => '2026-07-18',
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('cliente_id');
    }

    public function test_rechaza_sin_lineas(): void
    {
        $cliente = Cliente::factory()->create();

        $this->postJson(route('presupuestos.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas');
    }

    public function test_rechaza_cantidad_no_positiva_y_precio_negativo(): void
    {
        $cliente = Cliente::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('presupuestos.store'), [
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [
                ['producto_id' => $prod->id, 'cantidad' => 0, 'precio' => -5],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['lineas.0.cantidad', 'lineas.0.precio']);
    }
}
