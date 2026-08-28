<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\MovimientosClientesQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * spec 080, US2 — export a Excel de Movimientos de Cta Cte Clientes: 34 columnas (FR-007), fila de
 * Cobro con columnas fiscales en blanco (FR-010), NC/ND con su propio desglose con signo (FR-016),
 * "Aplicada en N° de Factura"/"Fecha Factura Aplicada" siempre vacías (FR-012).
 */
class MovimientosClientesExportTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function request(array $extra = []): Request
    {
        return Request::create('/informes/cuenta-corriente/movimientos/exportar', 'GET', array_merge([
            'fecha_desde' => '2026-03-01', 'fecha_hasta' => '2026-03-31',
        ], $extra));
    }

    public function test_venta_con_dos_alicuotas_y_su_cobro_completo(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente Excel']);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-03-10',
            'subtotal_sin_descuento' => 2000,
            'subtotal_con_descuento' => 2000,
            'total' => 2321,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Item 21', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Item 10.5', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '10.5', 'subtotal' => 1000, 'subtotal_con_iva' => 1105,
        ]);
        Cobro::create([
            'venta_id' => $venta->id, 'fecha' => '2026-03-11', 'monto' => 2315,
            'cuenta_tesoreria_id' => CuentaTesoreria::factory()->create()->id,
        ]);

        $filas = app(MovimientosClientesQuery::class)->obtener($this->request());

        $filaVenta = $filas->firstWhere('operacion', 'venta');
        $filaCobro = $filas->firstWhere('operacion', 'cobro');

        $this->assertNotNull($filaVenta);
        $this->assertEqualsWithDelta(210.0, $filaVenta['iva_21'], 0.02);
        $this->assertEqualsWithDelta(105.0, $filaVenta['iva_10_5'], 0.02);
        $this->assertEqualsWithDelta(2000.0, $filaVenta['neto_gravado'], 0.02);

        // FR-010: la fila de Cobro deja las columnas fiscales en blanco (null), no en 0.
        $this->assertNotNull($filaCobro);
        $this->assertNull($filaCobro['neto_gravado']);
        $this->assertNull($filaCobro['iva_21']);
        $this->assertNull($filaCobro['subtotal_sin_descuento']);
        $this->assertEqualsWithDelta(2315.0, $filaCobro['cobrado'], 0.01);

        // FR-012: siempre vacías en todas las filas.
        $this->assertNull($filaVenta['aplicada_nro_factura']);
        $this->assertNull($filaVenta['fecha_factura_aplicada']);
        $this->assertNull($filaCobro['aplicada_nro_factura']);
        $this->assertNull($filaCobro['fecha_factura_aplicada']);
    }

    public function test_nota_de_credito_aparece_con_desglose_fiscal_con_signo_negativo(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-03-05',
            'total' => 1210,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Item', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);

        NotaCreditoDebito::create([
            'venta_id' => $venta->id,
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => '2026-03-01',
            'fecha_emision' => '2026-03-15',
            'monto' => 121.0,
            'tipo_comprobante' => 'A',
            'descripcion' => 'Devolución parcial',
        ]);

        $filas = app(MovimientosClientesQuery::class)->obtener($this->request());
        $filaNota = $filas->firstWhere('operacion', 'nota_credito');

        $this->assertNotNull($filaNota);
        // La NC hereda la única alícuota (21%) y resta: neto negativo, IVA negativo.
        $this->assertLessThan(0, $filaNota['neto_gravado']);
        $this->assertLessThan(0, $filaNota['iva_21']);

        // Columnas que sólo aplican a Venta/Cobro van en blanco en la fila de NC/ND.
        $this->assertNull($filaNota['subtotal_sin_descuento']);
        $this->assertNull($filaNota['medio_cobro']);
        $this->assertNull($filaNota['id_venta']);
    }
}
