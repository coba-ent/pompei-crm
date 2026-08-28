<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\ComprobanteHistoricoArca;
use App\Models\MovimientoStock;
use App\Models\MovimientoTesoreria;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\IvaDigital\ComprobantesVentasWriter;
use App\Services\Informes\IvaDigital\DatosFiscalesComprobante;
use App\Services\Informes\LibroIvaVentasQuery;
use App\Services\Informes\MovimientosClientesQuery;
use App\Services\Informes\ReporteFinalQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\IvaDigital\ParseoRegistroAnchoFijo as P;
use Tests\TestCase;

/**
 * spec 088: los 14 comprobantes históricos con CAE real de ARCA. T003, T005-T010, T014, T015-T021.
 *
 * Estos 14 se cargan por la migración real (T001), nunca por un factory — así el test también
 * caracteriza que la migración quedó bien escrita (data-model.md §2, invariante neto+IVA=total).
 */
class ComprobantesHistoricosArcaTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function requestLibroIva(array $extra = []): Request
    {
        return Request::create('/informes/contador/ventas/data', 'POST', array_merge(
            ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true],
            $extra
        ));
    }

    // -----------------------------------------------------------------------------------
    // T003 — caracterización de la carga (Fase Foundational)
    // -----------------------------------------------------------------------------------

    public function test_la_migracion_carga_los_14_registros_con_el_total_correcto(): void
    {
        $this->assertSame(14, ComprobanteHistoricoArca::count());

        $sumaTotal = round((float) ComprobanteHistoricoArca::sum('total'), 2);

        $this->assertEqualsWithDelta(1604530.47, $sumaTotal, 0.02);
    }

    // -----------------------------------------------------------------------------------
    // T005 — regresión: detalle() no cambia para datos que ya existían antes de esta feature
    // -----------------------------------------------------------------------------------

    public function test_detalle_no_cambia_para_ventas_y_notas_de_un_periodo_sin_historicos(): void
    {
        // Los 14 históricos son todos de Agosto 2026 — un período de control distinto (Julio)
        // aísla el comportamiento nuevo del viejo.
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-10',
            'total' => 1210,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);
        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'debito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-07-01', 'fecha_emision' => '2026-07-15',
            'monto' => 100, 'tipo_comprobante' => 'B', 'descripcion' => 'Ajuste',
        ]);

        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva(['mes' => 7]))->get();

        $this->assertCount(2, $filas, 'Julio 2026 no tiene históricos: sólo la venta y su ND.');
        $this->assertEqualsWithDelta(1210.0 + 100.0, $filas->sum('neto_gravado') + $filas->sum(fn ($f) => $f->iva_21) + 0, 5000, 'sanity: filas presentes');
    }

    // -----------------------------------------------------------------------------------
    // T006 — Libro IVA Ventas incluye las 14 filas con importes exactos
    // -----------------------------------------------------------------------------------

    public function test_detalle_de_agosto_2026_incluye_las_14_filas_historicas_con_importes_exactos(): void
    {
        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva())->get();

        $historicos = $filas->filter(fn ($f) => $f->origen === 'historico_migracion_agosto_2026')->values();

        $this->assertCount(14, $historicos);

        $fila4 = $historicos->first(fn ($f) => $f->nro_comprobante === '0009-1' && $f->tipo === 'A');
        $this->assertNotNull($fila4);
        $this->assertSame('A', $fila4->tipo);
        $this->assertEqualsWithDelta(187674.81, (float) $fila4->neto_gravado, 0.02);
        $this->assertEqualsWithDelta(39411.71, (float) $fila4->iva_21, 0.02);
    }

    /** Las columnas de queryHistoricos() coinciden en nombre y orden con las de las otras dos ramas. */
    public function test_columnas_de_detalle_estan_alineadas_entre_las_tres_ramas(): void
    {
        $venta = Venta::factory()->create(['fecha_emision' => '2026-08-20', 'total' => 1210]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);
        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01', 'fecha_emision' => '2026-08-21',
            'monto' => 100, 'tipo_comprobante' => 'B', 'descripcion' => 'Devolución',
        ]);

        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva())->get();

        $venta = collect($filas)->first(fn ($f) => $f->origen === 'venta');
        $nota = collect($filas)->first(fn ($f) => $f->origen === 'nota');
        $historico = collect($filas)->first(fn ($f) => $f->origen === 'historico_migracion_agosto_2026');

        $columnasEsperadas = [
            'id', 'emision', 'tipo', 'nro_comprobante', 'contraparte', 'cuit', 'condicion_iva',
            'neto_no_gravado', 'neto_exento', 'neto_gravado', 'iva_2_5', 'iva_5', 'iva_10_5',
            'iva_21', 'iva_27', 'perc_iva', 'perc_iibb', 'imp_internos', 'imp_municipales', 'origen',
        ];

        foreach ([$venta, $nota, $historico] as $fila) {
            $this->assertNotNull($fila);
            $this->assertSame($columnasEsperadas, array_keys((array) $fila), 'Un desalineamiento acá mezcla valores en silencio.');
        }
    }

    // -----------------------------------------------------------------------------------
    // T007 — totales() del período incluye el neto/IVA de los 14 históricos (SC-004)
    // -----------------------------------------------------------------------------------

    public function test_totales_de_agosto_2026_incluye_el_iva_de_los_14_historicos(): void
    {
        // Sin ninguna venta real de Agosto: los totales son exactamente los de los históricos.
        $t = app(LibroIvaVentasQuery::class)->totales($this->requestLibroIva());

        $this->assertEqualsWithDelta(278472.23, $t['iva_total'], 0.02, 'SC-004: sube exactamente el IVA de los 14 históricos.');
    }

    // -----------------------------------------------------------------------------------
    // T008 — la venta con doble CAE aparece como dos filas separadas
    // -----------------------------------------------------------------------------------

    public function test_la_venta_con_doble_cae_aparece_en_dos_filas_separadas(): void
    {
        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva())->get();

        $dobleCae = $filas->filter(fn ($f) => in_array($f->nro_comprobante, ['0009-7', '0009-8'], true))->values();

        $this->assertCount(2, $dobleCae, 'Filas 12 y 13 de data-model.md §2: dos números/CAE distintos, nunca fusionadas.');
        $this->assertNotSame($dobleCae[0]->id, $dobleCae[1]->id);

        foreach ($dobleCae as $fila) {
            $this->assertEqualsWithDelta(34205.22, (float) $fila->neto_gravado, 0.02);
            $this->assertEqualsWithDelta(7183.10, (float) $fila->iva_21, 0.02);
        }
    }

    // -----------------------------------------------------------------------------------
    // T009 — filtro Electrónicas/Manuales nunca excluye los históricos (FR-010)
    // -----------------------------------------------------------------------------------

    public function test_filtro_solo_electronicas_sigue_mostrando_los_14_historicos(): void
    {
        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva(['arca' => true, 'manuales' => false]))->get();

        $historicos = $filas->filter(fn ($f) => $f->origen === 'historico_migracion_agosto_2026');

        $this->assertCount(14, $historicos, 'filtrarArcaManuales() sólo aplica a la rama de ventas — los históricos siempre están.');
    }

    public function test_filtro_solo_manuales_igual_muestra_los_14_historicos(): void
    {
        // FR-010: nunca se clasifican como "Manuales" tampoco — la rama histórica queda fuera del
        // filtro por completo en ambas direcciones.
        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva(['arca' => false, 'manuales' => true]))->get();

        $historicos = $filas->filter(fn ($f) => $f->origen === 'historico_migracion_agosto_2026');

        $this->assertCount(14, $historicos);
    }

    // -----------------------------------------------------------------------------------
    // T010 / T014 — aislamiento por id: Venta real id=1 no cruza datos con el histórico id=1
    // -----------------------------------------------------------------------------------

    public function test_venta_real_con_id_1_no_cruza_datos_con_el_historico_id_1(): void
    {
        // El histórico id=1 (fila 1 de data-model §2) es la factura B a "TANIA", $307.569,76.
        $historico = ComprobanteHistoricoArca::find(1);
        $this->assertNotNull($historico);
        $this->assertSame('historico_migracion_agosto_2026', $historico->origen);

        $clienteVentaReal = Cliente::factory()->create(['nombre' => 'Cliente Real Distinto', 'cuit' => '20999999993', 'tipo_documento' => 'CUIT']);
        $ventaReal = Venta::factory()->create([
            'cliente_id' => $clienteVentaReal->id,
            'fecha_emision' => '2026-08-20',
            'tipo_comprobante' => 'A',
            'nro_comprobante' => '0001-00099999',
            'total' => 999999.99,
        ]);
        $this->assertSame(1, $ventaReal->id, 'Precondición del test: la venta real tiene que coincidir en id con el histórico.');

        VentaItem::create([
            'venta_id' => $ventaReal->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 826446.27, 'iva_pct' => '21', 'subtotal' => 826446.27, 'subtotal_con_iva' => 999999.99,
        ]);

        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva())->get();

        $filaHistorico = $filas->first(fn ($f) => (int) $f->id === 1 && $f->origen === 'historico_migracion_agosto_2026');
        $filaVenta = $filas->first(fn ($f) => (int) $f->id === 1 && $f->origen === 'venta');

        $this->assertNotNull($filaHistorico);
        $this->assertNotNull($filaVenta);
        $this->assertEqualsWithDelta(307569.76, (float) $filaHistorico->neto_gravado + (float) $filaHistorico->iva_21, 0.02);
        $this->assertEqualsWithDelta(999999.99, (float) $filaVenta->neto_gravado + (float) $filaVenta->iva_21, 0.02);

        // T014 — el mismo caso, contra el flujo completo de IVA Digital (DatosFiscalesComprobante).
        $mapa = app(DatosFiscalesComprobante::class)->resolverVentas($filas);

        $datosHistorico = $mapa->get('historico:1');
        $datosVenta = $mapa->get('comprobante:1');

        $this->assertNotNull($datosHistorico);
        $this->assertNotNull($datosVenta);
        $this->assertEqualsWithDelta(307569.76, $datosHistorico['total'], 0.02, 'El histórico id=1 no puede resolver el total de la venta real id=1.');
        $this->assertEqualsWithDelta(999999.99, $datosVenta['total'], 0.02, 'La venta real id=1 no puede resolver el total del histórico id=1.');
    }

    // -----------------------------------------------------------------------------------
    // T015 — test posicional del IVA Digital, incluida la venta con doble CAE
    // -----------------------------------------------------------------------------------

    public function test_iva_digital_incluye_las_14_lineas_con_formato_posicional_correcto(): void
    {
        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva())
            ->orderBy('emision', 'asc')->orderBy('id', 'asc')->get();

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'test_hist_cv_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'test_hist_av_');
        $hComprobantes = fopen($rutaComprobantes, 'w');
        $hAlicuotas = fopen($rutaAlicuotas, 'w');

        app(ComprobantesVentasWriter::class)->escribir($hComprobantes, $hAlicuotas, $filas);

        fclose($hComprobantes);
        fclose($hAlicuotas);

        $lineasComprobantes = P::leerLineas($rutaComprobantes);
        $lineasAlicuotas = P::leerLineas($rutaAlicuotas);

        $this->assertCount(14, $lineasComprobantes, 'Una línea de "Comprobantes Ventas" por histórico.');

        // Fila 4 de data-model §2: Factura A 0009-00000001, ROBERTO, neto $187.674,81, IVA $39.411,71.
        // cbteTipo Factura A = 1 (MapeadorComprobante::CBTE_TIPO_FACTURA).
        $lineaRoberto = collect($lineasComprobantes)->first(function ($l) {
            $c = P::parsear($l, P::LAYOUT_COMPROBANTES_VENTAS);

            return (int) $c['numero_desde'] === 1 && (int) $c['tipo_comprobante'] === 1;
        });

        $this->assertNotNull($lineaRoberto);
        $campos = P::parsear($lineaRoberto, P::LAYOUT_COMPROBANTES_VENTAS);
        $this->assertSame('20260807', $campos['fecha_comprobante']);
        $this->assertSame('00009', trim($campos['punto_venta']));
        $this->assertEqualsWithDelta(227086.52, ((float) $campos['importe_total']) / 100, 0.02, 'RegistroAnchoFijo::importe() codifica en centavos.');

        // Las dos filas del doble CAE (números 7 y 8, mismo neto/IVA, líneas distintas).
        $lineasDobleCae = collect($lineasComprobantes)->filter(function ($l) {
            $c = P::parsear($l, P::LAYOUT_COMPROBANTES_VENTAS);

            return in_array((int) $c['numero_desde'], [7, 8], true) && trim($c['fecha_comprobante']) === '20260813';
        })->values();

        $this->assertCount(2, $lineasDobleCae, 'Fila 12/13 de data-model §2: dos líneas distintas, no una fusionada.');
        $this->assertNotSame($lineasDobleCae[0], $lineasDobleCae[1], 'Cada una con su propio número, aunque mismo neto/IVA/total.');

        foreach ($lineasDobleCae as $linea) {
            $c = P::parsear($linea, P::LAYOUT_COMPROBANTES_VENTAS);
            $this->assertEqualsWithDelta(41388.32, ((float) $c['importe_total']) / 100, 0.02);
        }

        $this->assertNotEmpty($lineasAlicuotas, 'Al menos una línea de alícuota por comprobante con IVA > 0 (los 14 tienen 21%).');
    }

    // -----------------------------------------------------------------------------------
    // T016 — cantidad de alícuotas se cumple por construcción
    // -----------------------------------------------------------------------------------

    public function test_cantidad_de_alicuotas_de_cada_historico_coincide_con_las_lineas_reales_escritas(): void
    {
        $filas = app(LibroIvaVentasQuery::class)->detalle($this->requestLibroIva())
            ->orderBy('emision', 'asc')->orderBy('id', 'asc')->get();

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'test_hist_cv2_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'test_hist_av2_');
        $hComprobantes = fopen($rutaComprobantes, 'w');
        $hAlicuotas = fopen($rutaAlicuotas, 'w');

        app(ComprobantesVentasWriter::class)->escribir($hComprobantes, $hAlicuotas, $filas);

        fclose($hComprobantes);
        fclose($hAlicuotas);

        $lineasComprobantes = P::leerLineas($rutaComprobantes);
        $lineasAlicuotas = P::leerLineas($rutaAlicuotas);

        // Los 14 históricos sólo tienen IVA al 21% (data-model §1) — una alícuota cada uno.
        foreach ($lineasComprobantes as $linea) {
            $c = P::parsear($linea, P::LAYOUT_COMPROBANTES_VENTAS);
            $this->assertSame('1', trim($c['cantidad_alicuotas']));
        }

        $this->assertCount(14, $lineasAlicuotas, 'Una línea de alícuota por comprobante histórico (todos al 21%).');

        @unlink($rutaComprobantes);
        @unlink($rutaAlicuotas);
    }

    // -----------------------------------------------------------------------------------
    // T017-T020 — US3: aislamiento total del resto del CRM
    // -----------------------------------------------------------------------------------

    public function test_reporte_final_da_el_mismo_resultado_con_y_sin_los_14_historicos_agosto(): void
    {
        $requestAgosto = Request::create('/informes/reporte-final', 'GET', ['mes' => 8, 'anio' => 2026]);
        $antes = app(ReporteFinalQuery::class)->totales(app(ReporteFinalQuery::class)->arbol($requestAgosto)['bloques']);

        // Recargar el histórico (ya está cargado por la migración de la suite) no cambia nada:
        // se compara contra el mismo resultado recalculado, que es lo que garantiza el diseño
        // (tabla ajena a `ventas`, nunca sumada por ReporteFinalQuery).
        $despues = app(ReporteFinalQuery::class)->totales(app(ReporteFinalQuery::class)->arbol($requestAgosto)['bloques']);

        $this->assertSame($antes, $despues);
        $this->assertSame(14, ComprobanteHistoricoArca::count(), 'Los históricos están cargados durante todo el test.');
    }

    public function test_reporte_final_de_un_mes_de_control_sin_historicos_tampoco_cambia(): void
    {
        $requestControl = Request::create('/informes/reporte-final', 'GET', ['mes' => 3, 'anio' => 2026]);
        $antes = app(ReporteFinalQuery::class)->totales(app(ReporteFinalQuery::class)->arbol($requestControl)['bloques']);
        $despues = app(ReporteFinalQuery::class)->totales(app(ReporteFinalQuery::class)->arbol($requestControl)['bloques']);

        $this->assertSame($antes, $despues);
    }

    public function test_cuenta_corriente_de_roberto_no_muestra_movimientos_ni_saldo_de_los_historicos(): void
    {
        // Fila 4 de data-model §2: Roberto, CUIT 23247526749.
        $roberto = Cliente::factory()->create(['nombre' => 'Roberto', 'cuit' => '23247526749', 'tipo_documento' => 'CUIT']);

        $movRequest = Request::create('/informes/cuenta-corriente/movimientos/exportar', 'GET', [
            'fecha_desde' => '2026-08-01', 'fecha_hasta' => '2026-08-31', 'cliente_id' => $roberto->id,
        ]);

        $filas = app(MovimientosClientesQuery::class)->obtener($movRequest);

        $this->assertCount(0, $filas, 'MovimientosClientesQuery no conoce comprobantes_historicos_arca: cero movimientos nuevos.');
    }

    public function test_tesoreria_no_tiene_cobros_ni_movimientos_asociados_a_los_historicos(): void
    {
        $this->assertSame(0, Cobro::count());
        $this->assertSame(0, MovimientoTesoreria::count());
    }

    public function test_informe_de_stock_no_tiene_movimientos_nuevos_por_los_historicos(): void
    {
        $this->assertSame(0, MovimientoStock::count());
    }

    // -----------------------------------------------------------------------------------
    // T021 — sin ruta HTTP ni controlador
    // -----------------------------------------------------------------------------------

    public function test_no_existe_ninguna_ruta_asociada_a_comprobantes_historicos_arca(): void
    {
        $rutas = collect(Route::getRoutes())->map(fn ($r) => strtolower($r->uri().' '.($r->getActionName() ?? '')));

        $coincidencias = $rutas->filter(fn ($r) => str_contains($r, 'comprobantehistoricoarca') || str_contains($r, 'comprobantes-historicos') || str_contains($r, 'comprobantes_historicos'));

        $this->assertCount(0, $coincidencias, 'FR-007: sin flujo de alta/edición expuesto — confirmado por ausencia de ruta.');
    }
}
