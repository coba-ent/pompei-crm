<?php

namespace Tests\Feature\Informes\IvaDigital;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Proveedor;
use App\Services\Informes\IvaDigital\ComprobantesComprasWriter;
use App\Services\Informes\LibroIvaComprasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\IvaDigital\ParseoRegistroAnchoFijo as P;
use Tests\TestCase;

/**
 * T013 (spec 086, FR-022) — excepción nombrada: los 2 comprobantes de compra de MercadoLibre del
 * fixture (`MERCADOLIBRE S.R.L.` y `MELI LOG SRL`) declaran `Cantidad de alícuotas = 0` pese a
 * traer una fila de alícuota al 21% (research Decisión 5, defecto de origen de Contagram). Se
 * testea explícitamente **al revés**: el generador del CRM debe emitir `1`, afirmando la
 * diferencia contra el fixture — así la corrección queda fijada como comportamiento buscado y no
 * se puede revertir por accidente a "coincidir con el fixture a cualquier precio".
 */
class ExcepcionMercadoLibreTest extends TestCase
{
    use RefreshDatabase;

    public function test_comprobante_de_mercadolibre_emite_cantidad_de_alicuotas_1_no_0_como_el_fixture(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'MERCADOLIBRE S.R.L.', 'cuit' => '30703088534']);

        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => 'A',
            'nro_comprobante' => '0011-05104095',
            'fecha_emision' => '2026-08-04',
            'total' => 12502000.53,
        ]);

        CompraItem::create([
            'compra_id' => $compra->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 8695728.28, 'iva_pct' => '21',
            'subtotal' => 8695728.28, 'subtotal_con_iva' => 10521830.22,
        ]);

        $request = Request::create('/informes/contador/compras/data', 'POST', ['mes' => 8, 'anio' => 2026]);
        $filas = app(LibroIvaComprasQuery::class)->detalle($request)->get();

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'test_cc_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'test_ac_');
        $h1 = fopen($rutaComprobantes, 'w');
        $h2 = fopen($rutaAlicuotas, 'w');

        app(ComprobantesComprasWriter::class)->escribir($h1, $h2, $filas);

        fclose($h1);
        fclose($h2);

        $generado = P::parsear(rtrim(file_get_contents($rutaComprobantes), "\r\n"), P::LAYOUT_COMPROBANTES_COMPRAS);

        // El fixture real (contador/Comprobantes Compras...) trae '0' para este mismo proveedor —
        // ver FixtureCaracterizacionTest::test_fixture_tiene_el_defecto_de_origen... — el CRM debe
        // apartarse de eso a propósito.
        $this->assertSame('1', $generado['cantidad_alicuotas'], 'FR-022: debe corregir el defecto de origen, no reproducirlo');

        @unlink($rutaComprobantes);
        @unlink($rutaAlicuotas);
    }
}
