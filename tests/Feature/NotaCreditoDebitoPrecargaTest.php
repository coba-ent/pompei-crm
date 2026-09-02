<?php

namespace Tests\Feature;

use App\Models\Categoria;
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
 * Spec 095 — Espejo del comprobante de origen al crear una NC/ND.
 *
 * Hasta esta spec el alta precargaba SÓLO los ítems: el descuento general y el tipo de
 * comprobante nacían vacíos, así que una nota sobre una venta con descuento proponía el total
 * SIN descuento (en la venta 24740, $11.497,80 de más). Estos tests fijan que la cabecera se
 * hereda, que se hereda con la modalidad correcta, y que precargar no impone.
 */
class NotaCreditoDebitoPrecargaTest extends TestCase
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

    /** Venta con un ítem, para que la precarga tenga de dónde partir. */
    private function ventaConItem(array $atributos = [], array $item = []): Venta
    {
        $venta = Venta::factory()->create(array_merge([
            'cliente_id' => Cliente::factory(),
            'total' => 1000,
        ], $atributos));

        $producto = Producto::factory()->create();
        $venta->items()->create(array_merge([
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => 5,
            'precio_unitario' => 100,
            'subtotal' => 500,
            'subtotal_con_iva' => 500,
        ], $item));

        return $venta->fresh();
    }

    // -----------------------------------------------------------------
    // US1 — Descuento general (el reporte original del cliente)
    // -----------------------------------------------------------------

    /** T011 (FR-002): el descuento en porcentaje se hereda con su modalidad. */
    public function test_precarga_hereda_descuento_general_en_porcentaje(): void
    {
        $venta = $this->ventaConItem([
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 5,
        ]);

        $cabecera = $this->servicio->cabeceraComprobante($venta);

        $this->assertSame('porcentaje', $cabecera['descuentoGeneralTipo']);
        $this->assertSame(5.0, $cabecera['descuentoGeneralPct']);
        $this->assertNull($cabecera['descuentoGeneralMonto'], 'En modo porcentaje no viaja un monto.');
    }

    /** T012 (FR-002): en modo monto se hereda el importe TAL CUAL, sin convertirlo a un % equivalente. */
    public function test_precarga_hereda_descuento_general_en_monto_sin_convertirlo(): void
    {
        $venta = $this->ventaConItem([
            'descuento_general_tipo' => 'monto',
            'descuento_general_monto' => 250.75,
        ]);

        $cabecera = $this->servicio->cabeceraComprobante($venta);

        $this->assertSame('monto', $cabecera['descuentoGeneralTipo']);
        $this->assertSame(250.75, $cabecera['descuentoGeneralMonto']);
        $this->assertNull($cabecera['descuentoGeneralPct'], 'No se convierte el monto a porcentaje.');
    }

    /** T013 (FR-002): sin descuento en el comprobante no se inventa ninguno. */
    public function test_precarga_sin_descuento_no_inventa_valor(): void
    {
        $venta = $this->ventaConItem([
            'descuento_general_pct' => null,
            'descuento_general_monto' => null,
        ]);

        $cabecera = $this->servicio->cabeceraComprobante($venta);

        $this->assertNull($cabecera['descuentoGeneralPct']);
        $this->assertNull($cabecera['descuentoGeneralMonto']);
    }

    /** T014 (FR-003): el descuento POR LÍNEA sigue viniendo de los ítems y el general no lo pisa. */
    public function test_descuento_por_linea_se_conserva_y_es_independiente_del_general(): void
    {
        $venta = $this->ventaConItem(
            ['descuento_general_tipo' => 'porcentaje', 'descuento_general_pct' => 10],
            ['descuento_pct' => 20]
        );

        $cabecera = $this->servicio->cabeceraComprobante($venta);
        $items = $this->servicio->itemsDisponibles($venta);

        // El general vive en la cabecera; la bonificación de línea, en el ítem. Son dos
        // factores separados: no se prorratea uno dentro del otro ni se aplican dos veces.
        $this->assertSame(10.0, $cabecera['descuentoGeneralPct']);
        $this->assertSame(20.0, $items[0]['descuento_pct']);
    }

    /**
     * T014a (FR-014, SC-001): el total propuesto coincide con el del comprobante dentro de medio
     * centavo. Es el corazón de la spec — reproduce la venta 24740, donde sin heredar el
     * descuento general la nota proponía $229.956,12 en vez de $218.458,32.
     *
     * Clave para entender que NO hay doble descuento: la venta guarda en el ítem el `subtotal`
     * ya descontado (3.393,69), pero `itemsDisponibles()` sirve el `precio_unitario` BRUTO
     * (3.572,30) con `descuento_pct` en 0. El descuento general se aplica sobre ese bruto, una
     * sola vez.
     */
    public function test_total_propuesto_coincide_con_el_del_comprobante_dentro_de_medio_centavo(): void
    {
        // Réplica de una línea de la venta 24740, con IVA 21% y 5% de descuento general.
        $venta = $this->ventaConItem(
            [
                'descuento_general_tipo' => 'porcentaje',
                'descuento_general_pct' => 5,
                'subtotal_sin_descuento' => 3572.30,
                'descuento' => 178.61,
                'subtotal_con_descuento' => 3393.69,
                'total' => 4106.36,
            ],
            [
                'cantidad' => 1,
                'precio_unitario' => 3572.30,
                'descuento_pct' => null,
                'iva_pct' => '21',
                'subtotal' => 3393.69,
                'subtotal_con_iva' => 4106.36,
            ]
        );

        $cabecera = $this->servicio->cabeceraComprobante($venta);
        $items = $this->servicio->itemsDisponibles($venta);

        // Réplica de recalcular() en notas-credito-debito.js.
        $factor = 1 - ($cabecera['descuentoGeneralPct'] / 100);
        $sinDescuento = 0.0;
        foreach ($items as $item) {
            $bruto = $item['pendiente'] * $item['precio'];
            $subtotal = $bruto - ($bruto * $item['descuento_pct'] / 100);
            $sinDescuento += $subtotal * 1.21;
        }
        $propuesto = round($sinDescuento * $factor, 2);

        $this->assertEqualsWithDelta((float) $venta->total, $propuesto, 0.005);

        // Y el bug que la spec vino a arreglar: sin heredar el descuento, la nota nace de MÁS.
        $this->assertGreaterThan((float) $venta->total, round($sinDescuento, 2));
    }

    // -----------------------------------------------------------------
    // US2 — Tipo de comprobante
    // -----------------------------------------------------------------

    /** T017 (FR-004): el tipo se deriva del comprobante, tanto para A como para B. */
    public function test_precarga_trae_el_tipo_de_comprobante_del_origen(): void
    {
        foreach (['A', 'B'] as $tipo) {
            $venta = $this->ventaConItem(['tipo_comprobante' => $tipo]);

            $this->assertSame(
                $tipo,
                $this->servicio->cabeceraComprobante($venta)['tipoComprobante'],
                "La nota sobre una factura {$tipo} debe nacer en {$tipo}."
            );
        }
    }

    /** T018 (FR-004): sin tipo en el origen el campo queda vacío — no se infiere ninguno. */
    public function test_comprobante_sin_tipo_no_infiere_uno(): void
    {
        // En Compras el tipo es nullable ("Sin Factura"); en Ventas la columna es un enum NOT NULL.
        $compra = Compra::factory()->create([
            'proveedor_id' => Proveedor::factory(),
            'tipo_comprobante' => null,
        ]);

        $this->assertNull($this->servicio->cabeceraComprobante($compra)['tipoComprobante']);
    }

    // -----------------------------------------------------------------
    // Fechas y conceptos
    // -----------------------------------------------------------------

    /** T005 (FR-005): cada fecha usa la del comprobante y, si falta, cae en la de emisión. */
    public function test_fechas_faltantes_caen_en_la_de_emision(): void
    {
        $venta = $this->ventaConItem([
            'fecha_emision' => '2026-03-15',
            'fecha_vto_cobro' => null,
            'servicio_desde' => null,
            'servicio_hasta' => null,
        ]);

        $cabecera = $this->servicio->cabeceraComprobante($venta);

        $this->assertSame('2026-03-15', $cabecera['fechaEmision']);
        $this->assertSame('2026-03-15', $cabecera['fechaVencimiento']);
        $this->assertSame('2026-03-15', $cabecera['servicioDesde']);
        $this->assertSame('2026-03-15', $cabecera['servicioHasta']);
    }

    /** FR-005: las fechas viajan en ISO, nunca ya formateadas en dd/mm/aaaa. */
    public function test_las_fechas_viajan_en_iso(): void
    {
        $venta = $this->ventaConItem([
            'fecha_emision' => '2026-08-05',
            'fecha_vto_cobro' => '2026-09-10',
        ]);

        $cabecera = $this->servicio->cabeceraComprobante($venta);

        // El 5 de agosto: si saliera formateado sería "05/08/2026" y el front lo re-invertiría.
        $this->assertSame('2026-08-05', $cabecera['fechaEmision']);
        $this->assertSame('2026-09-10', $cabecera['fechaVencimiento']);
    }

    /** T004 (FR-007): percepciones e impuestos internos se heredan con la forma que usa la nota. */
    public function test_precarga_hereda_conceptos_del_comprobante(): void
    {
        $venta = $this->ventaConItem();
        $venta->conceptos()->create(['tipo' => 'percepcion', 'concepto' => 'IIBB CABA', 'monto' => 120.50]);

        $cabecera = $this->servicio->cabeceraComprobante($venta);

        $this->assertCount(1, $cabecera['conceptos']);
        $this->assertSame(
            ['tipo' => 'percepcion', 'concepto' => 'IIBB CABA', 'monto' => 120.50],
            $cabecera['conceptos'][0]
        );
    }

    /** Sin conceptos el contrato pide un array vacío, no null. */
    public function test_comprobante_sin_conceptos_devuelve_array_vacio(): void
    {
        $this->assertSame([], $this->servicio->cabeceraComprobante($this->ventaConItem())['conceptos']);
    }

    // -----------------------------------------------------------------
    // US3 — Precargar no es imponer
    // -----------------------------------------------------------------

    /** T021 (FR-008): se guarda lo que quedó en pantalla, sin restos de la precarga. */
    public function test_nota_guardada_conserva_lo_enviado_y_no_lo_precargado(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = $this->ventaConItem([
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 5,
        ]);
        $productoId = $venta->items()->first()->producto_id;

        // El usuario baja la cantidad y borra el descuento heredado antes de guardar.
        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $productoId, 'cantidad' => 2, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => null,
        ]);

        $response->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->latest('id')->first();
        $this->assertEqualsWithDelta(200.0, (float) $nota->monto, 0.005);
        $this->assertNull($nota->descuento_general_pct, 'El descuento borrado por el usuario no vuelve.');
        $this->assertEqualsWithDelta(2.0, (float) $nota->items()->first()->cantidad, 0.001);
    }

    // -----------------------------------------------------------------
    // US4 — Paridad con Compras
    // -----------------------------------------------------------------

    /** T024 (FR-010): en Compras se precarga igual que en Ventas. */
    public function test_precarga_en_compras_trae_descuento_tipo_y_fechas(): void
    {
        $compra = Compra::factory()->create([
            'proveedor_id' => Proveedor::factory(),
            'tipo_comprobante' => 'A',
            'fecha_emision' => '2026-04-02',
            'fecha_vto_pago' => '2026-05-02',
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 7,
        ]);

        $cabecera = $this->servicio->cabeceraComprobante($compra);

        $this->assertSame('A', $cabecera['tipoComprobante']);
        $this->assertSame(7.0, $cabecera['descuentoGeneralPct']);
        $this->assertSame('2026-04-02', $cabecera['fechaEmision']);
        // En Compras el vencimiento es `fecha_vto_pago`, no `fecha_vto_cobro`.
        $this->assertSame('2026-05-02', $cabecera['fechaVencimiento']);
    }

    /** T025 (FR-006/FR-010): el tercero de una compra es el Proveedor, no un Cliente. */
    public function test_el_tercero_precargado_en_compras_es_el_proveedor(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'Ferrum SA']);
        $compra = Compra::factory()->create(['proveedor_id' => $proveedor->id]);

        $cabecera = $this->servicio->cabeceraComprobante($compra);

        $this->assertSame($proveedor->id, $cabecera['tercero']['id']);
        $this->assertSame('Ferrum SA', $cabecera['tercero']['nombre']);
    }

    /** FR-006: en Ventas el tercero es el Cliente. */
    public function test_el_tercero_precargado_en_ventas_es_el_cliente(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Juan Pérez']);
        $venta = $this->ventaConItem(['cliente_id' => $cliente->id]);

        $cabecera = $this->servicio->cabeceraComprobante($venta);

        $this->assertSame($cliente->id, $cabecera['tercero']['id']);
        $this->assertSame('Juan Pérez', $cabecera['tercero']['nombre']);
    }

    // -----------------------------------------------------------------
    // Phase 7 — No regresión
    // -----------------------------------------------------------------

    /**
     * T026 (FR-009 por encima de FR-001): con una nota previa, la precarga parte de lo que
     * queda PENDIENTE y no del total del comprobante. Si las dos reglas chocan, gana ésta:
     * evita que una segunda nota proponga ajustar de nuevo lo ya ajustado.
     */
    public function test_con_nota_previa_la_precarga_parte_de_lo_pendiente(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = $this->ventaConItem();
        $productoId = $venta->items()->first()->producto_id;

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $productoId, 'cantidad' => 2, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 200,
        ])->assertCreated();

        $items = $this->servicio->itemsDisponibles($venta->fresh());

        // Se facturaron 5 y ya se ajustaron 2: quedan 3, no las 5 del comprobante.
        $this->assertEqualsWithDelta(3.0, $items[0]['pendiente'], 0.001);
    }

    /**
     * T027 (FR-011, SC-006): editar una nota existente NO precarga desde el comprobante. El
     * controlador de edición no entrega cabecera de origen; si lo hiciera, abrir una nota
     * vieja le cambiaría los importes en pantalla.
     */
    public function test_la_edicion_no_recibe_cabecera_del_comprobante(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $venta = $this->ventaConItem(['descuento_general_pct' => 5]);
        $productoId = $venta->items()->first()->producto_id;

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => true,
            'deposito_id' => $deposito->id,
            'items' => [['producto_id' => $productoId, 'cantidad' => 1, 'precio' => 100]],
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->latest('id')->first();

        $this->get(route('ventas.notas.edit', [$venta, $nota]))
            ->assertOk()
            ->assertViewMissing('cabeceraOrigen');
    }

    /** El alta, en cambio, sí la recibe. */
    public function test_el_alta_recibe_la_cabecera_del_comprobante(): void
    {
        $venta = $this->ventaConItem(['tipo_comprobante' => 'A', 'descuento_general_pct' => 5]);

        $response = $this->get(route('ventas.notas.create', $venta));

        $response->assertOk();
        $cabecera = $response->viewData('cabeceraOrigen');
        $this->assertSame('A', $cabecera['tipoComprobante']);
        $this->assertSame(5.0, $cabecera['descuentoGeneralPct']);
    }

    /**
     * T028 (FR-013): con "afecta stock = No" la nota se guarda por el monto y la descripción
     * que puso el usuario. La cabecera se hereda igual, pero no se arrastran ítems.
     */
    public function test_nota_sin_afectar_stock_guarda_lo_ingresado_sin_arrastrar_items(): void
    {
        $venta = $this->ventaConItem(['descuento_general_pct' => 5]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'descripcion' => 'Ajuste por bonificación comercial',
            'mes_imputacion' => now()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 1500,
        ])->assertCreated();

        $nota = $venta->fresh()->notasCreditoDebito()->latest('id')->first();

        $this->assertEqualsWithDelta(1500.0, (float) $nota->monto, 0.005);
        $this->assertSame('Ajuste por bonificación comercial', $nota->descripcion);
        $this->assertNull($nota->items()->first()?->producto_id);
    }

    /** FR-006: la categoría del comprobante también viaja en la cabecera. */
    public function test_precarga_incluye_la_categoria_del_comprobante(): void
    {
        $categoria = Categoria::factory()->create(['nombre' => 'Ventas Mostrador']);
        $venta = $this->ventaConItem(['categoria_id' => $categoria->id]);

        $cabecera = $this->servicio->cabeceraComprobante($venta);

        $this->assertSame($categoria->id, $cabecera['categoria']['id']);
        $this->assertSame('Ventas Mostrador', $cabecera['categoria']['nombre']);
    }
}
