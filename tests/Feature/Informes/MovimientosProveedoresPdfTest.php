<?php

namespace Tests\Feature\Informes;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Pago;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** spec 080, US1 — espejo de MovimientosClientesPdfTest del lado Proveedores. */
class MovimientosProveedoresPdfTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    public function test_pdf_movimientos_devuelve_200_y_content_type_pdf(): void
    {
        $compra = Compra::factory()->create(['proveedor_id' => Proveedor::factory(), 'fecha_emision' => '2026-08-10', 'total' => 1210]);
        CompraItem::create([
            'compra_id' => $compra->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);
        Pago::factory()->create(['compra_id' => $compra->id, 'monto' => 1210, 'fecha' => '2026-08-11']);

        $response = $this->get('/informes/cuenta-corriente-proveedores/movimientos/pdf?fecha_desde=2026-08-01&fecha_hasta=2026-08-31');

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }
}
