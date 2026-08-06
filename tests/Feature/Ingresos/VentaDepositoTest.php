<?php

namespace Tests\Feature\Ingresos;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Spec 049 (US1): la Venta usa el Depósito elegido en el formulario, no siempre el "por defecto". */
class VentaDepositoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVenta(Producto $producto, int $depositoId, float $cantidad = 3): Venta
    {
        $cliente = Cliente::factory()->create();

        $respuesta = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $depositoId,
            'fecha_emision' => '2026-08-17',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => 100,
                'iva_pct' => $producto->iva_venta_pct,
            ]],
        ]);

        $respuesta->assertCreated();

        return Venta::findOrFail($respuesta->json('venta.id'));
    }

    public function test_alta_de_venta_descuenta_stock_del_deposito_elegido_no_del_por_defecto(): void
    {
        $depositoA = Deposito::create(['nombre' => 'A - Por Defecto', 'activo' => true]);
        $depositoB = Deposito::create(['nombre' => 'B - Elegido', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $venta = $this->crearVenta($producto, $depositoB->id, 3);

        $this->assertSame($depositoB->id, $venta->deposito_id);
        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoB->id, 'cantidad' => -3]);
        $this->assertDatabaseMissing('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoA->id]);
    }

    public function test_editar_venta_cambiando_deposito_reintegra_el_anterior_y_descuenta_el_nuevo(): void
    {
        $depositoA = Deposito::create(['nombre' => 'A', 'activo' => true]);
        $depositoB = Deposito::create(['nombre' => 'B', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $venta = $this->crearVenta($producto, $depositoB->id, 3);

        $respuesta = $this->putJson(route('ventas.update', $venta), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $venta->cliente_id,
            'deposito_id' => $depositoA->id,
            'fecha_emision' => '2026-08-17',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => 3,
                'precio_unitario' => 100,
                'iva_pct' => $producto->iva_venta_pct,
            ]],
        ]);

        $respuesta->assertOk();

        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoB->id, 'cantidad' => 0]);
        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoA->id, 'cantidad' => -3]);
    }

    public function test_eliminar_venta_reintegra_sobre_el_deposito_persistido_no_sobre_el_por_defecto_vigente(): void
    {
        $depositoA = Deposito::create(['nombre' => 'A - Por Defecto', 'activo' => true]);
        $depositoB = Deposito::create(['nombre' => 'B', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $venta = $this->crearVenta($producto, $depositoB->id, 3);

        $this->deleteJson(route('ventas.destroy', $venta))->assertOk();

        $this->assertDatabaseHas('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoB->id, 'cantidad' => 0]);
        $this->assertDatabaseMissing('stocks', ['producto_id' => $producto->id, 'deposito_id' => $depositoA->id]);
    }

    public function test_guardar_venta_con_deposito_inactivo_falla_validacion(): void
    {
        $depositoInactivo = Deposito::create(['nombre' => 'Inactivo', 'activo' => false]);
        $cliente = Cliente::factory()->create();

        $respuesta = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $depositoInactivo->id,
            'fecha_emision' => '2026-08-17',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => null,
                'descripcion' => 'Ítem libre',
                'cantidad' => 1,
                'precio_unitario' => 100,
            ]],
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['deposito_id']);
    }

    public function test_sin_depositos_activos_crear_venta_no_ofrece_ninguna_opcion_valida(): void
    {
        $response = $this->get(route('ventas.create'));

        $response->assertOk();
        $response->assertSee('id="f-deposito"', false);
        // Sin depósitos activos, el select de Depósito se renderiza vacío (sin <option>).
        preg_match('/<select id="f-deposito"[^>]*>(.*?)<\/select>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString('<option', $matches[1]);
    }
}
