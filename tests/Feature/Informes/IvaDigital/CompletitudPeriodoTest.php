<?php

namespace Tests\Feature\Informes\IvaDigital;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\IvaDigital\ComprobantesComprasWriter;
use App\Services\Informes\IvaDigital\ComprobantesVentasWriter;
use App\Services\Informes\LibroIvaComprasQuery;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\IvaDigital\ParseoRegistroAnchoFijo as P;
use Tests\TestCase;

/**
 * T023 (spec 086, SC-003/SC-004) — sobre un período conocido, la cantidad de comprobantes emitidos
 * en el TXT coincide con la del informe en pantalla de la spec 077 (ninguno se pierde ni se
 * duplica), y toda fila de alícuota tiene comprobante mientras todo comprobante declara el conteo
 * real de sus alícuotas — ambos consumen las mismas queries (research §3), así que esta comparación
 * garantiza que el archivo y la pantalla cuentan lo mismo.
 */
class CompletitudPeriodoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cantidad_de_comprobantes_de_venta_coincide_con_el_informe_en_pantalla(): void
    {
        $cliente = Cliente::factory()->create(['cuit' => '20111111112', 'tipo_documento' => 'CUIT']);

        foreach (range(1, 5) as $i) {
            $venta = Venta::factory()->create([
                'cliente_id' => $cliente->id, 'tipo_comprobante' => 'B',
                'nro_comprobante' => sprintf('0001-%08d', $i), 'fecha_emision' => '2026-08-10', 'total' => 1210,
            ]);
            VentaItem::create([
                'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
                'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
            ]);
        }

        $request = Request::create('/informes/contador/ventas/data', 'POST', ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true]);
        $filas = app(LibroIvaVentasQuery::class)->detalle($request)->get();

        // "El informe en pantalla" = detalle() en crudo, sin pasar por el TXT — mismo dato, dos
        // consumidores (research §3): la pantalla lo pagina con DataTables, acá se cuenta directo.
        $this->assertCount(5, $filas, 'el informe en pantalla debe ver los 5 comprobantes cargados');

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'test_cv_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'test_av_');
        $h1 = fopen($rutaComprobantes, 'w');
        $h2 = fopen($rutaAlicuotas, 'w');
        app(ComprobantesVentasWriter::class)->escribir($h1, $h2, $filas);
        fclose($h1);
        fclose($h2);

        $lineasComprobantes = P::leerLineas($rutaComprobantes);
        $lineasAlicuotas = P::leerLineas($rutaAlicuotas);

        $this->assertCount(5, $lineasComprobantes, 'el TXT no debe perder ni duplicar comprobantes respecto de la pantalla');
        $this->assertCount(5, $lineasAlicuotas, 'una alícuota por comprobante, ninguna huérfana');

        // Toda fila de alícuota tiene comprobante con la misma clave (tipo|ptovta|numero).
        $clavesComprobantes = array_map(
            fn ($l) => P::parsear($l, P::LAYOUT_COMPROBANTES_VENTAS),
            $lineasComprobantes
        );
        $clavesComprobantes = array_map(fn ($c) => $c['tipo_comprobante'].'|'.$c['punto_venta'].'|'.$c['numero_desde'], $clavesComprobantes);

        foreach ($lineasAlicuotas as $la) {
            $a = P::parsear($la, P::LAYOUT_ALICUOTAS_VENTAS);
            $this->assertContains($a['tipo_comprobante'].'|'.$a['punto_venta'].'|'.$a['numero_comprobante'], $clavesComprobantes);
        }

        // Todo comprobante declara el conteo real: 1 alícuota cargada, cantidad_alicuotas = '1'.
        foreach ($lineasComprobantes as $lc) {
            $c = P::parsear($lc, P::LAYOUT_COMPROBANTES_VENTAS);
            $this->assertSame('1', $c['cantidad_alicuotas']);
        }

        @unlink($rutaComprobantes);
        @unlink($rutaAlicuotas);
    }

    public function test_cantidad_de_comprobantes_de_compra_coincide_con_el_informe_en_pantalla(): void
    {
        $proveedor = Proveedor::factory()->create(['cuit' => '20111111112']);

        foreach (range(1, 3) as $i) {
            $compra = Compra::factory()->create([
                'proveedor_id' => $proveedor->id, 'tipo_comprobante' => 'A',
                'nro_comprobante' => sprintf('0001-%08d', $i), 'fecha_emision' => '2026-08-10', 'total' => 1210,
            ]);
            CompraItem::create([
                'compra_id' => $compra->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
                'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
            ]);
        }

        $request = Request::create('/informes/contador/compras/data', 'POST', ['mes' => 8, 'anio' => 2026]);
        $filas = app(LibroIvaComprasQuery::class)->detalle($request)->get();

        $this->assertCount(3, $filas);

        $rutaComprobantes = tempnam(sys_get_temp_dir(), 'test_cc_');
        $rutaAlicuotas = tempnam(sys_get_temp_dir(), 'test_ac_');
        $h1 = fopen($rutaComprobantes, 'w');
        $h2 = fopen($rutaAlicuotas, 'w');
        app(ComprobantesComprasWriter::class)->escribir($h1, $h2, $filas);
        fclose($h1);
        fclose($h2);

        $this->assertCount(3, P::leerLineas($rutaComprobantes));
        $this->assertCount(3, P::leerLineas($rutaAlicuotas));

        @unlink($rutaComprobantes);
        @unlink($rutaAlicuotas);
    }
}
