<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** spec 077, US5 — export a Excel del Libro IVA (FR-033/034/035/036). */
class LibroIvaExportTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    /** FR-033/SC-006: respeta período y filtros; FR-034: trae las 19 columnas siempre. */
    public function test_exportar_genera_el_archivo_con_el_periodo_elegido(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-10']);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);

        Excel::fake();

        $response = $this->get('/informes/contador/ventas/exportar?mes=8&anio=2026&arca=1&manuales=1');

        $response->assertOk();
        Excel::assertDownloaded('Libro IVA Ventas 08-2026.xlsx', function ($export) {
            $filas = $export->array();

            // spec 089: filas 1-4 encabezado del negocio, fila 5 los títulos; fila 6 en adelante
            // el detalle, y las 3 de totales al pie. spec 091: son 13 columnas, las de Contagram.
            return count($filas[4]) === 13 && count($filas) >= 6;
        });
    }

    /** FR-036: exportar sin período responde 422 y no genera archivo. */
    public function test_exportar_sin_periodo_responde_422(): void
    {
        $response = $this->get('/informes/contador/ventas/exportar');

        $response->assertStatus(422);
    }

    public function test_exportar_compras_sin_periodo_responde_422(): void
    {
        $response = $this->get('/informes/contador/compras/exportar');

        $response->assertStatus(422);
    }
}
