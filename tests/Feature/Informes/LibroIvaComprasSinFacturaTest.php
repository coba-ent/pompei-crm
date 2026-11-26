<?php

namespace Tests\Feature\Informes;

use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Proveedor;
use App\Services\Informes\LibroIvaComprasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Pedido de la contadora (26/11/2026): las compras "Sin Factura" (tipo NULL, '' o 'S') no
 * pasan por el Libro IVA Compras / subdiario de compras, aunque sean no gravadas — ni en
 * pantalla, ni en el Excel, ni en el envío al contador (todos salen de la misma query).
 */
class LibroIvaComprasSinFacturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_compras_sin_factura_y_sus_notas_no_entran_al_libro(): void
    {
        $proveedor = Proveedor::factory()->create();

        $conFactura = Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'tipo_comprobante' => 'A',
            'nro_comprobante' => '0001-00000001', 'fecha_emision' => '2026-08-10', 'total' => 1210,
        ]);
        Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'tipo_comprobante' => null,
            'nro_comprobante' => null, 'fecha_emision' => '2026-08-11', 'total' => 500,
        ]);
        $sinFactura = Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'tipo_comprobante' => 'S',
            'nro_comprobante' => null, 'fecha_emision' => '2026-08-12', 'total' => 700,
        ]);
        NotaCreditoDebito::create([
            'compra_id' => $sinFactura->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01', 'fecha_emision' => '2026-08-20', 'monto' => 100,
            'tipo_comprobante' => 'S', 'descripcion' => 'Devolución',
        ]);
        NotaCreditoDebito::create([
            'compra_id' => $conFactura->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01', 'fecha_emision' => '2026-08-21', 'monto' => 121,
            'tipo_comprobante' => 'A', 'nro_comprobante' => '0001-00000002', 'descripcion' => 'Devolución',
        ]);

        $request = Request::create('/', 'POST', ['mes' => 8, 'anio' => 2026]);
        $filas = app(LibroIvaComprasQuery::class)->detalle($request)->get();

        $this->assertEqualsCanonicalizing(['A', 'NCA'], $filas->pluck('tipo')->all());
    }
}
