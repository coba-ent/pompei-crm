<?php

namespace Tests\Feature\Informes;

use App\Models\Categoria;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\OtroIngreso;
use App\Models\Pago;
use App\Services\Informes\ReporteFinalQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 068 US3 — las dos lecturas del Reporte Final.
 *
 * Lo que este test protege es la diferencia entre las dos bases: qué entra en devengado y no en
 * caja, y por qué fecha se imputa cada cosa. Es todo dinero (constitución IV).
 */
class ReporteFinalTest extends TestCase
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

    private function informe(): ReporteFinalQuery
    {
        return app(ReporteFinalQuery::class);
    }

    private function pedir(array $params = []): array
    {
        return $this->informe()->arbol(Request::create('/informes/reporte-final', 'GET', $params));
    }

    /** @return array<string, mixed>|null */
    private function bloque(array $arbol, string $clave): ?array
    {
        foreach ($arbol['bloques'] as $bloque) {
            if ($bloque['clave'] === $clave) {
                return $bloque;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------------------
    // Jerarquía y bloques
    // -----------------------------------------------------------------------------------

    public function test_la_vista_devengado_arma_los_cuatro_bloques_con_su_naturaleza(): void
    {
        $arbol = $this->pedir();

        $this->assertSame('devengado', $arbol['vista']);
        $this->assertSame(
            ['ventas', 'otros_ingresos', 'compras', 'gastos'],
            array_column($arbol['bloques'], 'clave')
        );
        $this->assertSame('ingreso', $this->bloque($arbol, 'ventas')['naturaleza']);
        $this->assertSame('egreso', $this->bloque($arbol, 'gastos')['naturaleza']);
    }

    public function test_la_vista_devengado_agrupa_las_ventas_por_categoria_e_incluye_sus_notas(): void
    {
        $categoria = Categoria::factory()->create(['tipo' => 'venta', 'nombre' => 'Online']);
        $venta = $this->venta([['cantidad' => 1, 'precio' => 2000]], ['categoria_id' => $categoria->id]);
        $this->nota($venta, 'credito', [['cantidad' => 1, 'precio' => 106.35]]);

        $bloque = $this->bloque($this->pedir(), 'ventas');

        $this->assertCount(1, $bloque['categorias']);
        $this->assertSame('Online', $bloque['categorias'][0]['etiqueta']);
        $this->assertEqualsWithDelta(1893.65, $bloque['categorias'][0]['monto'], 0.01);
    }

    public function test_una_venta_sin_categoria_cae_en_el_rotulo_de_fallback(): void
    {
        // `ventas.categoria_id` es nullable: acá "Sin categoría" es un caso real, no una red.
        $this->venta([['cantidad' => 1, 'precio' => 500]], ['categoria_id' => null]);

        $bloque = $this->bloque($this->pedir(), 'ventas');

        $this->assertSame(ReporteFinalQuery::SIN_CATEGORIA, $bloque['categorias'][0]['etiqueta']);
        $this->assertNull($bloque['categorias'][0]['id']);
        // La categoría sin id igual tiene una clave propia, o sería inexcluible en el simulador.
        $this->assertSame('ventas|sin', $bloque['categorias'][0]['clave']);
    }

    public function test_los_gastos_se_abren_por_categoria_y_subcategoria(): void
    {
        $raiz = Categoria::factory()->create(['tipo' => 'gasto', 'nombre' => 'Oficina']);
        $hija = Categoria::factory()->create(['tipo' => 'gasto', 'nombre' => 'Luz', 'categoria_padre_id' => $raiz->id]);

        Gasto::factory()->create(['fecha' => '2026-08-05', 'monto' => 300, 'categoria_id' => $hija->id]);
        Gasto::factory()->create(['fecha' => '2026-08-06', 'monto' => 200, 'categoria_id' => $raiz->id]);

        $bloque = $this->bloque($this->pedir(), 'gastos');

        $this->assertCount(1, $bloque['categorias']);
        $this->assertSame('Oficina', $bloque['categorias'][0]['etiqueta']);
        $this->assertEqualsWithDelta(500.0, $bloque['categorias'][0]['monto'], 0.01);

        $subs = collect($bloque['categorias'][0]['hijos'])->pluck('monto', 'etiqueta');
        $this->assertEqualsWithDelta(300.0, $subs['Luz'], 0.01);
        $this->assertEqualsWithDelta(200.0, $subs[ReporteFinalQuery::SIN_SUBCATEGORIA], 0.01);
    }

    // -----------------------------------------------------------------------------------
    // Devengado vs. caja
    // -----------------------------------------------------------------------------------

    public function test_un_gasto_pendiente_entra_en_devengado_y_no_en_caja(): void
    {
        Gasto::factory()->create(['fecha' => '2026-08-05', 'monto' => 400, 'pendiente' => true]);

        $this->assertEqualsWithDelta(400.0, $this->bloque($this->pedir(), 'gastos')['total'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->bloque($this->pedir(['vista' => 'caja']), 'gastos')['total'], 0.01);
    }

    public function test_la_vista_caja_toma_lo_cobrado_y_no_lo_facturado(): void
    {
        $cuenta = CuentaTesoreria::factory()->create(['nombre' => 'Efectivo']);
        $categoria = Categoria::factory()->create(['tipo' => 'venta', 'nombre' => 'Mostrador']);

        $venta = $this->venta([['cantidad' => 1, 'precio' => 1000]], ['categoria_id' => $categoria->id]);
        Cobro::factory()->create([
            'venta_id' => $venta->id, 'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2026-08-11', 'monto' => 400,
        ]);

        $devengado = $this->bloque($this->pedir(), 'ventas');
        $caja = $this->bloque($this->pedir(['vista' => 'caja']), 'ventas');

        $this->assertEqualsWithDelta(1000.0, $devengado['total'], 0.01);
        $this->assertEqualsWithDelta(400.0, $caja['total'], 0.01);
        $this->assertSame('Ventas Cobradas', $caja['etiqueta']);
    }

    /**
     * La distinción central entre las dos bases (FR-037b): el cobro se imputa por **su** fecha, y
     * la categoría con la que se agrupa sigue siendo la de la venta que lo origina.
     */
    public function test_el_cobro_se_imputa_por_su_propia_fecha_y_no_por_la_de_la_venta(): void
    {
        $cuenta = CuentaTesoreria::factory()->create();
        $categoria = Categoria::factory()->create(['tipo' => 'venta', 'nombre' => 'Mostrador']);

        // Venta de julio, cobrada en agosto.
        $venta = $this->venta([['cantidad' => 1, 'precio' => 900]], [
            'fecha_emision' => '2026-07-20', 'categoria_id' => $categoria->id,
        ]);
        Cobro::factory()->create([
            'venta_id' => $venta->id, 'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2026-08-03', 'monto' => 900,
        ]);

        $devengado = $this->bloque($this->pedir(), 'ventas');
        $caja = $this->bloque($this->pedir(['vista' => 'caja']), 'ventas');

        $this->assertEqualsWithDelta(0.0, $devengado['total'], 0.01);
        $this->assertEqualsWithDelta(900.0, $caja['total'], 0.01);
        $this->assertSame('Mostrador', $caja['categorias'][0]['etiqueta']);
    }

    public function test_los_pagos_de_compras_arman_el_bloque_de_egresos_de_la_vista_caja(): void
    {
        $cuenta = CuentaTesoreria::factory()->create();
        $categoria = Categoria::factory()->create(['tipo' => 'compra', 'nombre' => 'Mercadería']);

        $compra = Compra::factory()->create(['fecha_emision' => '2026-08-02', 'categoria_id' => $categoria->id, 'total' => 700]);
        Pago::factory()->create([
            'compra_id' => $compra->id, 'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2026-08-09', 'monto' => 250,
        ]);

        $this->assertEqualsWithDelta(700.0, $this->bloque($this->pedir(), 'compras')['total'], 0.01);

        $caja = $this->bloque($this->pedir(['vista' => 'caja']), 'compras');
        $this->assertSame('Compras Pagadas', $caja['etiqueta']);
        $this->assertEqualsWithDelta(250.0, $caja['total'], 0.01);
    }

    /** SC-006: la diferencia entre las dos lecturas es exactamente lo no cobrado / no pagado. */
    public function test_los_ingresos_de_caja_nunca_superan_a_los_de_devengado_en_el_mismo_periodo(): void
    {
        $cuenta = CuentaTesoreria::factory()->create();
        $venta = $this->venta([['cantidad' => 1, 'precio' => 1000]]);
        Cobro::factory()->create([
            'venta_id' => $venta->id, 'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2026-08-12', 'monto' => 600,
        ]);

        $devengado = $this->pedir()['totales']['ingresos'];
        $caja = $this->pedir(['vista' => 'caja'])['totales']['ingresos'];

        $this->assertLessThanOrEqual($devengado, $caja);
        $this->assertEqualsWithDelta(400.0, $devengado - $caja, 0.01);
    }

    // -----------------------------------------------------------------------------------
    // Cuentas de tesorería
    // -----------------------------------------------------------------------------------

    public function test_lista_las_cuentas_visibles_aunque_su_monto_sea_cero(): void
    {
        $usada = CuentaTesoreria::factory()->create(['nombre' => 'Efectivo', 'visible' => true]);
        CuentaTesoreria::factory()->create(['nombre' => 'Banco Nación', 'visible' => true]);
        CuentaTesoreria::factory()->create(['nombre' => 'Cuenta oculta', 'visible' => false]);

        $venta = $this->venta([['cantidad' => 1, 'precio' => 500]]);
        Cobro::factory()->create([
            'venta_id' => $venta->id, 'cuenta_tesoreria_id' => $usada->id,
            'fecha' => '2026-08-10', 'monto' => 500,
        ]);

        $cuentas = collect($this->bloque($this->pedir(['vista' => 'caja']), 'ventas')['categorias'][0]['hijos'])
            ->pluck('monto', 'etiqueta');

        $this->assertEqualsWithDelta(500.0, $cuentas['Efectivo'], 0.01);
        // Visible sin movimiento: aparece en $0,00, como hace Contagram (FR-038).
        $this->assertEqualsWithDelta(0.0, $cuentas['Banco Nación'], 0.01);
        // No visible y sin movimiento: no aparece.
        $this->assertArrayNotHasKey('Cuenta oculta', $cuentas->all());
    }

    public function test_una_cuenta_no_visible_con_movimientos_se_lista_igual(): void
    {
        // Ningún importe puede quedar escondido detrás de una cuenta oculta.
        $oculta = CuentaTesoreria::factory()->create(['nombre' => 'Caja vieja', 'visible' => false]);

        $venta = $this->venta([['cantidad' => 1, 'precio' => 320]]);
        Cobro::factory()->create([
            'venta_id' => $venta->id, 'cuenta_tesoreria_id' => $oculta->id,
            'fecha' => '2026-08-10', 'monto' => 320,
        ]);

        $cuentas = collect($this->bloque($this->pedir(['vista' => 'caja']), 'ventas')['categorias'][0]['hijos'])
            ->pluck('monto', 'etiqueta');

        $this->assertEqualsWithDelta(320.0, $cuentas['Caja vieja'], 0.01);
    }

    // -----------------------------------------------------------------------------------
    // Totales, bajas y período vacío
    // -----------------------------------------------------------------------------------

    public function test_en_pantalla_el_resultado_resta_los_egresos_en_las_dos_vistas(): void
    {
        $this->venta([['cantidad' => 1, 'precio' => 1000]]);
        Gasto::factory()->create(['fecha' => '2026-08-04', 'monto' => 250]);

        foreach (['devengado', 'caja'] as $vista) {
            $totales = $this->pedir(['vista' => $vista])['totales'];

            // Egresos SIEMPRE en positivo en pantalla (FR-035).
            $this->assertGreaterThanOrEqual(0, $totales['egresos']);
            $this->assertEqualsWithDelta(
                $totales['ingresos'] - $totales['egresos'],
                $totales['resultado'],
                0.01
            );
        }
    }

    public function test_los_otros_ingresos_suman_del_lado_de_los_ingresos(): void
    {
        OtroIngreso::factory()->create(['fecha' => '2026-08-07', 'monto' => 150]);

        $bloque = $this->bloque($this->pedir(), 'otros_ingresos');

        $this->assertSame('ingreso', $bloque['naturaleza']);
        $this->assertEqualsWithDelta(150.0, $bloque['total'], 0.01);
    }

    public function test_los_registros_dados_de_baja_no_computan(): void
    {
        Gasto::factory()->create(['fecha' => '2026-08-05', 'monto' => 100]);
        Gasto::factory()->create(['fecha' => '2026-08-05', 'monto' => 900])->delete();

        $this->assertEqualsWithDelta(100.0, $this->pedir()['totales']['egresos'], 0.01);
    }

    public function test_un_periodo_sin_movimientos_devuelve_ceros_y_ningun_bloque_con_categorias(): void
    {
        $arbol = $this->pedir(['desde' => '2020-01-01', 'hasta' => '2020-01-31']);

        $this->assertSame(0.0, $arbol['totales']['ingresos']);
        $this->assertSame(0.0, $arbol['totales']['egresos']);
        $this->assertSame(0.0, $arbol['totales']['resultado']);

        foreach ($arbol['bloques'] as $bloque) {
            $this->assertSame([], $bloque['categorias']);
        }
    }

    public function test_una_vista_desconocida_cae_en_devengado(): void
    {
        $this->assertSame('devengado', $this->pedir(['vista' => 'inventada'])['vista']);
    }

    public function test_el_rango_dado_vuelta_responde_422(): void
    {
        $this->getJson(route('informes.reporte-final.data', ['desde' => '2026-08-20', 'hasta' => '2026-08-01']))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }
}
