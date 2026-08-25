<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** spec 080, US1 — PDF de Movimientos de Clientes: 200, application/pdf, contenido con datos reales. */
class MovimientosClientesPdfTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    public function test_pdf_movimientos_devuelve_200_y_content_type_pdf(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-10', 'total' => 1210]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 1210, 'fecha' => '2026-08-11']);

        $response = $this->get('/informes/cuenta-corriente/movimientos/pdf?fecha_desde=2026-08-01&fecha_hasta=2026-08-31');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }
}
