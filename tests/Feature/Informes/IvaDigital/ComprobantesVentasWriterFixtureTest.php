<?php

namespace Tests\Feature\Informes\IvaDigital;

use App\Models\Cliente;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\IvaDigital\AlicuotasVentasWriter;
use App\Services\Informes\IvaDigital\ComprobantesVentasWriter;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\IvaDigital\ParseoRegistroAnchoFijo as P;
use Tests\TestCase;

/**
 * T012 (spec 086) — test posicional campo por campo contra el fixture real. Reproduce la venta id
 * 20309 (línea 1 del fixture de Agosto 2026: `0005-00005669`, Fabio Humberto Maidana, DNI
 * 18209989, neto $156.757,16 al 21%, total $189.676,17) con datos sintéticos idénticos, y compara
 * el resultado del writer campo por campo contra la línea real del fixture — nunca un
 * `assertEquals` de archivos completos (plan §Estrategia de test 1): así una diferencia señala
 * exactamente qué campo falló.
 */
class ComprobantesVentasWriterFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_genera_la_linea_1_del_fixture_campo_por_campo(): void
    {
        $cliente = Cliente::factory()->create([
            'nombre' => 'Fabio Humberto Maidana',
            'cuit' => '18209989',
            'tipo_documento' => 'DNI',
        ]);

        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => 'B',
            'nro_comprobante' => '0005-00005669',
            'fecha_emision' => '2026-08-03',
            'total' => 189676.17,
        ]);

        VentaItem::create([
            'venta_id' => $venta->id,
            'descripcion' => 'Ítem',
            'cantidad' => 1,
            'precio_unitario' => 156757.16,
            'iva_pct' => '21',
            'subtotal' => 156757.16,
            'subtotal_con_iva' => 189676.16,
        ]);

        $request = Request::create('/informes/contador/ventas/data', 'POST', [
            'mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true,
        ]);

        $filas = app(LibroIvaVentasQuery::class)->detalle($request)->orderBy('emision')->orderBy('id')->get();

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'test_cv_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'test_av_');
        $hComprobantes = fopen($rutaComprobantes, 'w');
        $hAlicuotas = fopen($rutaAlicuotas, 'w');

        app(ComprobantesVentasWriter::class)->escribir($hComprobantes, $hAlicuotas, $filas);

        fclose($hComprobantes);
        fclose($hAlicuotas);

        $lineaGenerada = rtrim(file_get_contents($rutaComprobantes), "\r\n");
        $lineaFixture = P::leerLineas(__DIR__.'/../../../Fixtures/IvaDigital/Comprobantes Ventas Agosto 2026 Res 3685.txt')[0];

        $this->assertSame(266, strlen($lineaGenerada));

        $generado = P::parsear($lineaGenerada, P::LAYOUT_COMPROBANTES_VENTAS);
        $esperado = P::parsear($lineaFixture, P::LAYOUT_COMPROBANTES_VENTAS);

        foreach ($esperado as $campo => $valorEsperado) {
            $this->assertSame(
                $valorEsperado, $generado[$campo],
                "campo '{$campo}': esperado '{$valorEsperado}', obtenido '{$generado[$campo]}'"
            );
        }

        @unlink($rutaComprobantes);
        @unlink($rutaAlicuotas);
    }

    public function test_genera_la_alicuota_de_la_linea_1_del_fixture(): void
    {
        $cliente = Cliente::factory()->create(['cuit' => '18209989', 'tipo_documento' => 'DNI']);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => 'B',
            'nro_comprobante' => '0005-00005669',
            'fecha_emision' => '2026-08-03',
            'total' => 189676.17,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 156757.16, 'iva_pct' => '21',
            'subtotal' => 156757.16, 'subtotal_con_iva' => 189676.16,
        ]);

        $request = Request::create('/informes/contador/ventas/data', 'POST', ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true]);
        $filas = app(LibroIvaVentasQuery::class)->detalle($request)->get();

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'test_cv_');
        $ruta = tempnam(sys_get_temp_dir(), 'test_av_');
        $hComprobantes = fopen($rutaComprobantes, 'w');
        $h = fopen($ruta, 'w');
        app(ComprobantesVentasWriter::class)->escribir($hComprobantes, $h, $filas);
        fclose($hComprobantes);
        fclose($h);

        $generado = P::parsear(rtrim(file_get_contents($ruta), "\r\n"), P::LAYOUT_ALICUOTAS_VENTAS);
        $esperado = P::parsear(P::leerLineas(__DIR__.'/../../../Fixtures/IvaDigital/Alicuotas Ventas Agosto 2026 Res 3685.txt')[0], P::LAYOUT_ALICUOTAS_VENTAS);

        $this->assertSame($esperado['importe_neto_gravado'], $generado['importe_neto_gravado']);
        $this->assertSame($esperado['alicuota'], $generado['alicuota']);
        $this->assertSame($esperado['impuesto_liquidado'], $generado['impuesto_liquidado']);

        @unlink($rutaComprobantes);
        @unlink($ruta);
    }
}
