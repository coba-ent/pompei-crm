<?php

namespace Tests\Feature\Informes;

use App\Exports\Informes\CuentaCorrienteProveedorExport;
use App\Exports\Informes\InformeComprasExport;
use App\Exports\Informes\InformeGastosExport;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraConcepto;
use App\Models\CompraItem;
use App\Models\Gasto;
use App\Models\Proveedor;
use App\Services\Informes\ComprasInformeQuery;
use App\Services\Informes\GastosInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** spec 067 US4 — exportación a Excel de dos hojas y a PDF `inline`. */
class InformesExportTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');
        $this->autenticarConPermisoInformes();
        $this->datosDePrueba();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function datosDePrueba(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'Distribuidora SRL']);

        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-08-10',
            'subtotal_sin_descuento' => 1000,
            'subtotal_con_descuento' => 1000,
            'total' => 1260,
        ]);
        CompraItem::create([
            'compra_id' => $compra->id, 'descripcion' => 'Caño PVC', 'cantidad' => 2,
            'precio_unitario' => 500, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);
        CompraConcepto::create([
            'compra_id' => $compra->id, 'tipo' => 'percepcion', 'concepto' => 'Percepción IIBB', 'monto' => 50,
        ]);

        $oficina = Categoria::create(['tipo' => 'gasto', 'nombre' => 'Oficina', 'activo' => true]);
        Gasto::factory()->create(['fecha' => '2026-08-05', 'monto' => 300, 'categoria_id' => $oficina->id]);
    }

    private function request(array $params = []): Request
    {
        return Request::create('/informes', 'GET', $params);
    }

    /**
     * FR-040: exactamente dos hojas —una formateada y una plana— en los tres informes.
     *
     * Los exports se toman de los propios endpoints (vía `Excel::fake()`) y no se construyen a
     * mano: así se ejercita el mismo armado de query que corre en producción, incluida la UNION
     * de Movimientos, que es justo la parte que un doble hecho a mano no reproduce.
     */
    public function test_excel_tiene_dos_hojas(): void
    {
        Excel::fake();

        $rutas = [
            'informes.compras.exportar' => InformeComprasExport::class,
            'informes.gastos.exportar' => InformeGastosExport::class,
            'informes.cuenta-corriente-proveedores.exportar' => CuentaCorrienteProveedorExport::class,
        ];

        foreach ($rutas as $ruta => $clase) {
            $this->get(route($ruta))->assertOk();
        }

        foreach ($rutas as $ruta => $clase) {
            Excel::assertDownloaded($this->nombreDeArchivo($ruta), function ($export) use ($clase) {
                $hojas = $export->sheets();

                $this->assertInstanceOf($clase, $export);
                $this->assertCount(2, $hojas, $clase.' tiene que exportar dos hojas.');
                $this->assertNotSame($hojas[0]->title(), $hojas[1]->title());

                return true;
            });
        }
    }

    private function nombreDeArchivo(string $ruta): string
    {
        $nombre = match ($ruta) {
            'informes.compras.exportar' => 'Compras',
            'informes.gastos.exportar' => 'Gastos',
            default => 'Cuenta Corriente Proveedores',
        };

        return "Informe de {$nombre} ".now()->format('d-m-Y Hi').' Hs.xlsx';
    }

    /** FR-041: la hoja plana de Compras trae el desglose completo, aunque esté oculto en pantalla. */
    public function test_excel_de_compras_trae_desglose_impositivo_completo(): void
    {
        $hojas = (new InformeComprasExport(app(ComprasInformeQuery::class), $this->request()))->sheets();
        $plana = $hojas[1]->array();

        $encabezados = $plana[0];

        foreach (['IVA 21%', 'IVA 10,5%', 'IVA 2,5%', 'Perc. IVA', 'Perc. IIBB', 'Otras Percepciones', 'Imp. Internos'] as $columna) {
            $this->assertContains($columna, $encabezados, "Falta la columna «{$columna}» en la hoja plana.");
        }

        $fila = array_combine($encabezados, $plana[1]);

        $this->assertEqualsWithDelta(1000.0, $fila['Importe Neto Gravado'], 0.01);
        $this->assertEqualsWithDelta(210.0, $fila['IVA 21%'], 0.01);
        $this->assertEqualsWithDelta(50.0, $fila['Perc. IIBB'], 0.01);
        $this->assertEqualsWithDelta(0.0, $fila['Perc. IVA'], 0.01);
    }

    /** FR-043: los totales del archivo tienen que coincidir con los de la pantalla, al centavo. */
    public function test_totales_export_coinciden_con_pantalla(): void
    {
        $kpis = app(ComprasInformeQuery::class)->kpis($this->request());
        $formateada = (new InformeComprasExport(app(ComprasInformeQuery::class), $this->request()))->sheets()[0]->array();

        $totales = collect($formateada)
            ->filter(fn ($fila) => ($fila[0] ?? null) === 'Total Compras')
            ->first();

        $this->assertNotNull($totales, 'La hoja formateada tiene que traer la fila de Total Compras.');
        $this->assertEqualsWithDelta($kpis['total_compras'], $totales[1], 0.01);

        $stats = app(GastosInformeQuery::class)->subtotales($this->request());
        $hojaGastos = (new InformeGastosExport(app(GastosInformeQuery::class), $this->request()))->sheets()[0]->array();

        $gastoTotal = collect($hojaGastos)
            ->filter(fn ($fila) => ($fila[1] ?? null) === 'Gasto Total')
            ->first();

        $this->assertNotNull($gastoTotal);
        $this->assertEqualsWithDelta($stats['gasto_total'], $gastoTotal[4], 0.01);
    }

    public function test_los_tres_exportar_descargan_un_xlsx(): void
    {
        Excel::fake();

        foreach (['informes.compras.exportar', 'informes.gastos.exportar', 'informes.cuenta-corriente-proveedores.exportar'] as $ruta) {
            $this->get(route($ruta))->assertOk();
        }

        Excel::assertDownloaded('Informe de Compras '.now()->format('d-m-Y Hi').' Hs.xlsx');
        Excel::assertDownloaded('Informe de Gastos '.now()->format('d-m-Y Hi').' Hs.xlsx');
        Excel::assertDownloaded('Informe de Cuenta Corriente Proveedores '.now()->format('d-m-Y Hi').' Hs.xlsx');
    }

    /**
     * FR-042: `inline` y no `attachment`. Es lo que permite que el `<iframe>` del modal
     * compartido lo renderice en vez de que el navegador lo baje (regla #4 de CLAUDE.md).
     */
    public function test_pdf_se_sirve_inline(): void
    {
        foreach (['informes.compras.pdf', 'informes.gastos.pdf', 'informes.cuenta-corriente-proveedores.pdf'] as $ruta) {
            $respuesta = $this->get(route($ruta))->assertOk();

            $this->assertStringContainsString(
                'inline',
                (string) $respuesta->headers->get('content-disposition'),
                "{$ruta} tiene que servirse inline para el modal de PDF."
            );
            $this->assertSame('application/pdf', $respuesta->headers->get('content-type'));
        }
    }
}
