<?php

namespace Tests\Feature\Informes;

use App\Models\Categoria;
use App\Models\Gasto;
use App\Services\Informes\GastosInformeQuery;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** spec 067 US2 — total, jerarquía y subtotales del Informe de Gastos. */
class InformeGastosTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

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

    private function informe(): GastosInformeQuery
    {
        return app(GastosInformeQuery::class);
    }

    private function request(array $params = []): Request
    {
        return Request::create('/informes/gastos', 'GET', $params);
    }

    private function categoria(string $nombre, ?Categoria $padre = null): Categoria
    {
        return Categoria::create([
            'tipo' => 'gasto',
            'nombre' => $nombre,
            'categoria_padre_id' => $padre?->id,
            'activo' => true,
        ]);
    }

    private function gasto(float $monto, ?Categoria $categoria, array $extra = []): Gasto
    {
        return Gasto::factory()->create(array_merge([
            'fecha' => '2026-08-10',
            'monto' => $monto,
            'categoria_id' => $categoria?->id,
        ], $extra));
    }

    public function test_suma_de_subtotales_igual_al_total(): void
    {
        $impuestos = $this->categoria('Impuestos');
        $iva = $this->categoria('IVA', $impuestos);
        $oficina = $this->categoria('Oficina');
        $luz = $this->categoria('Luz', $oficina);

        $this->gasto(870, $iva);
        $this->gasto(300, $luz);
        $this->gasto(200, $oficina);

        $stats = $this->informe()->subtotales($this->request());

        $this->assertSame(1370.0, $stats['gasto_total']);
        $this->assertSame(
            $stats['gasto_total'],
            round(array_sum(array_column($stats['grupos'], 'subtotal')), 2),
            'Σ subtotales de Categoría tiene que ser el Gasto Total (FR-026).'
        );

        foreach ($stats['grupos'] as $grupo) {
            $this->assertSame(
                $grupo['subtotal'],
                round(array_sum(array_column($grupo['subcategorias'], 'subtotal')), 2),
                'Σ subcategorías tiene que ser el subtotal de su categoría.'
            );
        }
    }

    public function test_gasto_sin_subcategoria_no_desaparece(): void
    {
        $oficina = $this->categoria('Oficina');
        $this->gasto(200, $oficina);

        $stats = $this->informe()->subtotales($this->request());

        $this->assertSame(200.0, $stats['gasto_total']);
        $this->assertSame('Oficina', $stats['grupos'][0]['categoria']);
        $this->assertSame(GastosInformeQuery::SIN_SUBCATEGORIA, $stats['grupos'][0]['subcategorias'][0]['subcategoria']);
        $this->assertCount(1, $this->informe()->detalle($this->request())->get());
    }

    /**
     * `data-model.md` §3 contempla un gasto **sin categoría** cayendo en "Sin categoría".
     * Medido contra el esquema real, ese caso **no puede existir**: `gastos.categoria_id` es
     * `NOT NULL` con `restrictOnDelete` (migración `2026_07_31_070003_create_gastos_table`), así
     * que todo gasto tiene categoría y ninguna categoría usada se puede borrar.
     *
     * El rótulo se mantiene igual como red de contención —si la columna se volviera nullable, el
     * gasto seguiría apareciendo en vez de desaparecer del informe—, y este test fija las dos
     * mitades: que la base impide el caso, y que el rótulo sigue existiendo para cubrirlo.
     */
    public function test_todo_gasto_tiene_categoria_por_esquema(): void
    {
        $this->expectException(QueryException::class);

        $this->gasto(150, null);
    }

    public function test_el_rotulo_sin_categoria_sigue_disponible_como_red_de_contencion(): void
    {
        $this->assertSame('Sin categoría', GastosInformeQuery::SIN_CATEGORIA);
        $this->assertSame('Sin subcategoría', GastosInformeQuery::SIN_SUBCATEGORIA);
    }

    /**
     * Los subtotales se calculan sobre el conjunto filtrado completo. Si dependieran de la página
     * visible, pasar a la página 2 cambiaría los números de la misma categoría.
     */
    public function test_subtotales_no_dependen_de_la_pagina(): void
    {
        $oficina = $this->categoria('Oficina');

        for ($i = 0; $i < 25; $i++) {
            $this->gasto(100, $oficina);
        }

        $esperado = $this->informe()->subtotales($this->request());
        $this->assertSame(2500.0, $esperado['gasto_total']);

        $pagina2 = $this->getJson(route('informes.gastos.stats', ['start' => 10, 'length' => 10, 'draw' => 2]))
            ->assertOk()->json();

        $this->assertEquals($esperado['gasto_total'], $pagina2['gasto_total']);

        $detalle = $this->getJson(route('informes.gastos.data', ['start' => 10, 'length' => 10, 'draw' => 2]))
            ->assertOk()->json();

        $this->assertCount(10, $detalle['data'], 'La paginación sí acota el detalle...');
        $this->assertSame(25, $detalle['recordsTotal'], '...pero no el universo informado.');
    }

    public function test_filtro_estado_pago_pendiente_restringe_total_y_detalle(): void
    {
        $oficina = $this->categoria('Oficina');
        $this->gasto(300, $oficina, ['pendiente' => false]);
        $this->gasto(700, $oficina, ['pendiente' => true, 'cuenta_tesoreria_id' => null]);

        $pendientes = $this->informe()->subtotales($this->request(['estado_pago' => 'pendiente']));
        $pagados = $this->informe()->subtotales($this->request(['estado_pago' => 'pagado']));

        $this->assertSame(700.0, $pendientes['gasto_total']);
        $this->assertSame(300.0, $pagados['gasto_total']);
        $this->assertCount(1, $this->informe()->detalle($this->request(['estado_pago' => 'pendiente']))->get());
    }

    public function test_filtro_de_categoria_incluye_sus_subcategorias(): void
    {
        $oficina = $this->categoria('Oficina');
        $luz = $this->categoria('Luz', $oficina);
        $otra = $this->categoria('Impuestos');

        $this->gasto(300, $luz);
        $this->gasto(200, $oficina);
        $this->gasto(999, $otra);

        // Elegir "Oficina" tiene que traer también lo imputado a "Oficina → Luz": es como se lo
        // ve agrupado en pantalla.
        $stats = $this->informe()->subtotales($this->request(['categoria_id' => [$oficina->id]]));

        $this->assertSame(500.0, $stats['gasto_total']);
    }

    public function test_gasto_eliminado_no_aparece_ni_suma(): void
    {
        $oficina = $this->categoria('Oficina');
        $this->gasto(300, $oficina);
        $this->gasto(999, $oficina)->delete();

        $this->assertSame(300.0, $this->informe()->subtotales($this->request())['gasto_total']);
        $this->assertCount(1, $this->informe()->detalle($this->request())->get());
    }

    /**
     * Detalle de una subcategoría concreta: es lo que se pide al desplegarla en pantalla.
     * El árbol se dibuja colapsado, así que sólo llega lo del grupo abierto.
     */
    public function test_el_detalle_de_un_grupo_trae_solo_las_filas_de_esa_subcategoria(): void
    {
        $oficina = $this->categoria('Oficina');
        $luz = $this->categoria('Luz', $oficina);
        $gas = $this->categoria('Gas', $oficina);

        $deLuz = $this->gasto(300, $luz);
        $this->gasto(700, $gas);

        $filas = $this->getJson(route('informes.gastos.grupo', [
            'categoria' => 'Oficina', 'subcategoria' => 'Luz',
        ]))->assertOk()->json('filas');

        $this->assertCount(1, $filas);
        $this->assertEquals($deLuz->id, $filas[0]['id']);
        $this->assertEquals(300.0, $filas[0]['total']);
    }

    /**
     * Un gasto imputado a una categoría raíz cae bajo el rótulo "Sin subcategoría", y ese
     * grupo tiene que poder desplegarse igual que los demás: es un rótulo resuelto en SQL,
     * no una categoría con id.
     */
    public function test_el_grupo_sin_subcategoria_tambien_se_puede_desplegar(): void
    {
        $oficina = $this->categoria('Oficina');
        $gasto = $this->gasto(250, $oficina);

        $filas = $this->getJson(route('informes.gastos.grupo', [
            'categoria' => 'Oficina', 'subcategoria' => GastosInformeQuery::SIN_SUBCATEGORIA,
        ]))->assertOk()->json('filas');

        $this->assertCount(1, $filas);
        $this->assertEquals($gasto->id, $filas[0]['id']);
    }

    public function test_el_detalle_de_un_grupo_respeta_los_filtros_del_informe(): void
    {
        $oficina = $this->categoria('Oficina');
        $luz = $this->categoria('Luz', $oficina);

        $this->gasto(300, $luz, ['pendiente' => false]);
        $this->gasto(700, $luz, ['pendiente' => true, 'cuenta_tesoreria_id' => null]);

        $filas = $this->getJson(route('informes.gastos.grupo', [
            'categoria' => 'Oficina', 'subcategoria' => 'Luz', 'estado_pago' => 'pendiente',
        ]))->assertOk()->json('filas');

        $this->assertCount(1, $filas);
        $this->assertEquals(700.0, $filas[0]['total']);
    }

    public function test_el_detalle_de_un_grupo_exige_categoria_y_subcategoria(): void
    {
        $this->getJson(route('informes.gastos.grupo', ['categoria' => 'Oficina']))
            ->assertStatus(422);
    }

    public function test_periodo_sin_datos_devuelve_total_cero_y_sin_grupos(): void
    {
        $stats = $this->getJson(route('informes.gastos.stats', [
            'fecha_desde' => '2026-01-01', 'fecha_hasta' => '2026-01-31',
        ]))->assertOk()->json();

        $this->assertEquals(0, $stats['gasto_total']);
        $this->assertSame([], $stats['grupos']);
    }

    public function test_rango_invertido_devuelve_422(): void
    {
        $this->getJson(route('informes.gastos.data', [
            'fecha_desde' => '2026-08-31', 'fecha_hasta' => '2026-08-01',
        ]))->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_el_detalle_plano_llega_ordenado_por_categoria(): void
    {
        $oficina = $this->categoria('Oficina');
        $luz = $this->categoria('Luz', $oficina);
        $impuestos = $this->categoria('Impuestos');

        $this->gasto(100, $luz, ['fecha' => '2026-08-05']);
        $this->gasto(200, $impuestos, ['fecha' => '2026-08-03']);
        $this->gasto(300, $luz, ['fecha' => '2026-08-01']);

        $data = $this->getJson(route('informes.gastos.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
            ->assertOk()->json('data');

        // El export y el PDF agrupan recorriendo el detalle en orden: si no viniera ordenado
        // desde el servidor, "Oficina" aparecería partida en dos bloques.
        $categorias = array_column($data, 'categoria');
        $ordenadas = $categorias;
        sort($ordenadas);
        $this->assertSame($ordenadas, $categorias);
    }
}
