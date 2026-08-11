<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Spec 060 — toggle %/monto fijo para el Descuento General (US1/US2, módulo de referencia: Ventas). */
class VentaDescuentoGeneralToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function payloadBase(Cliente $cliente, array $overrides = []): array
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        return array_merge([
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 10000, 'iva_pct' => '21'],
            ],
        ], $overrides);
    }

    /** US1: alta con descuento general en modo monto fijo persiste monto y deja pct en null. */
    public function test_alta_con_descuento_general_modo_monto_persiste_monto_y_limpia_pct(): void
    {
        $cliente = Cliente::factory()->create();

        $response = $this->postJson(route('ventas.store'), $this->payloadBase($cliente, [
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 500,
            'descuento_general_pct' => null,
        ]));

        $response->assertCreated();

        $venta = Venta::latest('id')->firstOrFail();
        $this->assertSame('monto', $venta->descuento_general_tipo);
        $this->assertEquals(500.00, (float) $venta->descuento_general_monto);
        $this->assertNull($venta->descuento_general_pct);
        // 500 / 10000 = 5% efectivo → descuento = 500, total = (10000-500)*1.21 = 11495
        $this->assertEqualsWithDelta(11495.00, (float) $venta->total, 0.01);
    }

    /** FR-007: monto fijo mayor al subtotal de ítems se rechaza con 422. */
    public function test_alta_con_descuento_general_monto_mayor_al_subtotal_es_rechazada(): void
    {
        $cliente = Cliente::factory()->create();

        $response = $this->postJson(route('ventas.store'), $this->payloadBase($cliente, [
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 10000, 'iva_pct' => '21'],
            ],
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 15000,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('descuento_general_monto');
        $this->assertDatabaseCount('ventas', 0);
    }

    /** US2: reabrir para editar sin tocar el descuento general conserva modo y valor. */
    public function test_editar_sin_tocar_descuento_general_no_cambia_tipo_ni_valor(): void
    {
        $cliente = Cliente::factory()->create();

        $this->postJson(route('ventas.store'), $this->payloadBase($cliente, [
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 500,
        ]))->assertCreated();

        $venta = Venta::latest('id')->firstOrFail();

        $response = $this->putJson(route('ventas.update', $venta), $this->payloadBase($cliente, [
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 500,
            'nota_interna' => 'Nota actualizada',
        ]));

        $response->assertOk();
        $venta->refresh();
        $this->assertSame('monto', $venta->descuento_general_tipo);
        $this->assertEquals(500.00, (float) $venta->descuento_general_monto);
        $this->assertNull($venta->descuento_general_pct);
        $this->assertSame('Nota actualizada', $venta->nota_interna);
    }

    /** Alta en modo porcentaje (default) confirma que descuento_general_tipo queda en 'porcentaje'. */
    public function test_alta_con_descuento_general_modo_porcentaje_persiste_pct_y_tipo(): void
    {
        $cliente = Cliente::factory()->create();

        $response = $this->postJson(route('ventas.store'), $this->payloadBase($cliente, [
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 15,
        ]));

        $response->assertCreated();

        $venta = Venta::latest('id')->firstOrFail();
        $this->assertSame('porcentaje', $venta->descuento_general_tipo);
        $this->assertEquals(15.00, (float) $venta->descuento_general_pct);
        $this->assertNull($venta->descuento_general_monto);
    }
}
