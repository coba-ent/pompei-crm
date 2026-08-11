<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ComprobanteFiscal;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 057 US1: editar una NC/ND existente (Venta). */
class NotaCreditoDebitoEditarTest extends TestCase
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

    /** T008 */
    public function test_editar_nota_sin_stock_actualiza_monto_y_ecuacion(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 1000,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();
        $this->assertSame(0.0, $venta->fresh()->aCobrar());

        $response = $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Corregido',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 1500,
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1500.0, (float) $nota->fresh()->monto);
        $this->assertSame(-500.0, $venta->fresh()->aCobrar());
    }

    /** Spec 062 (T020/T021): editar nota_interna persiste el valor; vacío no bloquea la edición. */
    public function test_editar_nota_actualiza_nota_interna(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 1000,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();
        $this->assertNull($nota->nota_interna);

        $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Original', 'nota_interna' => 'Comentario interno',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 1000,
        ])->assertOk();

        $this->assertSame('Comentario interno', $nota->fresh()->nota_interna);

        $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Original', 'nota_interna' => '',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 1000,
        ])->assertOk();
    }

    /** T009 */
    public function test_editar_nota_que_afecta_stock_revierte_y_reaplica(): void
    {
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = $this->crearVenta();
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 10,
            'precio_unitario' => 100, 'subtotal' => 1000, 'subtotal_con_iva' => 1000,
        ]);

        $stockAntes = $producto->stockTotal();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'debito', 'afecta_stock' => true, 'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 200,
        ])->assertCreated();

        $this->assertSame($stockAntes - 2.0, $producto->fresh()->stockTotal());

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'debito', 'afecta_stock' => true, 'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 500,
        ])->assertOk();

        $this->assertSame($stockAntes - 5.0, $producto->fresh()->stockTotal());
    }

    /** T010 */
    public function test_editar_nota_con_cae_aprobado_devuelve_409(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();
        ComprobanteFiscal::create([
            'comprobantable_type' => $nota->getMorphClass(), 'comprobantable_id' => $nota->id,
            'tipo_comprobante' => 'A', 'numero' => '0001-00000001', 'cae' => '12345678901234',
            'cae_vencimiento' => now()->addDays(10), 'estado' => 'aprobado',
        ]);

        $response = $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Intento de cambio',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 999,
        ]);

        $response->assertStatus(409);
        $this->assertSame(100.0, (float) $nota->fresh()->monto);
    }

    /** T011 */
    public function test_editar_no_choca_contra_pendiente_de_la_propia_nota(): void
    {
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = $this->crearVenta();
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 300,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        // La propia nota ya usó 3 de 5; editar a 5 (el máximo total) no debería chocar contra sí misma.
        $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 500,
        ])->assertOk();
    }

    /** T012 */
    public function test_editar_con_nro_comprobante_duplicado_falla_422(): void
    {
        $venta = $this->crearVenta();
        $venta->update(['tipo_comprobante' => 'A', 'nro_comprobante' => '0001-00000099']);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $response = $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
            'tipo_comprobante' => 'A', 'nro_comprobante' => '0001-00000099',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('nro_comprobante');
    }

    /** T012b */
    public function test_editar_intentando_cambiar_tipo_falla_422(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $response = $this->putJson(route('ventas.notas.update', [$venta, $nota]), [
            'tipo' => 'debito', 'afecta_stock' => false, 'descripcion' => 'Original',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->assertSame('credito', $nota->fresh()->tipo);
    }
}
