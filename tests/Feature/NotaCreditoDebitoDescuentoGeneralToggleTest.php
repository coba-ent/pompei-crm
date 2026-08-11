<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 060 (US3): toggle %/monto fijo del Descuento General en NC/ND. */
class NotaCreditoDebitoDescuentoGeneralToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVenta(): Venta
    {
        $cliente = Cliente::factory()->create();

        return Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
    }

    public function test_alta_en_modo_monto_persiste_tipo_monto_y_deja_pct_null(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Devolución parcial',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 50,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $this->assertSame('monto', $nota->descuento_general_tipo);
        $this->assertSame(50.0, (float) $nota->descuento_general_monto);
        $this->assertNull($nota->descuento_general_pct);
    }

    public function test_alta_en_modo_porcentaje_persiste_tipo_porcentaje_y_deja_monto_null(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Devolución parcial',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 10,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $this->assertSame('porcentaje', $nota->descuento_general_tipo);
        $this->assertSame(10.0, (float) $nota->descuento_general_pct);
        $this->assertNull($nota->descuento_general_monto);
    }

    public function test_editar_sin_tocar_descuento_general_no_cambia_los_3_campos(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 30,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        // El JS siempre reenvía los 3 campos con el mismo modo/valor tal como cargó el form
        // (mismo criterio que Ventas — la key nunca se omite), así que editar "sin tocar" el
        // descuento general implica reenviar el mismo tipo/monto.
        $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Corregido',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 30,
        ])->assertOk();

        $nota->refresh();

        $this->assertSame('monto', $nota->descuento_general_tipo);
        $this->assertSame(30.0, (float) $nota->descuento_general_monto);
        $this->assertNull($nota->descuento_general_pct);
    }

    /** FR-007: monto fijo mayor al subtotal bruto de items falla 422. */
    public function test_monto_fijo_mayor_al_subtotal_de_items_falla_422(): void
    {
        $producto = \App\Models\Producto::factory()->create();
        $deposito = \App\Models\Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = $this->crearVenta();
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [
                ['producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 100],
            ],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 300,
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 999,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('descuento_general_monto');
    }
}
