<?php

namespace Tests\Feature\Informes;

use App\Models\Categoria;
use App\Models\Gasto;
use App\Services\Informes\ReporteFinalQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 068 US3 — el simulador "Activo" del Reporte Final (FR-034, FR-006).
 *
 * El recálculo en sí ocurre en el cliente y sin red; lo que se puede fijar del lado del servidor
 * —y es lo que importa para el dinero— es que el **archivo exportado refleje el escenario
 * simulado** y no el total sin simular, y que destildar nunca toque los datos reales.
 */
class ReporteFinalSimuladorTest extends TestCase
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

    /** Dos categorías de venta con montos distintos, para poder excluir una sola. */
    private function escenario(): array
    {
        $online = Categoria::factory()->create(['tipo' => 'venta', 'nombre' => 'Online']);
        $mostrador = Categoria::factory()->create(['tipo' => 'venta', 'nombre' => 'Mostrador']);

        $this->venta([['cantidad' => 1, 'precio' => 1893.65]], ['categoria_id' => $online->id]);
        $this->venta([['cantidad' => 1, 'precio' => 1000]], ['categoria_id' => $mostrador->id]);
        Gasto::factory()->create(['fecha' => '2026-08-04', 'monto' => 500]);

        return ['online' => $online, 'mostrador' => $mostrador];
    }

    public function test_excluir_una_categoria_baja_su_bloque_el_total_y_el_resultado_en_ese_monto(): void
    {
        $cats = $this->escenario();
        $clave = 'ventas|'.$cats['online']->id;

        $sin = $this->pedir();
        $con = $this->pedir(['excluidas' => [$clave]]);

        $this->assertEqualsWithDelta(2893.65, $sin['totales']['ingresos'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $con['totales']['ingresos'], 0.01);
        // El resultado baja exactamente en el monto excluido, ni más ni menos.
        $this->assertEqualsWithDelta(
            $sin['totales']['resultado'] - 1893.65,
            $con['totales']['resultado'],
            0.01
        );
    }

    public function test_el_subtotal_del_bloque_tambien_descuenta_la_categoria_excluida(): void
    {
        $cats = $this->escenario();
        $arbol = $this->pedir();
        $bloque = collect($arbol['bloques'])->firstWhere('clave', 'ventas');

        $this->assertEqualsWithDelta(2893.65, $this->informe()->totalBloque($bloque), 0.01);
        $this->assertEqualsWithDelta(
            1000.0,
            $this->informe()->totalBloque($bloque, ['ventas|'.$cats['online']->id]),
            0.01
        );
    }

    public function test_excluir_no_altera_los_datos_reales(): void
    {
        $cats = $this->escenario();

        $this->pedir(['excluidas' => ['ventas|'.$cats['online']->id]]);

        // La simulación es de lectura: el árbol sin excluir sigue dando lo mismo que antes.
        $this->assertEqualsWithDelta(2893.65, $this->pedir()['totales']['ingresos'], 0.01);
        $this->assertDatabaseCount('ventas', 2);
    }

    public function test_con_todas_las_categorias_excluidas_los_totales_quedan_en_cero_sin_romper(): void
    {
        $this->escenario();
        $arbol = $this->pedir();

        $todas = [];
        foreach ($arbol['bloques'] as $bloque) {
            foreach ($bloque['categorias'] as $categoria) {
                $todas[] = $categoria['clave'];
            }
        }

        $totales = $this->pedir(['excluidas' => $todas])['totales'];

        $this->assertSame(0.0, $totales['ingresos']);
        $this->assertSame(0.0, $totales['egresos']);
        $this->assertSame(0.0, $totales['resultado']);
    }

    public function test_el_excel_se_genera_sobre_el_escenario_simulado(): void
    {
        $cats = $this->escenario();

        // El archivo tiene que reflejar lo que el usuario está viendo: sería desconcertante que
        // contradiga la pantalla (Clarifications).
        $respuesta = $this->get(route('informes.reporte-final.exportar', [
            'excluidas' => ['ventas|'.$cats['online']->id],
        ]));

        $respuesta->assertOk();
        $this->assertStringContainsString('spreadsheetml', $respuesta->headers->get('content-type'));
    }

    public function test_el_pdf_respeta_las_categorias_excluidas(): void
    {
        $cats = $this->escenario();

        $respuesta = $this->get(route('informes.reporte-final.pdf', [
            'excluidas' => ['ventas|'.$cats['online']->id],
        ]));

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('content-type'));
        // `inline`, no `attachment`: lo tiene que poder renderizar el <iframe> del modal (regla #4).
        $this->assertStringContainsString('inline', (string) $respuesta->headers->get('content-disposition'));
    }
}
