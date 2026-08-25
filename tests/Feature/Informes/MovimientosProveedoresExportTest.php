<?php

namespace Tests\Feature\Informes;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\CuentaTesoreria;
use App\Models\Pago;
use App\Models\Proveedor;
use App\Services\Informes\MovimientosProveedoresQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * spec 080, US2 — espejo de MovimientosClientesExportTest del lado Proveedores: "Sellos" siempre 0
 * (FR-017), sin columna "Vendedor" en la forma de la fila (data-model.md).
 */
class MovimientosProveedoresExportTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function request(array $extra = []): Request
    {
        return Request::create('/informes/cuenta-corriente-proveedores/movimientos/exportar', 'GET', array_merge([
            'fecha_desde' => '2026-08-01', 'fecha_hasta' => '2026-08-31',
        ], $extra));
    }

    public function test_compra_con_dos_alicuotas_y_su_pago_completo(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'Proveedor Excel']);
        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => '2026-08-10',
            'subtotal_sin_descuento' => 2000,
            'subtotal_con_descuento' => 2000,
            'total' => 2321,
        ]);
        CompraItem::create([
            'compra_id' => $compra->id, 'descripcion' => 'Item 21', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);
        CompraItem::create([
            'compra_id' => $compra->id, 'descripcion' => 'Item 10.5', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '10.5', 'subtotal' => 1000, 'subtotal_con_iva' => 1105,
        ]);
        Pago::create([
            'compra_id' => $compra->id, 'fecha' => '2026-08-11', 'monto' => 2315,
            'cuenta_tesoreria_id' => CuentaTesoreria::factory()->create()->id,
        ]);

        $filas = app(MovimientosProveedoresQuery::class)->obtener($this->request());

        $filaCompra = $filas->firstWhere('operacion', 'compra');
        $filaPago = $filas->firstWhere('operacion', 'pago');

        $this->assertNotNull($filaCompra);
        $this->assertEqualsWithDelta(210.0, $filaCompra['iva_21'], 0.02);
        $this->assertEqualsWithDelta(105.0, $filaCompra['iva_10_5'], 0.02);
        $this->assertEqualsWithDelta(2000.0, $filaCompra['neto_gravado'], 0.02);
        // FR-017: Sellos siempre 0 en la fila con desglose fiscal.
        $this->assertSame(0.0, $filaCompra['sellos']);

        $this->assertNotNull($filaPago);
        $this->assertNull($filaPago['neto_gravado']);
        $this->assertNull($filaPago['iva_21']);
        $this->assertEqualsWithDelta(2315.0, $filaPago['pagado'], 0.01);

        // No existe columna "Vendedor" en la forma de la fila de Proveedores.
        $this->assertArrayNotHasKey('vendedor', $filaCompra);
        $this->assertArrayNotHasKey('vendedor', $filaPago);
    }

    public function test_export_no_tiene_columna_vendedor(): void
    {
        $columnas = (new \ReflectionClass(\App\Exports\Informes\MovimientosProveedoresExport::class))
            ->getConstant('ENCABEZADOS');

        $this->assertNotContains('Vendedor', $columnas);
        $this->assertContains('Sellos', $columnas);
    }
}
