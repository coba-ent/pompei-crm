<?php

namespace Tests\Feature\Informes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * spec 067 — acceso y permisos de los tres informes de la tanda 1.
 *
 * Las tres pantallas y todos sus endpoints están detrás de `informes.ver`: sin ese permiso no
 * se abren ni se listan en el sidebar.
 */
class InformesAccesoTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    /** @return list<array{string}> */
    public static function rutasProtegidas(): array
    {
        return [
            ['informes.compras.index'],
            ['informes.compras.data'],
            ['informes.compras.stats'],
            ['informes.gastos.index'],
            ['informes.gastos.data'],
            ['informes.gastos.stats'],
            ['informes.cuenta-corriente-proveedores.index'],
            ['informes.cuenta-corriente-proveedores.saldos.data'],
            ['informes.cuenta-corriente-proveedores.movimientos.data'],

            // Tanda 2 (spec 068). Se cubren también los endpoints de export y PDF: sin permiso
            // no puede salir un archivo con los importes que la pantalla no deja ver (FR-007).
            ['informes.ventas.index'],
            ['informes.ventas.data'],
            ['informes.ventas.stats'],
            ['informes.ventas.exportar'],
            ['informes.ventas.pdf'],
            ['informes.reporte-final.index'],
            ['informes.reporte-final.data'],
            ['informes.reporte-final.exportar'],
            ['informes.reporte-final.pdf'],
        ];
    }

    #[DataProvider('rutasProtegidas')]
    public function test_sin_permiso_recibe_403(string $ruta): void
    {
        $this->get(route($ruta))->assertForbidden();
    }

    #[DataProvider('rutasProtegidas')]
    public function test_con_permiso_responde_200(string $ruta): void
    {
        $this->autenticarConPermisoInformes();

        $this->get(route($ruta, ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
    }

    /**
     * Rutas del motor de tablas dinámicas (spec 069, tanda 3).
     *
     * Van aparte del proveedor de arriba porque no son todas GET: las vistas guardadas son el
     * único punto de escritura del módulo, y un POST/DELETE sin permiso tiene que dar 403 igual
     * que una pantalla.
     *
     * @return list<array{string, string}>
     */
    public static function rutasPivotProtegidas(): array
    {
        return [
            ['get', 'informes.ventas.pivot.dataset'],
            ['get', 'informes.ventas.pivot.vistas.index'],
            ['post', 'informes.ventas.pivot.vistas.store'],
            ['post', 'informes.ventas.pivot.exportar'],
            ['get', 'informes.compras.pivot.dataset'],
            ['get', 'informes.compras.pivot.vistas.index'],
            ['post', 'informes.compras.pivot.vistas.store'],
            ['post', 'informes.compras.pivot.exportar'],
        ];
    }

    #[DataProvider('rutasPivotProtegidas')]
    public function test_pivot_sin_permiso_recibe_403(string $metodo, string $ruta): void
    {
        $this->{$metodo.'Json'}(route($ruta))->assertForbidden();
    }

    public function test_borrar_una_vista_existente_sin_permiso_recibe_403(): void
    {
        // Con una vista REAL, no con un id inventado: `SubstituteBindings` corre antes que el
        // middleware de permiso, así que un id inexistente devuelve 404 y no probaría nada de
        // autorización. Lo que importa es que un usuario sin permiso no pueda borrar una que sí
        // existe — y que la vista siga ahí después del intento.
        $vista = \App\Models\InformeVista::create([
            'informe' => 'ventas',
            'descripcion' => 'No la puede borrar cualquiera',
            'config' => ['filas' => ['clientes'], 'columnas' => [], 'dato' => 'total_venta', 'accion' => 'suma', 'exclusiones' => []],
        ]);

        $this->deleteJson(route('informes.ventas.pivot.vistas.destroy', $vista))->assertForbidden();

        $this->assertDatabaseHas('informes_vistas', ['id' => $vista->id]);
    }

    /**
     * Entrada directa por URL a un ranking o a una vista guardada (research R6): sin permiso, 403.
     */
    public function test_las_urls_directas_de_ranking_y_vista_tambien_estan_protegidas(): void
    {
        $this->get(route('informes.ventas.ranking.show', 'clientes'))->assertForbidden();
        $this->get(route('informes.compras.ranking.show', 'proveedores'))->assertForbidden();
        $this->get(route('informes.ventas.vista.show', 1))->assertForbidden();
        $this->get(route('informes.compras.vista.show', 1))->assertForbidden();
    }

    /**
     * El sidebar se ejercita renderizando el parcial y no cargando otra pantalla completa: toda
     * pantalla del CRM exige su propio permiso, así que un usuario sin ninguno no puede abrir
     * ninguna, y el Dashboard —que sería la candidata natural— llama a `CuentaCorriente::aging()`,
     * que usa `DATEDIFF` y no corre en la SQLite de los tests (limitación previa a esta spec, en
     * un servicio que la spec tiene prohibido tocar — research R7).
     */
    private function sidebar(): string
    {
        return view('elements.sidebar', ['CurrentPage' => ''])->render();
    }

    public function test_el_sidebar_no_muestra_las_entradas_sin_permiso(): void
    {
        $html = $this->sidebar();

        $this->assertStringNotContainsString(route('informes.compras.index'), $html);
        $this->assertStringNotContainsString(route('informes.gastos.index'), $html);
        $this->assertStringNotContainsString('Cuenta Corriente Proveedores', $html);
    }

    public function test_el_sidebar_muestra_las_tres_entradas_nuevas_con_permiso(): void
    {
        $this->autenticarConPermisoInformes();

        $html = $this->sidebar();

        $this->assertStringContainsString(route('informes.compras.index'), $html);
        $this->assertStringContainsString(route('informes.gastos.index'), $html);
        $this->assertStringContainsString(route('informes.cuenta-corriente-proveedores.index'), $html);
        // La entrada existente se renombró para poder distinguirla de la de proveedores.
        $this->assertStringContainsString('Cuenta Corriente Clientes', $html);
    }

    public function test_el_sidebar_no_muestra_las_entradas_de_la_tanda_2_sin_permiso(): void
    {
        $html = $this->sidebar();

        $this->assertStringNotContainsString(route('informes.ventas.index'), $html);
        $this->assertStringNotContainsString(route('informes.reporte-final.index'), $html);
    }

    public function test_el_sidebar_muestra_ventas_y_reporte_final_con_permiso(): void
    {
        $this->autenticarConPermisoInformes();

        $html = $this->sidebar();

        $this->assertStringContainsString(route('informes.ventas.index'), $html);
        $this->assertStringContainsString(route('informes.reporte-final.index'), $html);
        // "Ventas" va primera de la lista, como en la landing de tarjetas de Contagram.
        $this->assertLessThan(
            strpos($html, route('informes.stock.index')),
            strpos($html, route('informes.ventas.index'))
        );
    }
}
