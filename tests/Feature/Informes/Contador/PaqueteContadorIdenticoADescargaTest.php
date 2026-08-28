<?php

namespace Tests\Feature\Informes\Contador;

use App\Exports\Informes\LibroIvaExport;
use App\Models\Cliente;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\Contador\OpcionesEnvio;
use App\Services\Informes\Contador\PaqueteContador;
use App\Services\Informes\Contador\Periodo;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * T012 (spec 087, SC-003) — el adjunto generado por `PaqueteContador` es idéntico a la descarga
 * directa del mismo período con las mismas casillas: ambos deben ser exactamente el mismo código
 * de generación (research Decisión 5), no dos caminos que casualmente coinciden.
 */
class PaqueteContadorIdenticoADescargaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_xlsx_de_iva_ventas_adjunto_es_identico_a_la_descarga_directa(): void
    {
        $cliente = Cliente::factory()->create(['cuit' => '20111111112', 'tipo_documento' => 'CUIT']);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'tipo_comprobante' => 'B',
            'nro_comprobante' => '0001-00000001', 'fecha_emision' => '2026-08-10', 'total' => 1210,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);

        $periodo = new Periodo(2026, 8);
        $opciones = new OpcionesEnvio(true, false, false);

        $archivos = app(PaqueteContador::class)->generar($periodo, $opciones);
        $bytesAdjunto = file_get_contents($archivos[$periodo->nombreIvaVentas()]);

        $requestDescarga = Request::create('/', 'POST', ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => false]);
        $bytesDescarga = Excel::raw(
            new LibroIvaExport(app(LibroIvaVentasQuery::class), $requestDescarga, 'Libro IVA Ventas'),
            \Maatwebsite\Excel\Excel::XLSX
        );

        $this->assertSame($bytesDescarga, $bytesAdjunto);

        foreach ($archivos as $ruta) {
            @unlink($ruta);
        }
    }
}
