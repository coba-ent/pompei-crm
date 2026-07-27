<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompraAltaTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_crea_compra_con_total_correcto_y_pendiente(): void
    {
        $proveedor = Proveedor::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $response = $this->postJson(route('compras.store'), [
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [
                ['producto_id' => $prod->id, 'cantidad' => 2, 'precio' => 100, 'iva_pct' => 21],
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('compras', [
            'proveedor_id' => $proveedor->id,
            'total' => 242.00,
            'estado_pago' => 'pendiente',
        ]);

        $data = $this->getJson(route('compras.data'));
        $data->assertOk();
        $this->assertSame(1, $data->json('recordsTotal'));
    }

    public function test_rechaza_sin_proveedor(): void
    {
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('compras.store'), [
            'fecha_emision' => '2026-07-18',
            'lineas' => [['producto_id' => $prod->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('proveedor_id');
    }

    public function test_rechaza_sin_lineas(): void
    {
        $proveedor = Proveedor::factory()->create();

        $this->postJson(route('compras.store'), [
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('lineas');
    }

    public function test_rechaza_cantidad_no_positiva_y_precio_negativo(): void
    {
        $proveedor = Proveedor::factory()->create();
        $prod = Producto::factory()->create(['tipo' => 'servicio']);

        $this->postJson(route('compras.store'), [
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-07-18',
            'lineas' => [
                ['producto_id' => $prod->id, 'cantidad' => 0, 'precio' => -5],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['lineas.0.cantidad', 'lineas.0.precio']);
    }
}
