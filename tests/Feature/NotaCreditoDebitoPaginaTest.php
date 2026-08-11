<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 059 — página completa de NC/ND (Crear/Editar), corrección estructural sobre spec 057. */
class NotaCreditoDebitoPaginaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    /** T006: GET ventas/{venta}/notas/nueva devuelve 200 y renderiza el formulario. */
    public function test_pagina_crear_nota_de_venta_devuelve_200(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $response = $this->get(route('ventas.notas.create', $venta));

        $response->assertOk();
        $response->assertViewIs('notas-credito-debito.form');
        $response->assertViewHas('venta', fn ($v) => $v->is($venta));
        $response->assertViewHas('compra', null);
        $response->assertViewHas('notaCreditoDebito', null);
    }

    /** T007: la query string del paso 1 precarga los controles equivalentes de la página completa. */
    public function test_pagina_crear_nota_precarga_desde_query_string(): void
    {
        $cliente = Cliente::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $response = $this->get(route('ventas.notas.create', $venta).'?tipo=debito&afecta_stock=1&deposito_id='.$deposito->id.'&mes_imputacion=2026-08');

        $response->assertOk();
        $response->assertSee('window.NotaFormData', false);
        // queryString se vuelca como objeto JS literal (claves sin comillas, sólo los
        // valores pasan por @json()) — a diferencia de notaCreditoDebito/items más abajo,
        // que sí son @json() del array completo con claves entre comillas.
        $response->assertSee('tipo: "debito"', false);
        $response->assertSee('afectaStock: "1"', false);
        $response->assertSee('mesImputacion: "2026-08"', false);
    }

    /** T014: GET ventas/{venta}/notas/{nota}/editar devuelve 200 con los datos de la nota precargados. */
    public function test_pagina_editar_nota_de_venta_precarga_datos_de_la_nota(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Ajuste',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 150,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $response = $this->get(route('ventas.notas.edit', [$venta, $nota]));

        $response->assertOk();
        $response->assertViewIs('notas-credito-debito.form');
        $response->assertViewHas('notaCreditoDebito', fn ($n) => $n->is($nota));
        $response->assertSee('"id":'.$nota->id, false);
        $response->assertSee('"tipo":"credito"', false);
    }

    /** Simetría (FR-011): las mismas rutas existen para Compras. */
    public function test_pagina_crear_y_editar_nota_de_compra_devuelve_200(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $compra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'deposito_id' => $deposito->id, 'total' => 1000]);

        $this->get(route('compras.notas.create', $compra))->assertOk()->assertViewIs('notas-credito-debito.form');

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'debito',
            'afecta_stock' => false,
            'descripcion' => 'Interés',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 80,
        ])->assertCreated();

        $nota = $compra->fresh()->notasCreditoDebito()->firstOrFail();

        $this->get(route('compras.notas.edit', [$compra, $nota]))
            ->assertOk()
            ->assertViewIs('notas-credito-debito.form')
            ->assertViewHas('notaCreditoDebito', fn ($n) => $n->is($nota));
    }

    /** T006/T007 con ítems (afecta_stock=true): la página muestra el selector de producto vía el bloque de ítems. */
    public function test_pagina_editar_nota_con_stock_precarga_items_con_producto(): void
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

        $nota = $venta->fresh()->notasCreditoDebito()->firstOrFail();

        $response = $this->get(route('ventas.notas.edit', [$venta, $nota]));

        $response->assertOk();
        $response->assertSee('"producto_id":'.$producto->id, false);
    }

    /**
     * Regresión: una nota sin stock (afecta_stock=false) no persistía el ítem (precio/IVA/desc.)
     * en absoluto — sólo el monto agregado y la descripción libre. Al editar, el form no tenía de
     * dónde reconstruir el IVA seleccionado (quedaba en "Elegir") ni el precio real de la línea
     * (caía al monto total con IVA incluido). Detectado en QA manual (11/08/2026).
     */
    public function test_pagina_editar_nota_sin_stock_precarga_precio_e_iva_del_item(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $compra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'deposito_id' => $deposito->id, 'total' => 1000]);

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Item con IVA 21',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 1210,
            'tipo_comprobante' => 'A',
            'nro_comprobante' => '0003-11112222',
            'items' => [
                ['producto_id' => null, 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '21', 'descuento_pct' => 0],
            ],
        ])->assertCreated();

        $nota = $compra->fresh()->notasCreditoDebito()->firstOrFail();
        $this->assertSame(1, $nota->items()->count());
        $item = $nota->items()->first();
        $this->assertSame(1000.0, (float) $item->precio);
        $this->assertSame(21.0, (float) $item->iva_pct);

        $response = $this->get(route('compras.notas.edit', [$compra, $nota]));

        $response->assertOk();
        $response->assertSee('"precio":1000', false);
        $response->assertSee('"iva_pct":21', false);
    }
}
