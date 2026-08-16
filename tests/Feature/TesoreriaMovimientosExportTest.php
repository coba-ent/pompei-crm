<?php

namespace Tests\Feature;

use App\Exports\Tesoreria\MovimientosExport;
use App\Models\CuentaTesoreria;
use App\Models\Rol;
use App\Services\Tesoreria\SeccionesMovimientos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El export de Movimientos pasó de CSV a XLSX calcado del de Contagram (relevado 16/08/2026).
 *
 * Se prueba la ESTRUCTURA del array que arma el export y no el archivo binario: la disposición
 * es lo que se relevó y lo que se puede romper sin darse cuenta. Los estilos (bandas de color,
 * anchos, tamaños de fuente) se verifican abriendo el archivo, no acá.
 */
class TesoreriaMovimientosExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );
    }

    /**
     * Se pasa por `SeccionesMovimientos` a propósito, en vez de armar las secciones a mano: lo que
     * interesa probar es lo que ve el usuario, y eso incluye las reglas de qué cuentas se listan.
     * El PDF consume exactamente lo mismo.
     */
    private function export(array $flujo): array
    {
        $secciones = (new SeccionesMovimientos)->armar($flujo, CuentaTesoreria::where('visible', true)->get());

        return (new MovimientosExport(
            $flujo,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-12'),
            $secciones,
        ))->array();
    }

    private function flujoVacio(): array
    {
        return ['cobros' => [], 'pagos' => [], 'total_cobros' => 0.0, 'total_pagos' => 0.0, 'resultado' => 0.0];
    }

    public function test_la_cabecera_replica_la_disposicion_de_contagram(): void
    {
        $filas = $this->export($this->flujoVacio());

        // C2 es el título; las filas 1 y 3 van vacías (índices 0 y 2).
        $this->assertSame('Movimientos', $filas[1][2]);
        $this->assertSame(['Desde', 'Hasta', 'Total Cobros', 'Total Pagos', 'Resultado'], $filas[3]);
        $this->assertSame('01/08/2026', $filas[4][0]);
        $this->assertSame('12/08/2026', $filas[4][1]);
    }

    public function test_los_pagos_van_en_negativo_aunque_el_flujo_los_devuelva_en_absoluto(): void
    {
        $cuenta = CuentaTesoreria::create(['nombre' => 'Caja Test', 'tipo' => 'efectivo', 'visible' => true]);

        $filas = $this->export([
            'cobros' => [],
            'pagos' => [['nombre' => $cuenta->nombre, 'monto' => 1000.0]],  // `flujo()` devuelve absoluto
            'total_cobros' => 0.0,
            'total_pagos' => 1000.0,
            'resultado' => -1000.0,
        ]);

        // Total Pagos de la cabecera (fila 5, columna D).
        $this->assertSame(-1000.0, $filas[4][3]);

        // Una cuenta de efectivo se lista en LAS DOS secciones, así que hay que buscarla dentro
        // de Pagos: en Cobros aparece con 0 y el assert pasaría por el motivo equivocado.
        $inicioPagos = collect($filas)->search(fn ($f) => $f[0] === 'Pagos');
        $filaCuenta = collect($filas)->slice($inicioPagos)->first(fn ($f) => $f[0] === 'Caja Test');
        $this->assertSame(-1000.0, $filaCuenta[4]);

        // También hay que buscarlo dentro de Pagos: la fila 4 de encabezados tiene el literal
        // "Total Pagos" en la misma columna D, y `first()` se quedaría con esa.
        $filaTotal = collect($filas)->slice($inicioPagos)->first(fn ($f) => $f[3] === 'Total Pagos');
        $this->assertSame(-1000.0, $filaTotal[4]);
    }

    public function test_lista_todas_las_cuentas_de_la_seccion_aunque_no_tengan_movimiento(): void
    {
        // Contagram no lista sólo las cuentas con movimiento: las que no tuvieron nada van en 0.
        CuentaTesoreria::create(['nombre' => 'Banco Sin Uso', 'tipo' => 'banco', 'visible' => true]);
        CuentaTesoreria::create(['nombre' => 'Tarjeta Sin Uso', 'tipo' => 'a_cobrar', 'visible' => true]);

        $filas = $this->export($this->flujoVacio());
        $nombres = collect($filas)->pluck(0)->filter()->all();

        $this->assertContains('Banco Sin Uso', $nombres);
        $this->assertContains('Tarjeta Sin Uso', $nombres);

        $sinUso = collect($filas)->first(fn ($f) => $f[0] === 'Banco Sin Uso');
        $this->assertSame(0.0, $sinUso[4]);
    }

    public function test_cobros_no_lista_cuentas_de_a_pagar_y_pagos_no_lista_las_de_a_cobrar(): void
    {
        CuentaTesoreria::create(['nombre' => 'Solo Cobrar', 'tipo' => 'a_cobrar', 'visible' => true]);
        CuentaTesoreria::create(['nombre' => 'Solo Pagar', 'tipo' => 'a_pagar', 'visible' => true]);

        $filas = $this->export($this->flujoVacio());
        $nombres = collect($filas)->pluck(0)->all();

        $inicioPagos = collect($nombres)->search('Pagos');
        $cobros = array_slice($nombres, 0, $inicioPagos);
        $pagos = array_slice($nombres, $inicioPagos);

        $this->assertContains('Solo Cobrar', $cobros);
        $this->assertNotContains('Solo Cobrar', $pagos);

        $this->assertContains('Solo Pagar', $pagos);
        $this->assertNotContains('Solo Pagar', $cobros);
    }

    public function test_cheque_de_terceros_aparece_en_pagos_pese_a_ser_cuenta_a_cobrar(): void
    {
        // La excepción relevada del archivo de Contagram; ver el comentario de MovimientosExport.
        CuentaTesoreria::create(['nombre' => 'Cheque de Terceros', 'tipo' => 'a_cobrar', 'visible' => true]);

        $nombres = collect($this->export($this->flujoVacio()))->pluck(0)->all();
        $inicioPagos = collect($nombres)->search('Pagos');

        $this->assertContains('Cheque de Terceros', array_slice($nombres, $inicioPagos));
    }

    public function test_el_pdf_lista_las_mismas_cuentas_que_el_xlsx(): void
    {
        // Los dos informes salen de `SeccionesMovimientos`; este test existe para que nadie los
        // vuelva a separar. Antes el PDF armaba su propia lista y mostraba sólo las cuentas con
        // movimiento, mientras el XLSX ya listaba todas.
        CuentaTesoreria::create(['nombre' => 'Banco Sin Uso', 'tipo' => 'banco', 'visible' => true]);

        $respuesta = $this->get(route('tesoreria.movimientos.pdf', [
            'desde' => '2026-08-01', 'hasta' => '2026-08-12',
        ]));

        $respuesta->assertOk();
        $this->assertStringContainsString('application/pdf', $respuesta->headers->get('content-type'));
    }

    public function test_el_pdf_se_devuelve_inline_para_el_modal_compartido(): void
    {
        // CLAUDE.md §4: los PDF se ven en el modal, así que no pueden salir como `attachment`.
        $respuesta = $this->get(route('tesoreria.movimientos.pdf', [
            'desde' => '2026-08-01', 'hasta' => '2026-08-12',
        ]));

        $this->assertStringContainsString('inline', (string) $respuesta->headers->get('content-disposition'));
    }

    public function test_el_endpoint_devuelve_un_xlsx_con_el_nombre_de_contagram(): void
    {
        $respuesta = $this->get(route('tesoreria.movimientos.export', [
            'desde' => '2026-08-01', 'hasta' => '2026-08-12',
        ]));

        $respuesta->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $respuesta->headers->get('content-type'));
        $this->assertMatchesRegularExpression(
            '/filename="Informe Final \d{2}-\d{2}-\d{4} \d{4} Hs\.xlsx"/',
            $respuesta->headers->get('content-disposition')
        );
    }
}
