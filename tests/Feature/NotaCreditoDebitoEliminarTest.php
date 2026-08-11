<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ComprobanteFiscal;
use App\Models\Deposito;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 057 US2: eliminar (soft delete) una NC/ND existente (Venta). */
class NotaCreditoDebitoEliminarTest extends TestCase
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

    /** T021 */
    public function test_eliminar_nota_que_afecta_stock_revierte_stock_exacto(): void
    {
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = $this->crearVenta();
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $stockAntes = $producto->stockTotal();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 300,
        ])->assertCreated();

        $this->assertSame($stockAntes + 3.0, $producto->fresh()->stockTotal());

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $this->deleteJson(route('ventas.notas.destroy', [$venta, $nota]))->assertOk()->assertJsonPath('ok', true);

        $this->assertSame($stockAntes, $producto->fresh()->stockTotal());
    }

    /** T022 */
    public function test_eliminar_nota_con_cae_aprobado_devuelve_409(): void
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

        $this->deleteJson(route('ventas.notas.destroy', [$venta, $nota]))->assertStatus(409);
        $this->assertNotNull($nota->fresh());
    }

    /** T023 */
    public function test_eliminar_nota_referenciada_por_otra_devuelve_409(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'A',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ])->assertCreated();
        $notaA = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $notaB = NotaCreditoDebito::create([
            'venta_id' => null, 'compra_id' => null, 'nota_ajustada_id' => $notaA->id,
            'tipo' => 'credito', 'afecta_stock' => false, 'mes_imputacion' => now(), 'fecha_emision' => now(),
            'monto' => 50, 'tipo_comprobante' => 'A', 'descripcion' => 'Ajusta a A',
        ]);

        $response = $this->deleteJson(route('ventas.notas.destroy', [$venta, $notaA]));

        $response->assertStatus(409);
        $this->assertStringContainsString((string) $notaB->id, $response->json('mensaje'));
        $this->assertNotNull($notaA->fresh());
    }

    /** T024 */
    public function test_eliminacion_es_soft_delete(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'A',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ])->assertCreated();
        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $this->deleteJson(route('ventas.notas.destroy', [$venta, $nota]))->assertOk();

        $this->assertSoftDeleted('notas_credito_debito', ['id' => $nota->id]);
    }
}
