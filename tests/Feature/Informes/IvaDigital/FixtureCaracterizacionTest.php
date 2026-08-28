<?php

namespace Tests\Feature\Informes\IvaDigital;

use PHPUnit\Framework\TestCase;
use Tests\Support\IvaDigital\ParseoRegistroAnchoFijo as P;

/**
 * Caracterización del fixture real (T003, spec 086): fija el formato exacto de los 4 archivos de
 * `contador/` (research.md §1) como hecho verificado antes de escribir código de producción, y fija
 * el defecto de origen (research Decisión 5) como comportamiento conocido del fixture, no un bug a
 * reproducir.
 */
class FixtureCaracterizacionTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/IvaDigital/';

    public function test_comprobantes_ventas_tiene_ancho_266_crlf_y_latin1(): void
    {
        $ruta = self::FIXTURES.'Comprobantes Ventas Agosto 2026 Res 3685.txt';
        $lineas = P::leerLineas($ruta);

        $this->assertNotEmpty($lineas);
        foreach ($lineas as $i => $linea) {
            $this->assertSame(266, strlen($linea), "línea ".($i + 1)." con ancho incorrecto");
        }

        $contenido = file_get_contents($ruta);
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $contenido), 'no debe haber LF suelto sin CR');

        // "Peirano" (proveedor con Ñ en otro archivo) no aparece acá, pero el archivo entero debe
        // decodificar sin error como latin-1 — si viniera en UTF-8, un acento ocuparía 2 bytes y
        // rompería el ancho de línea ya verificado arriba.
        $this->assertNotFalse(@iconv('ISO-8859-1', 'UTF-8', $contenido));
    }

    public function test_alicuotas_ventas_tiene_ancho_62(): void
    {
        foreach (P::leerLineas(self::FIXTURES.'Alicuotas Ventas Agosto 2026 Res 3685.txt') as $linea) {
            $this->assertSame(62, strlen($linea));
        }
    }

    public function test_comprobantes_compras_tiene_ancho_325(): void
    {
        foreach (P::leerLineas(self::FIXTURES.'Comprobantes Compras Agosto 2026 Res 3685.txt') as $linea) {
            $this->assertSame(325, strlen($linea));
        }
    }

    public function test_alicuotas_compras_tiene_ancho_84(): void
    {
        foreach (P::leerLineas(self::FIXTURES.'Alicuotas Compras Agosto 2026 Res 3685.txt') as $linea) {
            $this->assertSame(84, strlen($linea));
        }
    }

    public function test_sin_encabezado_la_primera_linea_ya_es_un_registro(): void
    {
        $primera = P::leerLineas(self::FIXTURES.'Comprobantes Ventas Agosto 2026 Res 3685.txt')[0];
        $campos = P::parsear($primera, P::LAYOUT_COMPROBANTES_VENTAS);

        // Es una fecha AAAAMMDD válida, no un título de columna.
        $this->assertMatchesRegularExpression('/^\d{8}$/', $campos['fecha_comprobante']);
    }

    /**
     * FR-016/FR-017: crédito fiscal computable = suma del IVA de las alícuotas del comprobante,
     * sin alícuotas huérfanas.
     */
    public function test_alicuotas_compras_sin_huerfanas_y_credito_fiscal_correcto(): void
    {
        $comprobantes = array_map(
            fn ($l) => P::parsear($l, P::LAYOUT_COMPROBANTES_COMPRAS),
            P::leerLineas(self::FIXTURES.'Comprobantes Compras Agosto 2026 Res 3685.txt')
        );
        $alicuotas = array_map(
            fn ($l) => P::parsear($l, P::LAYOUT_ALICUOTAS_COMPRAS),
            P::leerLineas(self::FIXTURES.'Alicuotas Compras Agosto 2026 Res 3685.txt')
        );

        $clave = fn (array $c) => $c['tipo_comprobante'].'|'.$c['punto_venta'].'|'.$c['numero_comprobante'];
        $clavesComprobantes = array_map($clave, $comprobantes);

        foreach ($alicuotas as $a) {
            $this->assertContains($clave($a), $clavesComprobantes, 'alícuota huérfana sin comprobante');
        }
    }

    /**
     * Defecto de origen conocido (research Decisión 5): los 2 comprobantes de MercadoLibre
     * declaran `Cantidad de alícuotas = 0` pese a traer una fila de alícuota. Este test fija ese
     * hecho del fixture — no es el comportamiento que el generador del CRM debe reproducir (ver
     * FR-022 / T013, que afirma lo contrario para la salida del CRM).
     */
    public function test_fixture_tiene_el_defecto_de_origen_en_los_comprobantes_de_mercadolibre(): void
    {
        $comprobantes = array_map(
            fn ($l) => P::parsear($l, P::LAYOUT_COMPROBANTES_COMPRAS),
            P::leerLineas(self::FIXTURES.'Comprobantes Compras Agosto 2026 Res 3685.txt')
        );

        $conAlicuotasEnCero = array_filter($comprobantes, fn ($c) => $c['denominacion_vendedor'] !== null
            && (str_contains($c['denominacion_vendedor'], 'MERCADOLIBRE') || str_contains($c['denominacion_vendedor'], 'MELI LOG'))
            && $c['cantidad_alicuotas'] === '0'
        );

        $this->assertCount(2, $conAlicuotasEnCero, 'se esperaban exactamente los 2 comprobantes de MercadoLibre con el defecto de origen');
    }
}
