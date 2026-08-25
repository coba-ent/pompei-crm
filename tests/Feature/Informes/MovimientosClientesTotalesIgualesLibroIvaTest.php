<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\Venta;
use App\Models\VentaConcepto;
use App\Models\VentaItem;
use App\Services\Informes\MovimientosClientesQuery;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * spec 080, US2, FR-015: para un rango que coincide con un mes calendario completo, los totales
 * agregados (neto/IVA/perc) del export de Movimientos coinciden con los del Libro IVA Ventas del
 * mismo mes — misma fuente de cálculo (research.md D1), sólo cambia cómo se acota el período.
 */
class MovimientosClientesTotalesIgualesLibroIvaTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    public function test_totales_del_export_coinciden_con_el_libro_iva_del_mismo_mes(): void
    {
        $cliente = Cliente::factory()->create();

        for ($i = 1; $i <= 3; $i++) {
            $venta = Venta::factory()->create([
                'cliente_id' => $cliente->id,
                'fecha_emision' => "2026-08-0{$i}",
                'total' => 1210,
            ]);
            VentaItem::create([
                'venta_id' => $venta->id, 'descripcion' => 'Item', 'cantidad' => 1,
                'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
            ]);
            VentaConcepto::create([
                'venta_id' => $venta->id, 'tipo' => 'percepcion', 'concepto' => 'Percepción IIBB', 'monto' => 10,
            ]);
        }

        $movRequest = Request::create('/informes/cuenta-corriente/movimientos/exportar', 'GET', [
            'fecha_desde' => '2026-08-01', 'fecha_hasta' => '2026-08-31',
        ]);
        $filas = app(MovimientosClientesQuery::class)->obtener($movRequest)
            ->whereIn('operacion', ['venta', 'nota_credito', 'nota_debito']);

        $totalesExport = [
            'neto_gravado' => round((float) $filas->sum('neto_gravado'), 2),
            'iva_21' => round((float) $filas->sum('iva_21'), 2),
            'perc_iibb' => round((float) $filas->sum('perc_iibb'), 2),
        ];

        $libroRequest = Request::create('/informes/contador/ventas/data', 'GET', ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true]);
        $totalesLibroIva = app(LibroIvaVentasQuery::class)->totales($libroRequest);

        $this->assertEqualsWithDelta(3000.0, $totalesExport['neto_gravado'], 0.02);
        $this->assertEqualsWithDelta($totalesLibroIva['gravados'], $totalesExport['neto_gravado'], 0.02);
        $this->assertEqualsWithDelta($totalesLibroIva['iva_total'], $totalesExport['iva_21'], 0.02);
        $this->assertEqualsWithDelta($totalesLibroIva['perc_iva_iibb_total'], $totalesExport['perc_iibb'], 0.02);
    }
}
