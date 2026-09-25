<?php

namespace Tests\Feature\Informes;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Services\Informes\ComprasInformeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Filtro "Facturado" del Informe de Compras (bug reportado por el cliente, 25/09/2026).
 *
 * El filtro consultaba `comprobantes_fiscales`, pero en Compras la factura la emite el
 * **proveedor** y se guarda en la propia compra (`tipo_comprobante` + `nro_comprobante`);
 * `comprobantes_fiscales` guarda lo que emitimos nosotros por ARCA y en Compras está vacía
 * (0 filas sobre 1.460 compras facturadas en producción).
 *
 * El efecto medido contra datos reales era peor que lo reportado:
 * - "Sí" devolvía **0** de 7.918 compras facturadas (lo que el cliente vio).
 * - "No" devolvía **las 12.029**, incluidas las que sí tenían factura — un informe que parecía
 *   andar y daba datos incorrectos en silencio.
 *
 * Es el mismo bug que ya se había corregido en el listado de Compras; acá se replica su criterio.
 */
class InformeComprasFiltroFacturadoTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');
        $this->autenticarConPermisoInformes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function compra(?string $tipoComprobante): Compra
    {
        $compra = Compra::factory()->create([
            'fecha_emision' => '2026-08-10',
            'tipo_comprobante' => $tipoComprobante,
            'subtotal_sin_descuento' => 1000,
            'subtotal_con_descuento' => 1000,
            'total' => 1210,
        ]);

        CompraItem::create([
            'compra_id' => $compra->id,
            'descripcion' => 'Ítem',
            'cantidad' => 1,
            'precio_unitario' => 1000,
            'iva_pct' => '21',
            'subtotal' => 1000,
            'subtotal_con_iva' => 1210,
        ]);

        return $compra;
    }

    /** @return list<int> ids de las compras que devuelve el informe con ese valor de filtro */
    private function idsCon(string $facturado): array
    {
        $req = Request::create('/informes/compras', 'GET', [
            'fecha_desde' => '2026-08-01',
            'fecha_hasta' => '2026-08-31',
            'facturado' => $facturado,
        ]);

        return app(ComprasInformeQuery::class)->detalle($req)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_si_devuelve_solo_las_compras_con_factura_del_proveedor(): void
    {
        $conFactura = $this->compra('A');
        $this->compra(null);

        $ids = $this->idsCon('si');

        $this->assertSame([$conFactura->id], $ids);
    }

    public function test_no_devuelve_solo_las_compras_sin_factura(): void
    {
        $this->compra('A');
        $sinFactura = $this->compra(null);

        $ids = $this->idsCon('no');

        $this->assertSame([$sinFactura->id], $ids);
    }

    /**
     * `''` y `'S'` cuentan como "sin factura", mismo criterio que el listado de Compras y que
     * `IvaDigitalPaquete::generarLadoCompras()` para excluirlas del TXT de RG 3685.
     */
    public function test_tipo_comprobante_vacio_o_S_cuenta_como_sin_factura(): void
    {
        $this->compra('B');
        $vacio = $this->compra('');
        $tipoS = $this->compra('S');

        $ids = $this->idsCon('no');

        sort($ids);
        $esperado = [$vacio->id, $tipoS->id];
        sort($esperado);

        $this->assertSame($esperado, $ids);
    }

    /** El informe manda 'si'/'no' y el listado '1'/'0': los dos tienen que funcionar. */
    public function test_acepta_los_valores_del_listado_ademas_de_los_del_informe(): void
    {
        $conFactura = $this->compra('A');
        $sinFactura = $this->compra(null);

        $this->assertSame([$conFactura->id], $this->idsCon('1'));
        $this->assertSame([$sinFactura->id], $this->idsCon('0'));
    }

    /**
     * "Sí" y "No" tienen que ser complementarios y exhaustivos: sumados dan el total sin filtro,
     * sin perder ni duplicar filas. Es la verificación que delata el bug original, donde "No"
     * devolvía TODAS las compras.
     */
    public function test_si_y_no_particionan_el_total_sin_solaparse(): void
    {
        $this->compra('A');
        $this->compra('B');
        $this->compra(null);
        $this->compra('');

        $si = $this->idsCon('si');
        $no = $this->idsCon('no');

        $req = Request::create('/informes/compras', 'GET', [
            'fecha_desde' => '2026-08-01', 'fecha_hasta' => '2026-08-31',
        ]);
        $total = app(ComprasInformeQuery::class)->detalle($req)->count();

        $this->assertCount(2, $si);
        $this->assertCount(2, $no);
        $this->assertSame($total, count($si) + count($no));
        $this->assertEmpty(array_intersect($si, $no), 'Ninguna compra puede estar en los dos grupos.');
    }
}
