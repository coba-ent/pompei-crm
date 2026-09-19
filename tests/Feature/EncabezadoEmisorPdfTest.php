<?php

namespace Tests\Feature;

use App\Models\DatosEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 105 — datos de contacto en el encabezado del emisor.
 *
 * Se renderiza la VISTA, no el PDF binario: el output de DomPDF está comprimido y un
 * `assertStringContainsString` sobre él no encuentra el texto aunque esté (research.md, Decisión 3).
 * Por eso los tests de PDF que ya tenía el proyecto son smoke tests de `assertOk()` y no pueden
 * cubrir esto: un `@if` mal escrito genera un PDF perfectamente válido al que le falta un dato.
 */
class EncabezadoEmisorPdfTest extends TestCase
{
    use RefreshDatabase;

    /** Las cinco vistas de comprobante que incluyen el encabezado del emisor (FR-004). */
    private const VISTAS_CON_ENCABEZADO = [
        'ventas.pdf',
        'presupuestos.pdf',
        'notas-credito-debito.pdf',
        'remitos.pdf',
        'recibos.pdf',
    ];

    private function encabezado(array $atributos = []): string
    {
        $datosEmpresa = DatosEmpresa::create($atributos + [
            'razon_social' => 'Pompei Sanitarios',
            'cuit' => '20111111112',
            'domicilio_fiscal' => 'Av. Siempre Viva 123',
            'condicion_iva' => 'Responsable Inscripto',
        ]);

        return view('pdf.partials.encabezado-emisor', compact('datosEmpresa'))->render();
    }

    public function test_imprime_telefono_y_sitio_web_cuando_estan_cargados(): void
    {
        $html = $this->encabezado([
            'telefono' => '11 5555-5555 / WhatsApp 11 4444-4444',
            'sitio_web' => 'www.pompeisanitarios.com.ar',
        ]);

        $this->assertStringContainsString('11 5555-5555 / WhatsApp 11 4444-4444', $html);
        $this->assertStringContainsString('www.pompeisanitarios.com.ar', $html);

        // La "dirección" del pedido del cliente: ya salía, y tiene que seguir saliendo.
        $this->assertStringContainsString('Av. Siempre Viva 123', $html);
    }

    /** FR-005: un campo vacío no imprime NI su etiqueta. El caso que más fácil se pasa por alto. */
    public function test_sin_datos_de_contacto_no_imprime_ni_la_etiqueta(): void
    {
        $html = $this->encabezado();

        $this->assertStringNotContainsString('Tel:', $html);

        // El resto del encabezado se sigue viendo igual que antes de la feature.
        $this->assertStringContainsString('Pompei Sanitarios', $html);
        $this->assertStringContainsString('CUIT: 20111111112', $html);
    }

    /** Edge case: cargado uno solo, se imprime uno solo. */
    public function test_con_un_solo_dato_cargado_imprime_solo_ese(): void
    {
        $html = $this->encabezado(['telefono' => '11 5555-5555']);

        $this->assertStringContainsString('Tel: 11 5555-5555', $html);
        $this->assertStringNotContainsString('www.', $html);
    }

    /** FR-007: se imprime tal cual se cargó, sin reformatear ni normalizar. */
    public function test_imprime_los_valores_tal_como_fueron_cargados(): void
    {
        $html = $this->encabezado([
            'telefono' => '  +54 9 11 5555-5555 (sólo WhatsApp)  ',
            'sitio_web' => 'https://Pompei-Sanitarios.COM.ar/tienda',
        ]);

        $this->assertStringContainsString('+54 9 11 5555-5555 (sólo WhatsApp)', $html);
        $this->assertStringContainsString('https://Pompei-Sanitarios.COM.ar/tienda', $html);

        // Contrato R2: es un documento impreso, la web no se convierte en link.
        $this->assertStringNotContainsString('<a href', $html);
    }

    /** FR-006: sin ficha de empresa el encabezado se omite entero, sin romper nada. */
    public function test_sin_datos_de_empresa_no_renderiza_encabezado(): void
    {
        $html = view('pdf.partials.encabezado-emisor', ['datosEmpresa' => null])->render();

        $this->assertSame('', trim($html));
    }

    /**
     * FR-004: los CINCO comprobantes comparten el mismo encabezado.
     *
     * Este es el test que impide que la consistencia entre comprobantes se rompa en el futuro: si
     * alguien le arma a un comprobante su propio encabezado, o le saca el `@include`, esto falla.
     */
    public function test_los_cinco_comprobantes_incluyen_el_encabezado_compartido(): void
    {
        foreach (self::VISTAS_CON_ENCABEZADO as $vista) {
            $ruta = resource_path('views/'.str_replace('.', '/', $vista).'.blade.php');

            $this->assertFileExists($ruta, "No existe la vista {$vista}.");
            $this->assertStringContainsString(
                "@include('pdf.partials.encabezado-emisor')",
                file_get_contents($ruta),
                "La vista {$vista} dejó de incluir el encabezado compartido del emisor: los comprobantes "
                .'ya no muestran todos los mismos datos del negocio (FR-004).'
            );
        }
    }
}
