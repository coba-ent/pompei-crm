<?php

namespace Tests\Feature\Informes\IvaDigital;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\IvaDigital\IvaDigitalPaquete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * T014-T017 (spec 086) — el paquete ZIP: nombres exactos (FR-002/FR-003), período vacío sin
 * excepción (FR-005), y determinismo byte a byte (SC-005).
 */
class IvaDigitalPaqueteTest extends TestCase
{
    use RefreshDatabase;

    private function request(): Request
    {
        return Request::create('/', 'POST', ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true]);
    }

    public function test_nombre_del_zip_usa_el_mes_en_castellano(): void
    {
        $nombre = app(IvaDigitalPaquete::class)->nombreZip(8, 2026);

        $this->assertSame('IVA Digital Ventas y Compras Agosto 2026.zip', $nombre);
    }

    public function test_zip_contiene_exactamente_4_entradas_con_los_nombres_exactos(): void
    {
        $ruta = app(IvaDigitalPaquete::class)->generar($this->request(), 8, 2026);

        $zip = new \ZipArchive;
        $zip->open($ruta);

        $this->assertSame(4, $zip->numFiles);

        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }

        $this->assertContains('Comprobantes Ventas Agosto 2026 Res 3685.txt', $nombres);
        $this->assertContains('Alicuotas Ventas Agosto 2026 Res 3685.txt', $nombres);
        $this->assertContains('Comprobantes Compras Agosto 2026 Res 3685.txt', $nombres);
        $this->assertContains('Alicuotas Compras Agosto 2026 Res 3685.txt', $nombres);

        $zip->close();
        @unlink($ruta);
    }

    /** FR-005: período sin comprobantes igual arma un ZIP válido con 4 archivos de 0 bytes. */
    public function test_periodo_vacio_genera_zip_valido_con_4_archivos_de_0_bytes(): void
    {
        // Enero 2026, no el default de $this->request(): el ZIP lee el período de la request
        // (mes/anio), no de los argumentos de generar() — que sólo nombran los archivos.
        $requestEnero = Request::create('/', 'POST', ['mes' => 1, 'anio' => 2026, 'arca' => true, 'manuales' => true]);
        $ruta = app(IvaDigitalPaquete::class)->generar($requestEnero, 1, 2026);

        $zip = new \ZipArchive;
        $abierto = $zip->open($ruta);

        $this->assertTrue($abierto === true);
        $this->assertSame(4, $zip->numFiles);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $this->assertSame(0, $stat['size']);
        }

        $zip->close();
        @unlink($ruta);
    }

    /**
     * T021 (verificación manual con MySQL): una compra "Sin Factura" (`tipo_comprobante` NULL o
     * `'S'`, opción del formulario para gastos sin comprobante fiscal real) no tiene tipo/número
     * de comprobante que declarar ante ARCA — se excluye del período en vez de romper la
     * generación o emitir campos vacíos.
     */
    public function test_compra_sin_factura_no_rompe_la_generacion_y_queda_excluida(): void
    {
        $proveedor = Proveedor::factory()->create();

        Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => null,
            'nro_comprobante' => null,
            'fecha_emision' => '2026-08-15',
            'total' => 500,
        ]);
        Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => 'S',
            'nro_comprobante' => null,
            'fecha_emision' => '2026-08-16',
            'total' => 700,
        ]);

        $ruta = app(IvaDigitalPaquete::class)->generar($this->request(), 8, 2026);

        $zip = new \ZipArchive;
        $zip->open($ruta);
        $contenido = $zip->getFromName('Comprobantes Compras Agosto 2026 Res 3685.txt');
        $zip->close();

        $this->assertSame('', $contenido);

        @unlink($ruta);
    }

    /** SC-005: generar el mismo período dos veces produce bytes idénticos (contenido, no el zip entero: el zip trae metadata de fecha). */
    public function test_generar_el_mismo_periodo_dos_veces_da_contenido_identico(): void
    {
        $cliente = Cliente::factory()->create(['cuit' => '20111111112', 'tipo_documento' => 'CUIT']);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'tipo_comprobante' => 'B',
            'nro_comprobante' => '0001-00000001', 'fecha_emision' => '2026-08-05', 'total' => 1210,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);

        $ruta1 = app(IvaDigitalPaquete::class)->generar($this->request(), 8, 2026);
        $ruta2 = app(IvaDigitalPaquete::class)->generar($this->request(), 8, 2026);

        $contenido = fn (string $rutaZip) => (function () use ($rutaZip) {
            $zip = new \ZipArchive;
            $zip->open($rutaZip);
            $todo = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $todo .= $zip->getNameIndex($i).':'.$zip->getFromIndex($i);
            }
            $zip->close();

            return $todo;
        })();

        $this->assertSame($contenido($ruta1), $contenido($ruta2));

        @unlink($ruta1);
        @unlink($ruta2);
    }
}
