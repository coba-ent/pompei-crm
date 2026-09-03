<?php

namespace Tests\Feature\Informes;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\CuentaTesoreria;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Services\Informes\VentasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 068 US1/US2 — KPIs, detalle y filtros del Informe de Ventas.
 *
 * Es todo cálculo de dinero, así que la constitución (principio IV) lo exige cubierto. Los tests
 * atacan el servicio directamente y no el endpoint: es la misma razón por la que el cálculo vive
 * fuera del controlador.
 */
class InformeVentasTest extends TestCase
{
    use ArmaVentas, ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');
        $this->autenticarConPermisoInformes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function informe(): VentasInformeQuery
    {
        return app(VentasInformeQuery::class);
    }

    // -----------------------------------------------------------------------------------
    // Detalle
    // -----------------------------------------------------------------------------------

    public function test_el_detalle_trae_una_fila_por_item_y_no_una_por_venta(): void
    {
        $this->venta([
            ['cantidad' => 1, 'precio' => 100],
            ['cantidad' => 2, 'precio' => 200],
            ['cantidad' => 3, 'precio' => 300],
        ]);

        $this->assertCount(3, $this->informe()->detalle($this->request())->get());
    }

    /**
     * Hasta el 24/08/2026 este test se llamaba
     * `test_total_comprobante_se_repite_en_cada_fila_de_la_misma_venta` y afirmaba que CADA fila
     * debía mostrar el total del comprobante repetido — con un comentario que lo llamaba "la
     * trampa principal del informe", como si fuera el comportamiento correcto. Era falso: Contagram
     * muestra el importe DE CADA LÍNEA, no el total repetido (capturado el 24/08/2026 contra la
     * venta 23501, con 12 líneas de importes distintos que suman el total). La columna técnica
     * `total_comprobante` sigue existiendo sin cambios —el pivot y otros consumidores la usan
     * (research §R7)— pero dejó de ser lo que se muestra; lo que se muestra y tiene que sumar es
     * `total_venta` (spec 076, FR-001/FR-002, research §R5).
     */
    public function test_los_importes_de_linea_de_una_venta_suman_su_total(): void
    {
        $venta = $this->venta([
            ['cantidad' => 1, 'precio' => 100, 'iva_pct' => '21'],
            ['cantidad' => 1, 'precio' => 400, 'iva_pct' => '21'],
        ]);

        $filas = $this->informe()->detalle($this->request())->get();

        $suma = round($filas->sum(fn ($f) => (float) $f->total_venta), 2);

        $this->assertEqualsWithDelta((float) $venta->total, $suma, 0.01);
        // Y no todas las líneas son iguales entre sí, a diferencia del criterio viejo.
        $this->assertNotEquals((float) $filas[0]->total_venta, (float) $filas[1]->total_venta);
    }

