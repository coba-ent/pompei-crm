<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\AjustesPendientesNotaCreditoDebito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 096 — Cada línea del comprobante es un ajuste independiente en la NC/ND.
 *
 * Bug real, verificado en producción: `itemsDisponibles()` agrupaba las líneas del comprobante por
 * `producto_id`, así que cuando el mismo producto aparecía en varias líneas (venta 24854: 3 líneas
 * a $13.000, $25.000 con 10% bonif. y $50.000 con 15% bonif., total real $94.380) las fundía en una
 * sola, quedándose con el precio/bonif. de la primera línea pero sumando la cantidad de todas — la
 * NC proponía $47.190, la mitad del real. Estos tests fijan que cada línea se trata por separado, y
 * que el fallback agregado (FR-006) preserva el comportamiento de las NC/ND ya creadas.
 */
class NotaCreditoDebitoLineasIndependientesTest extends TestCase
{
    use RefreshDatabase;

    private AjustesPendientesNotaCreditoDebito $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        $this->servicio = app(AjustesPendientesNotaCreditoDebito::class);
    }

    /** Réplica exacta de la venta 24854 verificada en producción. */
    private function ventaConProductoRepetido(): array
    {
        $venta = Venta::factory()->create([
            'cliente_id' => Cliente::factory(),
            'total' => 94380,
        ]);
        $producto = Producto::factory()->create();

        $linea1 = $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 1,
            'precio_unitario' => 13000, 'descuento_pct' => null, 'iva_pct' => '21',
            'subtotal' => 13000, 'subtotal_con_iva' => 15730,
        ]);
        $linea2 = $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 1,
            'precio_unitario' => 25000, 'descuento_pct' => 10, 'iva_pct' => '21',
            'subtotal' => 22500, 'subtotal_con_iva' => 27225,
        ]);
        $linea3 = $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 1,
            'precio_unitario' => 50000, 'descuento_pct' => 15, 'iva_pct' => '21',
            'subtotal' => 42500, 'subtotal_con_iva' => 51425,
        ]);

        return [$venta->fresh(), $producto, $linea1, $linea2, $linea3];
    }

    // -----------------------------------------------------------------
    // US1 — Precarga por línea, no fundida
    // -----------------------------------------------------------------

    /** T009 (FR-001, FR-002): 3 líneas del mismo producto precargan como 3 filas independientes. */
    public function test_producto_repetido_precarga_como_lineas_independientes(): void
    {
        [$venta] = $this->ventaConProductoRepetido();

        $items = $this->servicio->itemsDisponibles($venta);

        $this->assertCount(3, $items, 'Deben aparecer 3 líneas, no 1 fundida.');

        $precios = collect($items)->pluck('precio')->sort()->values()->all();
        $this->assertSame([13000.0, 25000.0, 50000.0], $precios);

        $porPrecio = collect($items)->keyBy('precio');
        $this->assertSame(0.0, $porPrecio[13000.0]['descuento_pct']);
        $this->assertSame(10.0, $porPrecio[25000.0]['descuento_pct']);
        $this->assertSame(15.0, $porPrecio[50000.0]['descuento_pct']);

        // Cada línea con pendiente=1 (su propia cantidad), no 3 (la suma fundida).
        foreach ($items as $item) {
            $this->assertSame(1.0, $item['pendiente']);
        }

        // item_origen_id distinto por línea.
        $this->assertCount(3, collect($items)->pluck('item_origen_id')->unique());
    }

    /** T010 (FR-005, SC-001): el total propuesto coincide con el real de la venta 24854 ($94.380). */
    public function test_total_propuesto_coincide_con_el_real_sobre_producto_repetido(): void
    {
        [$venta] = $this->ventaConProductoRepetido();

        $items = $this->servicio->itemsDisponibles($venta);

        $total = 0.0;
        foreach ($items as $item) {
            $bruto = $item['pendiente'] * $item['precio'];
            $subtotal = $bruto - ($bruto * $item['descuento_pct'] / 100);
            $total += round($subtotal * 1.21, 2);
        }

        $this->assertEqualsWithDelta(94380.0, $total, 0.01);

        // Y el bug que esto arregla: fundido en 1 sola línea daría la mitad.
        $this->assertNotEqualsWithDelta(47190.0, $total, 1.0);
    }

    /** T011 (FR-008): sin producto repetido, comportamiento idéntico al actual — 1 fila por producto. */
    public function test_sin_producto_repetido_no_cambia_el_comportamiento(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'total' => 500]);
        $producto = Producto::factory()->create();
        $venta->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 5,
            'precio_unitario' => 100, 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        $items = $this->servicio->itemsDisponibles($venta->fresh());

        $this->assertCount(1, $items);
        $this->assertSame(5.0, $items[0]['pendiente']);
    }

    // -----------------------------------------------------------------
    // US2 — Ajuste parcial y fallback
    // -----------------------------------------------------------------

    /** T013 (FR-007): guardar sólo 2 de las 3 líneas precargadas persiste exactamente esas 2. */
    public function test_guardar_solo_algunas_lineas_precargadas_no_afecta_a_las_demas(): void
    {
        [$venta, , $linea1, $linea2, $linea3] = $this->ventaConProductoRepetido();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        // El usuario borró la línea de $50.000 antes de guardar: sólo manda linea1 y linea2.
        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 15730 + 27225,
            'items' => [
                ['producto_id' => $linea1->producto_id, 'cantidad' => 1, 'precio' => 13000, 'item_origen_id' => $linea1->id],
                ['producto_id' => $linea2->producto_id, 'cantidad' => 1, 'precio' => 25000, 'descuento_pct' => 10, 'item_origen_id' => $linea2->id],
            ],
        ]);

        $response->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->latest('id')->first();
        $this->assertCount(2, $nota->items);
        $this->assertEqualsCanonicalizing(
            [$linea1->id, $linea2->id],
            $nota->items->pluck('venta_item_id')->all()
        );

        // La línea de $50.000 sigue intacta y pendiente — nadie la tocó.
        $pendienteLinea3 = $this->servicio->pendienteDeLinea($linea3->fresh());
        $this->assertSame(1.0, $pendienteLinea3);
    }

    /** T014 (FR-003): tras ajustar una línea, una segunda precarga no vuelve a ofrecerla. */
    public function test_segunda_nota_no_vuelve_a_ofrecer_lo_ya_ajustado_por_linea(): void
    {
        [$venta, , $linea1, $linea2, $linea3] = $this->ventaConProductoRepetido();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 27225,
            'items' => [
                ['producto_id' => $linea2->producto_id, 'cantidad' => 1, 'precio' => 25000, 'descuento_pct' => 10, 'item_origen_id' => $linea2->id],
            ],
        ])->assertCreated();

        $items = $this->servicio->itemsDisponibles($venta->fresh());

        $this->assertCount(2, $items, 'Sólo deben quedar las líneas de $13.000 y $50.000.');
        $precios = collect($items)->pluck('precio')->sort()->values()->all();
        $this->assertSame([13000.0, 50000.0], $precios);
    }

    /** T015 (FR-006): una nota vieja sin referencia de línea mantiene el cálculo agregado (fallback). */
    public function test_fallback_agregado_cuando_ninguna_nota_tiene_referencia_de_linea(): void
    {
        [$venta, $producto, $linea1, $linea2] = $this->ventaConProductoRepetido();

        // Simula una NC/ND creada ANTES de este fix: sin venta_item_id, ajustando el producto
        // "a lo bruto" por 1 unidad (como haría el código viejo).
        $notaVieja = $venta->notasCreditoDebito()->create([
            'tipo' => 'credito', 'afecta_stock' => true, 'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(), 'monto' => 15730,
            'tipo_comprobante' => $venta->tipo_comprobante,
        ]);
        $notaVieja->items()->create([
            'producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 13000, 'origen' => 'venta_original',
            // Sin venta_item_id — simula el dato histórico.
        ]);

        $items = $this->servicio->itemsDisponibles($venta->fresh());

        // Modo agregado: 1 sola fila para el producto, con pendiente = 3 - 1 = 2 (facturado
        // agregado menos lo ya ajustado agregado), igual que el código viejo.
        $this->assertCount(1, $items);
        $this->assertSame(2.0, $items[0]['pendiente']);
    }

    /**
     * T016 (FR-006): mientras exista UNA nota vieja sin referencia sobre un producto, ese producto
     * se mantiene en modo agregado — incluso si después se crea una nota nueva que sí trae
     * referencia de línea. Es la lectura correcta de "ninguna referencia de línea": no alcanza con
     * que ALGUNA nota la tenga, tiene que ser que NINGUNA le falte, porque mientras la nota vieja
     * exista no hay forma de saber qué línea puntual consumió.
     */
    public function test_fallback_persiste_mientras_exista_una_nota_vieja_sin_referencia(): void
    {
        [$venta, $producto, , $linea2] = $this->ventaConProductoRepetido();

        // Nota vieja sin referencia (fallback), ajusta 1 unidad "a lo bruto".
        $notaVieja = $venta->notasCreditoDebito()->create([
            'tipo' => 'credito', 'afecta_stock' => true, 'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(), 'monto' => 15730,
            'tipo_comprobante' => $venta->tipo_comprobante,
        ]);
        $notaVieja->items()->create([
            'producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 13000, 'origen' => 'venta_original',
        ]);

        // Confirmamos que arranca en modo agregado: pendiente = 3 - 1 = 2, en 1 sola fila.
        $itemsAntes = $this->servicio->itemsDisponibles($venta->fresh());
        $this->assertCount(1, $itemsAntes);
        $this->assertSame(2.0, $itemsAntes[0]['pendiente']);

        // Se crea una nota NUEVA con este fix, referenciando la línea2 explícitamente — pero la
        // nota vieja sigue existiendo, así que el producto NO puede pasar a modo por línea todavía.
        $notaNueva = $venta->notasCreditoDebito()->create([
            'tipo' => 'credito', 'afecta_stock' => true, 'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(), 'monto' => 27225,
            'tipo_comprobante' => $venta->tipo_comprobante,
        ]);
        $notaNueva->items()->create([
            'producto_id' => $producto->id, 'venta_item_id' => $linea2->id,
            'cantidad' => 1, 'precio' => 25000, 'descuento_pct' => 10, 'origen' => 'venta_original',
        ]);

        $items = $this->servicio->itemsDisponibles($venta->fresh());

        // Sigue en modo agregado: 1 sola fila, pendiente = 3 - 1 (vieja) - 1 (nueva) = 1.
        $this->assertCount(1, $items);
        $this->assertSame(1.0, $items[0]['pendiente']);
    }

    // -----------------------------------------------------------------
    // US3 — No regresión
    // -----------------------------------------------------------------

    /** T017 (FR-009): editar una nota existente no depende del comprobante de origen. */
    public function test_edicion_no_recibe_cabecera_ni_items_del_comprobante(): void
    {
        [$venta, , $linea1] = $this->ventaConProductoRepetido();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $deposito->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 15730,
            'items' => [['producto_id' => $linea1->producto_id, 'cantidad' => 1, 'precio' => 13000, 'item_origen_id' => $linea1->id]],
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->latest('id')->first();

        $this->get(route('ventas.notas.edit', [$venta, $nota]))
            ->assertOk()
            ->assertViewMissing('cabeceraOrigen');
    }

    /** T018 (SC-004): comprobante sin producto repetido, total propuesto sin cambios (réplica spec 095). */
    public function test_no_regresion_sobre_comprobante_sin_producto_repetido(): void
    {
        $venta = Venta::factory()->create([
            'cliente_id' => Cliente::factory(),
            'descuento_general_tipo' => 'porcentaje', 'descuento_general_pct' => 5,
            'subtotal_sin_descuento' => 3572.30, 'descuento' => 178.61,
            'subtotal_con_descuento' => 3393.69, 'total' => 4106.36,
        ]);
        $venta->items()->create([
            'producto_id' => Producto::factory()->create()->id, 'descripcion' => 'x',
            'cantidad' => 1, 'precio_unitario' => 3572.30, 'iva_pct' => '21',
            'subtotal' => 3393.69, 'subtotal_con_iva' => 4106.36,
        ]);

        $items = $this->servicio->itemsDisponibles($venta->fresh());

        $this->assertCount(1, $items);
        $total = round(($items[0]['pendiente'] * $items[0]['precio']) * 1.21 * 0.95, 2);
        $this->assertEqualsWithDelta(4106.36, $total, 0.01);
    }

    /** T019 (FR-010): paridad en Compras — mismo comportamiento con compra_item_id. */
    public function test_paridad_en_compras_con_producto_repetido(): void
    {
        $compra = Compra::factory()->create(['proveedor_id' => Proveedor::factory(), 'total' => 30000]);
        $producto = Producto::factory()->create();
        $lineaA = $compra->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 1,
            'precio_unitario' => 10000, 'iva_pct' => '21', 'subtotal' => 10000, 'subtotal_con_iva' => 12100,
        ]);
        $lineaB = $compra->items()->create([
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 1,
            'precio_unitario' => 20000, 'iva_pct' => '21', 'subtotal' => 20000, 'subtotal_con_iva' => 24200,
        ]);

        $items = $this->servicio->itemsDisponibles($compra->fresh());

        $this->assertCount(2, $items, 'Paridad con Ventas: 2 líneas independientes, no fundidas.');
        $precios = collect($items)->pluck('precio')->sort()->values()->all();
        $this->assertSame([10000.0, 20000.0], $precios);

        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $deposito->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 12100,
            'items' => [['producto_id' => $lineaA->producto_id, 'cantidad' => 1, 'precio' => 10000, 'item_origen_id' => $lineaA->id]],
        ])->assertCreated();

        $nota = $compra->fresh()->notasCreditoDebito()->latest('id')->first();
        $this->assertSame($lineaA->id, $nota->items->first()->compra_item_id);
        $this->assertNull($nota->items->first()->venta_item_id);
    }
}
