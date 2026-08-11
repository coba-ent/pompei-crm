<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US4 — NC/ND: afecta stock vía StockService, y ajusta la barra de ecuación de la venta (A Cobrar). */
class NotaCreditoDebitoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_nota_de_credito_que_afecta_stock_genera_movimiento_de_stock(): void
    {
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $stockAntes = $producto->stockTotal();

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
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);

        $this->assertSame($stockAntes + 3.0, $producto->fresh()->stockTotal());
        $this->assertDatabaseHas('movimientos_stock', ['producto_id' => $producto->id, 'cantidad' => 3]);
    }

    /** Spec 062 (T019/T021): alta de NC/ND con nota_interna persiste el valor; vacío no bloquea el alta. */
    public function test_alta_de_nota_con_nota_interna_persiste_el_valor(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Ajuste', 'nota_interna' => 'Uso interno',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('notas_credito_debito', ['venta_id' => $venta->id, 'nota_interna' => 'Uso interno']);
    }

    public function test_alta_de_nota_sin_nota_interna_no_bloquea_el_alta(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Ajuste',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);
    }

    /**
     * Regresión: el alta descartaba silenciosamente tipo_comprobante/nro_comprobante tipeados
     * en el form de creación (el campo existe en la UI desde spec 059, pero store()/storeCompra()
     * nunca los leían de $datos — sólo aplicarEdicion() los persistía). Detectado en QA manual
     * sobre una Compra real (11/08/2026): el usuario cargaba N° de Comprobante al crear la nota
     * y la tabla lo mostraba en "-".
     */
    public function test_alta_de_nota_con_tipo_y_nro_comprobante_manuales_persiste_ambos(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Ajuste',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(), 'monto' => 100,
            'tipo_comprobante' => 'A', 'nro_comprobante' => '0002-88888888',
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('notas_credito_debito', [
            'venta_id' => $venta->id, 'tipo_comprobante' => 'A', 'nro_comprobante' => '0002-88888888',
        ]);
    }

    public function test_nc_resta_y_nd_suma_en_la_barra_de_ecuacion_de_la_venta(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $this->assertSame(1000.0, $venta->aCobrar());

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Devolución parcial',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
        ])->assertCreated();

        $this->assertSame(800.0, $venta->fresh()->aCobrar());

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'debito',
            'afecta_stock' => false,
            'descripcion' => 'Interés por mora',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 50,
        ])->assertCreated();

        $this->assertSame(850.0, $venta->fresh()->aCobrar());
    }

    /** T015 (US1): afecta_stock=false persiste mes_imputacion normalizado y no genera movimiento de stock. */
    public function test_nota_sin_afectar_stock_persiste_mes_imputacion_normalizado_y_sin_movimiento(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Ajuste',
            'mes_imputacion' => '2026-03-17',
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();
        $this->assertSame('2026-03-01', $nota->mes_imputacion->toDateString());
        $this->assertDatabaseCount('movimientos_stock', 0);
    }

    /** T016 (US1): sin mes_imputacion falla validación 422. */
    public function test_crear_nota_sin_mes_imputacion_falla_validacion(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Ajuste',
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('mes_imputacion');
    }

    /** T023 (US2): NC sobre Venta sube stock (signo ya cubierto por T???); ND sobre Venta lo baja. */
    public function test_nota_de_debito_que_afecta_stock_descuenta_stock(): void
    {
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $stockAntes = $producto->stockTotal();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'debito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [
                ['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 100],
            ],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
        ])->assertCreated();

        $this->assertSame($stockAntes - 2.0, $producto->fresh()->stockTotal());
    }

    /** T024 (US2): cantidad mayor a la pendiente de ajuste falla 422 con el máximo disponible en el mensaje. */
    public function test_cantidad_mayor_a_pendiente_falla_validacion(): void
    {
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 3,
            'precio_unitario' => 100, 'subtotal' => 300, 'subtotal_con_iva' => 300,
        ]);

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [
                ['producto_id' => $producto->id, 'cantidad' => 10, 'precio' => 100],
            ],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 1000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
        $this->assertStringContainsString('3', $response->json('errors')['items.0.cantidad'][0]);
    }

    /** T025 (US2): una segunda nota sobre el mismo comprobante/producto descuenta lo ya ajustado por la primera. */
    public function test_segunda_nota_descuenta_lo_ya_ajustado_por_la_primera(): void
    {
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 300,
        ])->assertCreated();

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 3, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 300,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
        $this->assertStringContainsString('2', $response->json('errors')['items.0.cantidad'][0]);
    }

    /** T026 (US2): afecta_stock=true sin deposito_id falla 422. */
    public function test_afecta_stock_sin_deposito_falla_validacion(): void
    {
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
        ])->assertStatus(422)->assertJsonValidationErrors('deposito_id');
    }

    /** T027 (US2): una nota soft-deleted no debe seguir consumiendo cupo del producto. */
    public function test_nota_eliminada_no_consume_cupo_pendiente(): void
    {
        $cliente = Cliente::factory()->create();
        $producto = Producto::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 500,
        ])->assertCreated();

        $venta->fresh()->notasCreditoDebito()->first()->delete();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 500,
        ])->assertCreated();
    }
}