    public function test_la_nota_de_credito_aporta_sus_filas_en_negativo_y_la_de_debito_en_positivo(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 370]]);
        $this->nota($venta, 'credito', [['cantidad' => 1, 'precio' => 370]]);
        $this->nota($venta, 'debito', [['cantidad' => 1, 'precio' => 50]]);

        $filas = $this->informe()->detalle($this->request())->get()->keyBy('tipo_operacion');

        $this->assertEqualsWithDelta(-1.0, (float) $filas['nc']->cantidad, 0.001);
        $this->assertEqualsWithDelta(-370.0, (float) $filas['nc']->precio_neto, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $filas['nd']->cantidad, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $filas['nd']->precio_neto, 0.01);
    }

    /**
     * El caso exacto del relevamiento (§11.2): en **pantalla** la NC muestra -170,00, no -570,00.
     * La desviación de Contagram vive sólo en el Excel y tiene su propio test.
     */
    public function test_en_pantalla_el_resultado_resta_el_cmv_en_todas_las_filas_incluidas_las_notas(): void
    {
        $producto = $this->productoConCosto(200);
        $venta = $this->venta([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 370]]);
        $this->nota($venta, 'credito', [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 370]]);

        $filas = $this->informe()->detalle($this->request())->get()->keyBy('tipo_operacion');

        $this->assertEqualsWithDelta(170.0, (float) $filas['venta']->resultado, 0.01);
        $this->assertEqualsWithDelta(-170.0, (float) $filas['nc']->resultado, 0.01);

        foreach ($filas as $fila) {
            $this->assertEqualsWithDelta(
                (float) $fila->precio_neto - (float) $fila->cmv_total,
                (float) $fila->resultado,
                0.01
            );
        }
    }

    public function test_una_nota_sin_venta_asociada_aporta_su_fila_igual(): void
    {
        $this->nota(null, 'credito', [['cantidad' => 1, 'precio' => 100]]);

        $filas = $this->informe()->detalle($this->request())->get();

        $this->assertCount(1, $filas);
        $this->assertSame('Sin cliente', $filas->first()->cliente);
    }

    /**
     * Las notas migradas de Contagram no trajeron detalle de ítems (el export de origen no lo
     * tenía). Con un INNER JOIN contra `nota_credito_debito_items` desaparecían enteras del
     * informe y "Total Nota de Crédito" daba $0,00 con la plata bien cargada en `monto`.
     */
    public function test_una_nota_sin_items_aporta_su_monto_al_kpi_y_una_fila_en_cero(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 1000]]);
        $this->nota($venta, 'credito', [], ['monto' => 250.0]);

        $kpis = $this->informe()->kpis($this->request());

        $this->assertEqualsWithDelta(250.0, $kpis['total_nota_credito'], 0.01);
        $this->assertEqualsWithDelta(750.0, $kpis['total_ventas'], 0.01);

        // La nota aporta una fila, pero sin unidades ni CMV: ese detalle no existe todavía.
        $fila = $this->informe()->detalle($this->request())->get()
            ->firstWhere('tipo_operacion', 'nc');

        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(0.0, (float) $fila->cantidad, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $fila->cmv_total, 0.01);
        $this->assertEqualsWithDelta(-250.0, (float) $fila->total_comprobante, 0.01);
    }

    public function test_las_ventas_y_notas_dadas_de_baja_no_computan(): void
    {
        $viva = $this->venta([['cantidad' => 1, 'precio' => 100]]);
        $muerta = $this->venta([['cantidad' => 1, 'precio' => 999]]);
        Venta::withoutEvents(fn () => $muerta->delete());

        $nota = $this->nota($viva, 'credito', [['cantidad' => 1, 'precio' => 40]]);
        $nota->delete();

        $filas = $this->informe()->detalle($this->request())->get();
        $kpis = $this->informe()->kpis($this->request());

        $this->assertCount(1, $filas);
        $this->assertEqualsWithDelta(100.0, $kpis['total_ventas_creadas'], 0.01);
        $this->assertEqualsWithDelta(0.0, $kpis['total_nota_credito'], 0.01);
    }

    public function test_el_rango_por_defecto_es_el_mes_calendario_completo_incluidas_fechas_futuras(): void
    {
        // Hoy es el 15/08. Una venta del 28/08 —futura dentro del mes— tiene que entrar (FR-003).
        $this->venta([['cantidad' => 1, 'precio' => 100]], ['fecha_emision' => '2026-08-28']);
        $this->venta([['cantidad' => 1, 'precio' => 500]], ['fecha_emision' => '2026-07-31']);

        $rango = $this->informe()->rango($this->request());

        $this->assertSame('2026-08-01', $rango['desde']);
        $this->assertSame('2026-08-31', $rango['hasta']);
        $this->assertEqualsWithDelta(100.0, $this->informe()->kpis($this->request())['total_ventas_creadas'], 0.01);
    }

    // -----------------------------------------------------------------------------------
    // KPIs
    // -----------------------------------------------------------------------------------

    public function test_la_ecuacion_de_kpis_es_creadas_mas_nd_menos_nc(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 1000]]);
        $this->nota($venta, 'debito', [['cantidad' => 1, 'precio' => 200]]);
        $this->nota($venta, 'credito', [['cantidad' => 1, 'precio' => 300]]);

        $kpis = $this->informe()->kpis($this->request());

        $this->assertEqualsWithDelta(1000.0, $kpis['total_ventas_creadas'], 0.01);
        $this->assertEqualsWithDelta(200.0, $kpis['total_nota_debito'], 0.01);
        // La NC se muestra en positivo y se **resta**.
        $this->assertEqualsWithDelta(300.0, $kpis['total_nota_credito'], 0.01);
        $this->assertEqualsWithDelta(900.0, $kpis['total_ventas'], 0.01);
    }

    public function test_cantidad_prod_serv_es_la_suma_de_cantidades_y_no_el_conteo_de_lineas(): void
    {
        // 10 unidades en una sola línea son 10, no 1 (FR-011).
        $this->venta([['cantidad' => 10, 'precio' => 100], ['cantidad' => 5, 'precio' => 100]]);

        $this->assertEqualsWithDelta(15.0, $this->informe()->kpis($this->request())['cantidad_prod_serv'], 0.001);
    }

    public function test_venta_promedio_vale_cero_cuando_no_hay_ventas(): void
    {
        $kpis = $this->informe()->kpis($this->request());

        $this->assertSame(0.0, $kpis['venta_promedio']);
        $this->assertSame(0, $kpis['cantidad_ventas_creadas']);
        $this->assertSame(0.0, $kpis['total_ventas']);
    }

    public function test_venta_promedio_divide_el_total_por_la_cantidad_de_ventas(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 100]]);
        $this->venta([['cantidad' => 1, 'precio' => 300]]);

        $kpis = $this->informe()->kpis($this->request());

        $this->assertSame(2, $kpis['cantidad_ventas_creadas']);
        $this->assertEqualsWithDelta(200.0, $kpis['venta_promedio'], 0.01);
    }

    public function test_el_resultado_del_bloque_tres_es_precio_neto_menos_cmv(): void
    {
        $producto = $this->productoConCosto(150);
        $this->venta([['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 500]]);

        $kpis = $this->informe()->kpis($this->request());

        $this->assertEqualsWithDelta(1000.0, $kpis['precio_neto'], 0.01);
        $this->assertEqualsWithDelta(300.0, $kpis['cmv'], 0.01);
        $this->assertEqualsWithDelta(700.0, $kpis['resultado'], 0.01);
    }

    public function test_los_kpis_no_cambian_al_paginar_el_detalle(): void
    {
        // SC-003: los totales se calculan sobre el conjunto filtrado completo, no sobre la página.
        for ($i = 0; $i < 12; $i++) {
            $this->venta([['cantidad' => 1, 'precio' => 100]]);
        }

        $kpis = $this->informe()->kpis($this->request());
        $primeraPagina = $this->informe()->detalle($this->request())->limit(5)->get();

        $this->assertCount(5, $primeraPagina);
        $this->assertEqualsWithDelta(1200.0, $kpis['total_ventas'], 0.01);
        $this->assertSame(12, $kpis['cantidad_ventas_creadas']);
    }

    // -----------------------------------------------------------------------------------
    // Filtros
    // -----------------------------------------------------------------------------------

    public function test_filtra_por_cliente(): void
    {
        $cliente = Cliente::factory()->create();
        $this->venta([['cantidad' => 1, 'precio' => 100]], ['cliente_id' => $cliente->id]);
        $this->venta([['cantidad' => 1, 'precio' => 900]]);

        $request = $this->request(['cliente_id' => [$cliente->id]]);

        $this->assertCount(1, $this->informe()->detalle($request)->get());
        $this->assertEqualsWithDelta(100.0, $this->informe()->kpis($request)['total_ventas'], 0.01);
    }

    public function test_el_filtro_de_categoria_toma_tambien_las_hijas_de_la_raiz_elegida(): void
    {
        $raiz = Categoria::factory()->create(['tipo' => 'venta']);
        $hija = Categoria::factory()->create(['tipo' => 'venta', 'categoria_padre_id' => $raiz->id]);

        $this->venta([['cantidad' => 1, 'precio' => 100]], ['categoria_id' => $raiz->id]);
        $this->venta([['cantidad' => 1, 'precio' => 200]], ['categoria_id' => $hija->id]);
        $this->venta([['cantidad' => 1, 'precio' => 900]]);

        $request = $this->request(['categoria_id' => [$raiz->id]]);

        $this->assertEqualsWithDelta(300.0, $this->informe()->kpis($request)['total_ventas'], 0.01);
    }

    public function test_el_filtro_tipo_separa_venta_de_nota_de_credito(): void
    {
        // "Tipo" es el tipo de **operación**, distinto de "Tipo de Factura" (FR-017b).
        $venta = $this->venta([['cantidad' => 1, 'precio' => 500]]);
        $this->nota($venta, 'credito', [['cantidad' => 1, 'precio' => 100]]);

        $soloNc = $this->informe()->detalle($this->request(['tipo_operacion' => ['nc']]))->get();
        $soloVentas = $this->informe()->detalle($this->request(['tipo_operacion' => ['venta']]))->get();

        $this->assertCount(1, $soloNc);
        $this->assertSame('nc', $soloNc->first()->tipo_operacion);
        $this->assertCount(1, $soloVentas);
        $this->assertSame('venta', $soloVentas->first()->tipo_operacion);
    }

    public function test_el_filtro_de_estado_del_cobro_distingue_cobrado_parcial_y_pendiente(): void
    {
        $cuenta = CuentaTesoreria::factory()->create();

        $cobrada = $this->venta([['cantidad' => 1, 'precio' => 100]]);
        Cobro::factory()->create(['venta_id' => $cobrada->id, 'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 100]);

        $parcial = $this->venta([['cantidad' => 1, 'precio' => 200]]);
        Cobro::factory()->create(['venta_id' => $parcial->id, 'cuenta_tesoreria_id' => $cuenta->id, 'monto' => 50]);

        $this->venta([['cantidad' => 1, 'precio' => 300]]);

        $este = fn (string $estado) => $this->informe()->kpis($this->request(['estado_cobro' => [$estado]]))['total_ventas_creadas'];

        $this->assertEqualsWithDelta(100.0, $este('cobrado'), 0.01);
        $this->assertEqualsWithDelta(200.0, $este('parcial'), 0.01);
        $this->assertEqualsWithDelta(300.0, $este('pendiente'), 0.01);
    }

    public function test_el_filtro_productos_separa_catalogo_de_conceptos_libres(): void
    {
        $producto = $this->productoConCosto(10);
        $this->venta([
            ['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100],
            ['producto_id' => null, 'descripcion' => 'Flete', 'cantidad' => 1, 'precio' => 50],
        ]);

        $this->assertCount(1, $this->informe()->detalle($this->request(['solo_productos' => 'si']))->get());
        $this->assertCount(1, $this->informe()->detalle($this->request(['solo_productos' => 'no']))->get());
    }

    public function test_el_rango_dado_vuelta_responde_422(): void
    {
        $this->getJson(route('informes.ventas.data', ['desde' => '2026-08-20', 'hasta' => '2026-08-01']))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    // -----------------------------------------------------------------------------------
    // spec 076 US3 — contenido de columnas (FR-014, FR-015, FR-021)
    // -----------------------------------------------------------------------------------

    /** FR-014/FR-021: la proyección distingue tipo de operación y sigla completa del comprobante. */
    public function test_la_sigla_del_comprobante_es_completa_y_no_solo_la_letra(): void
    {
        $venta = $this->venta([['cantidad' => 1, 'precio' => 100]], ['tipo_comprobante' => 'B']);
        $this->nota($venta, 'credito', [['cantidad' => 1, 'precio' => 50]], ['tipo_comprobante' => 'B']);
        $this->nota($venta, 'debito', [['cantidad' => 1, 'precio' => 20]], ['tipo_comprobante' => 'A']);

        $filas = $this->informe()->detalle($this->request())->get()->keyBy('tipo_operacion');

        $this->assertSame('FCB', $filas['venta']->sigla_comprobante);
        $this->assertSame('NCB', $filas['nc']->sigla_comprobante);
        $this->assertSame('NDA', $filas['nd']->sigla_comprobante);
    }

    /** FR-015 (alcance pantalla): la proyección expone el código del producto en su propia columna. */
    public function test_la_proyeccion_expone_el_codigo_del_producto_para_anteponerlo_en_pantalla(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'ABC123']);
        $this->venta([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100]]);
        $this->venta([['producto_id' => null, 'descripcion' => 'Flete', 'cantidad' => 1, 'precio' => 50]]);

        $filas = $this->informe()->detalle($this->request())->get();

        $conCodigo = $filas->firstWhere('producto_id', $producto->id);
        $sinCodigo = $filas->firstWhere('producto_id', null);

        $this->assertSame('ABC123', $conCodigo->codigo);
        $this->assertSame('Flete', $sinCodigo->producto);
        $this->assertNull($sinCodigo->codigo);
    }

    private function productoConCosto(float $costoCompra): Producto
    {
        $producto = Producto::factory()->create(['costo' => 0]);

        $compra = Compra::factory()->create(['fecha_emision' => '2026-07-01']);
        CompraItem::create([
            'compra_id' => $compra->id,
            'producto_id' => $producto->id,
            'descripcion' => $producto->nombre,
            'cantidad' => 1,
            'precio_unitario' => $costoCompra,
            'subtotal' => $costoCompra,
            'subtotal_con_iva' => $costoCompra,
        ]);

        return $producto;
    }

    /**
     * EL CASO QUE MOTIVÓ EL CAMBIO (03/09/2026).
     *
     * Con un filtro que actúa sobre la LÍNEA (Proveedor, Producto, Tipo de Producto), los KPIs de
     * comprobante sumaban la factura entera aunque sólo una de sus líneas matcheara, mientras
     * Precio Neto sumaba sólo las líneas. El bloque no cerraba: en producción, filtrando por un
     * proveedor en agosto 2026, daba $12.435.090 de "ventas" contra $3.545.808 de neto — de las
     * 136 líneas de esas facturas sólo 49 eran del proveedor filtrado.
     *
     * Contagram suma las líneas filtradas: verificado sobre dos exports reales de la MISMA venta
     * con distinto proveedor, que dan $3.055,25 y $3.993. Si sumara la factura entera, darían igual.
     */
    public function test_con_filtro_de_proveedor_los_kpis_suman_solo_sus_lineas(): void
    {
        $proveedorA = Proveedor::factory()->create(['nombre' => 'Proveedor A']);
        $proveedorB = Proveedor::factory()->create(['nombre' => 'Proveedor B']);

        $delA = Producto::factory()->create(['proveedor_id' => $proveedorA->id]);
        $delB = Producto::factory()->create(['proveedor_id' => $proveedorB->id]);

        // Una sola venta con líneas de los dos proveedores: $1.000 de A y $3.000 de B.
        $this->venta([
            ['producto_id' => $delA->id, 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '0'],
            ['producto_id' => $delB->id, 'cantidad' => 1, 'precio' => 3000, 'iva_pct' => '0'],
        ]);

        $kpis = $this->informe()->kpis($this->request(['proveedor_id' => [$proveedorA->id]]));

        $this->assertEqualsWithDelta(1000.0, $kpis['total_ventas_creadas'], 0.01,
            'Suma la línea del proveedor filtrado, no los $4.000 de la factura entera.');
        $this->assertEqualsWithDelta(1000.0, $kpis['precio_neto'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $kpis['total_ventas'], 0.01);
    }

    /**
     * La invariante que protege el uso normal: sin filtros de línea, el KPI tiene que dar lo mismo
     * que daba cuando salía de `ventas.total`. Sumar todas las líneas de una factura da su total.
     */
    public function test_sin_filtros_el_total_sigue_siendo_el_de_la_factura_entera(): void
    {
        $this->venta([
            ['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '0'],
            ['cantidad' => 1, 'precio' => 3000, 'iva_pct' => '0'],
        ]);

        $kpis = $this->informe()->kpis($this->request());

        $this->assertEqualsWithDelta(4000.0, $kpis['total_ventas_creadas'], 0.01);
        $this->assertSame(1, $kpis['cantidad_ventas_creadas']);
    }

    /**
     * Una venta sin ítems sigue aportando su total.
     *
     * `queryItems` arranca desde `venta_items`, así que no produce ninguna fila y su importe se
     * perdería al pasar el KPI al nivel línea. En producción son 2 órdenes de Mercado Libre del
     * 13/08/2026 que se importaron con su total pero sin líneas ($561.753 entre las dos): sin esta
     * suma, el total del mes bajaría ese importe sin que nadie haya filtrado nada.
     */
    public function test_una_venta_sin_items_igual_aporta_su_total(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 1000, 'iva_pct' => '0']]);

        $huerfana = $this->venta([['cantidad' => 1, 'precio' => 500, 'iva_pct' => '0']]);
        $huerfana->items()->delete();

        $kpis = $this->informe()->kpis($this->request());

        $this->assertEqualsWithDelta(1500.0, $kpis['total_ventas_creadas'], 0.01);
        $this->assertSame(2, $kpis['cantidad_ventas_creadas']);
    }

    /** Pero con un filtro de línea NO entra: no tiene producto que pueda matchear. */
    public function test_una_venta_sin_items_no_entra_si_hay_filtro_de_linea(): void
    {
        $proveedor = Proveedor::factory()->create();
        $producto = Producto::factory()->create(['proveedor_id' => $proveedor->id]);

        $this->venta([['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 1000, 'iva_pct' => '0']]);

        $huerfana = $this->venta([['cantidad' => 1, 'precio' => 500, 'iva_pct' => '0']]);
        $huerfana->items()->delete();

        $kpis = $this->informe()->kpis($this->request(['proveedor_id' => [$proveedor->id]]));

        $this->assertEqualsWithDelta(1000.0, $kpis['total_ventas_creadas'], 0.01);
        $this->assertSame(1, $kpis['cantidad_ventas_creadas']);
    }
}
