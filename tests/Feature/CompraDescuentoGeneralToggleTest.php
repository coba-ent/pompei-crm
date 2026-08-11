<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Spec 060 — toggle %/monto fijo para el Descuento General (módulo Compras, réplica del patrón de Ventas). */
class CompraDescuentoGeneralToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function payloadBase(Proveedor $proveedor, array $overrides = []): array
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        return array_merge([
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => 'A-0001-00000001',
            'fecha_emision' => now()->toDateString(),
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 10000, 'iva_pct' => '21'],
            ],
        ], $overrides);
    }

    /** Alta con descuento general en modo monto fijo persiste monto y deja pct en null. */
    public function test_alta_con_descuento_general_modo_monto_persiste_monto_y_limpia_pct(): void
    {
        $proveedor = Proveedor::factory()->create();

        $response = $this->postJson(route('compras.store'), $this->payloadBase($proveedor, [
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 500,
            'descuento_general_pct' => null,
        ]));

        $response->assertCreated();

        $compra = Compra::latest('id')->firstOrFail();
        $this->assertSame('monto', $compra->descuento_general_tipo);
        $this->assertEquals(500.00, (float) $compra->descuento_general_monto);
        $this->assertNull($compra->descuento_general_pct);
        // 500 / 10000 = 5% efectivo → descuento = 500, total = (10000-500)*1.21 = 11495
        $this->assertEqualsWithDelta(11495.00, (float) $compra->total, 0.01);
    }

    /** FR-007: monto fijo mayor al subtotal de ítems se rechaza con 422. */
    public function test_alta_con_descuento_general_monto_mayor_al_subtotal_es_rechazada(): void
    {
        $proveedor = Proveedor::factory()->create();

        $response = $this->postJson(route('compras.store'), $this->payloadBase($proveedor, [
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 10000, 'iva_pct' => '21'],
            ],
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 15000,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('descuento_general_monto');
        $this->assertDatabaseCount('compras', 0);
    }

    /** Reabrir para editar sin tocar el descuento general conserva modo y valor. */
    public function test_editar_sin_tocar_descuento_general_no_cambia_tipo_ni_valor(): void
    {
        $proveedor = Proveedor::factory()->create();

        $this->postJson(route('compras.store'), $this->payloadBase($proveedor, [
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 500,
        ]))->assertCreated();

        $compra = Compra::latest('id')->firstOrFail();

        $response = $this->putJson(route('compras.update', $compra), $this->payloadBase($proveedor, [
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 500,
            'nota_interna' => 'Nota actualizada',
        ]));

        $response->assertOk();
        $compra->refresh();
        $this->assertSame('monto', $compra->descuento_general_tipo);
        $this->assertEquals(500.00, (float) $compra->descuento_general_monto);
        $this->assertNull($compra->descuento_general_pct);
        $this->assertSame('Nota actualizada', $compra->nota_interna);
    }

    /** Alta en modo porcentaje (default) confirma que descuento_general_tipo queda en 'porcentaje'. */
    public function test_alta_con_descuento_general_modo_porcentaje_persiste_pct_y_tipo(): void
    {
        $proveedor = Proveedor::factory()->create();

        $response = $this->postJson(route('compras.store'), $this->payloadBase($proveedor, [
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 15,
        ]));

        $response->assertCreated();

        $compra = Compra::latest('id')->firstOrFail();
        $this->assertSame('porcentaje', $compra->descuento_general_tipo);
        $this->assertEquals(15.00, (float) $compra->descuento_general_pct);
        $this->assertNull($compra->descuento_general_monto);
    }
}
