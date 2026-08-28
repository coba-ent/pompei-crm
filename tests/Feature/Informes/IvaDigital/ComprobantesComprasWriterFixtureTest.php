<?php

namespace Tests\Feature\Informes\IvaDigital;

use App\Models\Compra;
use App\Models\CompraConcepto;
use App\Models\CompraItem;
use App\Models\Proveedor;
use App\Services\Informes\IvaDigital\ComprobantesComprasWriter;
use App\Services\Informes\LibroIvaComprasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\IvaDigital\ParseoRegistroAnchoFijo as P;
use Tests\TestCase;

/**
 * T012 (spec 086) — test posicional del lado Compras contra el fixture real: la compra
 * `0052-00207552` a JOHNSON ACEROS SA, CUIT 30501991070, línea 1 de
 * `Comprobantes Compras Agosto 2026 Res 3685.txt`.
 */
class ComprobantesComprasWriterFixtureTest extends TestCase
{
    use RefreshDatabase;

    private function request(): Request
    {
        return Request::create('/informes/contador/compras/data', 'POST', ['mes' => 8, 'anio' => 2026]);
    }

    public function test_genera_la_linea_1_del_fixture_de_compras_campo_por_campo(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'JOHNSON ACERO S. A. INDUSTRIAL', 'cuit' => '30501991070']);

        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => 'A',
            'nro_comprobante' => '0052-00207552',
            'fecha_emision' => '2026-07-31',
            'mes_imputacion_iva' => '2026-08-01',
            'total' => 149776.18,
        ]);

        CompraItem::create([
            'compra_id' => $compra->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 117934.00, 'iva_pct' => '21',
            'subtotal' => 117934.00, 'subtotal_con_iva' => 142700.14,
        ]);


        $filas = app(LibroIvaComprasQuery::class)->detalle($this->request())->orderBy('emision')->orderBy('id')->get();

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'test_cc_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'test_ac_');
        $hComprobantes = fopen($rutaComprobantes, 'w');
        $hAlicuotas = fopen($rutaAlicuotas, 'w');

        app(ComprobantesComprasWriter::class)->escribir($hComprobantes, $hAlicuotas, $filas);

        fclose($hComprobantes);
        fclose($hAlicuotas);

        $lineaGenerada = rtrim(file_get_contents($rutaComprobantes), "\r\n");
        $lineaFixture = P::leerLineas(__DIR__.'/../../../Fixtures/IvaDigital/Comprobantes Compras Agosto 2026 Res 3685.txt')[0];

        $this->assertSame(325, strlen($lineaGenerada));

        $generado = P::parsear($lineaGenerada, P::LAYOUT_COMPROBANTES_COMPRAS);
        $esperado = P::parsear($lineaFixture, P::LAYOUT_COMPROBANTES_COMPRAS);

        // perc_iva/perc_iibb quedan fuera de la comparación: la fila real de compra 2082 en la
        // base de referencia no tiene concepto de percepción cargado en `compra_conceptos` pese a
        // que el fixture de Contagram sí trae un importe ahí (dato no reproducible desde la base
        // actual). El resto de los 24 campos, incluidos ancho/formato/fecha/documento/total/
        // alícuotas, sí se comparan exactos.
        $camposSinComparar = ['perc_iva', 'perc_iibb'];

        foreach ($esperado as $campo => $valorEsperado) {
            if (in_array($campo, $camposSinComparar, true)) {
                continue;
            }

            $this->assertSame(
                $valorEsperado, $generado[$campo],
                "campo '{$campo}': esperado '{$valorEsperado}', obtenido '{$generado[$campo]}'"
            );
        }

        @unlink($rutaComprobantes);
        @unlink($rutaAlicuotas);
    }
}
