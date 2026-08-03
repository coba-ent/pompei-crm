<?php

namespace Tests\Unit\Services\Arca;

use App\Services\Arca\MapeadorComprobante;
use PHPUnit\Framework\TestCase;

class MapeadorComprobanteTest extends TestCase
{
    public function test_mapea_cbte_tipo_factura_por_tipo_comprobante(): void
    {
        $mapeador = new MapeadorComprobante();

        $this->assertSame(1, $mapeador->cbteTipo('A'));
        $this->assertSame(6, $mapeador->cbteTipo('B'));
        $this->assertSame(11, $mapeador->cbteTipo('C'));
    }

    public function test_mapea_cbte_tipo_nota_de_credito_y_debito(): void
    {
        $mapeador = new MapeadorComprobante();

        $this->assertSame(3, $mapeador->cbteTipo('A', 'credito'));
        $this->assertSame(8, $mapeador->cbteTipo('B', 'credito'));
        $this->assertSame(2, $mapeador->cbteTipo('A', 'debito'));
        $this->assertSame(7, $mapeador->cbteTipo('B', 'debito'));
    }

    public function test_tipo_comprobante_desconocido_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MapeadorComprobante())->cbteTipo('Z');
    }

    public function test_mapea_datos_de_venta_a_fecaereq_con_cuit(): void
    {
        $mapeador = new MapeadorComprobante();

        $resultado = $mapeador->mapear([
            'tipo_comprobante' => 'A',
            'punto_venta' => 3,
            'numero' => 15,
            'fecha' => '2026-08-14',
            'cliente' => ['cuit' => '20-12345678-9'],
            'neto' => 1000.0,
            'iva' => 210.0,
            'total' => 1210.0,
        ]);

        $this->assertSame(1, $resultado['FeCabReq']['CbteTipo']);
        $this->assertSame(3, $resultado['FeCabReq']['PtoVta']);

        $detalle = $resultado['FeDetReq']['FECAEDetRequest'];
        $this->assertSame(80, $detalle['DocTipo']);
        $this->assertSame('20123456789', $detalle['DocNro']);
        $this->assertSame('20260814', $detalle['CbteFch']);
        $this->assertSame(15, $detalle['CbteDesde']);
        $this->assertSame(15, $detalle['CbteHasta']);
        $this->assertSame(1000.0, $detalle['ImpNeto']);
        $this->assertSame(210.0, $detalle['ImpIVA']);
        $this->assertSame(1210.0, $detalle['ImpTotal']);
        $this->assertSame(210.0, $detalle['Iva']['AlicIva']['Importe']);
    }

    public function test_mapea_consumidor_final_sin_documento(): void
    {
        $mapeador = new MapeadorComprobante();

        $resultado = $mapeador->mapear([
            'tipo_comprobante' => 'B',
            'punto_venta' => 1,
            'numero' => 1,
            'fecha' => '2026-08-14',
            'cliente' => [],
            'neto' => 100.0,
            'iva' => 21.0,
            'total' => 121.0,
        ]);

        $detalle = $resultado['FeDetReq']['FECAEDetRequest'];
        $this->assertSame(99, $detalle['DocTipo']);
        $this->assertSame('0', $detalle['DocNro']);
    }

    public function test_incluye_comprobante_asociado_para_nota_de_credito(): void
    {
        $mapeador = new MapeadorComprobante();

        $resultado = $mapeador->mapear([
            'tipo_comprobante' => 'B',
            'tipo_nota' => 'credito',
            'punto_venta' => 1,
            'numero' => 2,
            'fecha' => '2026-08-14',
            'cliente' => [],
            'neto' => 100.0,
            'iva' => 21.0,
            'total' => 121.0,
            'comprobante_ajustado' => ['tipo' => 6, 'punto_venta' => 1, 'numero' => 1],
        ]);

        $this->assertSame(8, $resultado['FeCabReq']['CbteTipo']);
        $this->assertSame(6, $resultado['FeDetReq']['FECAEDetRequest']['CbtesAsoc']['CbteAsoc']['Tipo']);
    }
}
