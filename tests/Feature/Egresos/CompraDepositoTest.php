<?php

namespace Tests\Feature\Egresos;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Spec 049 (US1): la Compra usa el Depósito elegido en el formulario, no siempre el "por defecto". */
class CompraDepositoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearCompra(Producto $producto, int $depositoId, float $cantidad = 5): Compra
    {
        $proveedor = Proveedor::factory()->create();

        $respuesta = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $depositoId,
            'nro_comprobante' => '0001-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-08-17',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => 100,
            ]],
        ]);

        $respuesta->assertCreated();

        return Compra::findOrFail($respuesta->json('compra.id'));
    }

    public function test_alta_de_compra_suma_stock_del_deposito_elegido_no_del_por_defecto(): void
    {
        $depositoA = Deposito::create(['nombre' => 'A - Por Defecto', 'activo' => true]);
        $depositoB = Deposito::create(['nombre' => 'B - Elegido', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $compra = $this->crearCompra($producto, $depositoB->id, 5);

        $this->assertSame($depositoB->id, $compra->deposito_id);
        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoB->id, 'cantidad' => 5]);
        $this->assertDatabaseMissing('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoA->id]);
    }

    public function test_editar_compra_cambiando_deposito_reintegra_el_anterior_y_suma_el_nuevo(): void
    {
        $depositoA = Deposito::create(['nombre' => 'A', 'activo' => true]);
        $depositoB = Deposito::create(['nombre' => 'B', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $compra = $this->crearCompra($producto, $depositoB->id, 5);

        $respuesta = $this->putJson(route('compras.update', $compra), [
            'proveedor_id' => $compra->proveedor_id,
            'deposito_id' => $depositoA->id,
            'nro_comprobante' => $compra->nro_comprobante,
            'fecha_emision' => '2026-08-17',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => 5,
                'precio_unitario' => 100,
            ]],
        ]);

        $respuesta->assertOk();

        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoB->id, 'cantidad' => 0]);
        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoA->id, 'cantidad' => 5]);
    }

    public function test_eliminar_compra_reintegra_sobre_el_deposito_persistido_no_sobre_el_por_defecto_vigente(): void
    {
        $depositoA = Deposito::create(['nombre' => 'A - Por Defecto', 'activo' => true]);
        $depositoB = Deposito::create(['nombre' => 'B', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $compra = $this->crearCompra($producto, $depositoB->id, 5);

        $this->deleteJson(route('compras.destroy', $compra))->assertOk();

        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoB->id, 'cantidad' => 0]);
        $this->assertDatabaseMissing('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoA->id]);
    }

    public function test_guardar_compra_con_deposito_inactivo_falla_validacion(): void
    {
        $depositoInactivo = Deposito::create(['nombre' => 'Inactivo', 'activo' => false]);
        $proveedor = Proveedor::factory()->create();

        $respuesta = $this->postJson(route('compras.store'), [
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $depositoInactivo->id,
            'nro_comprobante' => '0001-00000001',
            'fecha_emision' => '2026-08-17',
            'items' => [[
                'producto_id' => null,
                'descripcion' => 'Insumo libre',
                'cantidad' => 1,
                'precio_unitario' => 100,
            ]],
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['deposito_id']);
    }

    public function test_sin_depositos_activos_crear_compra_no_ofrece_ninguna_opcion_valida(): void
    {
        $response = $this->get(route('compras.create'));

        $response->assertOk();
        preg_match('/<select id="f-deposito"[^>]*>(.*?)<\/select>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString('<option', $matches[1]);
    }
}
