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

    /**
     * Regresión: el cliente guarda el DNI en la misma columna que el CUIT, y mandarlo como
     * DocTipo 80 hace que ARCA lo rechace por no encontrarlo en el padrón de CUIT (venta 24447).
     */
    public function test_mapea_dni_como_doc_tipo_96_y_no_como_cuit(): void
    {
        $mapeador = new MapeadorComprobante();

        $resultado = $mapeador->mapear([
            'tipo_comprobante' => 'B',
            'punto_venta' => 9,
            'numero' => 7,
            'fecha' => '2026-08-14',
            'cliente' => ['tipo_documento' => 'DNI', 'documento' => '27501362', 'condicion_iva_codigo' => '5'],
            'neto' => 64152.89,
            'iva' => 13472.11,
            'total' => 77625.0,
        ]);

        $detalle = $resultado['FeDetReq']['FECAEDetRequest'];
        $this->assertSame(96, $detalle['DocTipo']);
        $this->assertSame('27501362', $detalle['DocNro']);
    }

    public function test_mapea_cada_tipo_de_documento_a_su_doc_tipo_de_arca(): void
    {
        $mapeador = new MapeadorComprobante();

        $docTipoPara = function (string $tipoDocumento) use ($mapeador): int {
            $resultado = $mapeador->mapear([
                'tipo_comprobante' => 'B',
                'punto_venta' => 1,
                'numero' => 1,
                'fecha' => '2026-08-14',
                'cliente' => ['tipo_documento' => $tipoDocumento, 'documento' => '20123456789'],
                'neto' => 100.0,
                'iva' => 21.0,
                'total' => 121.0,
            ]);

            return $resultado['FeDetReq']['FECAEDetRequest']['DocTipo'];
        };

        $this->assertSame(80, $docTipoPara('CUIT'));
        $this->assertSame(86, $docTipoPara('CUIL'));
        $this->assertSame(87, $docTipoPara('CDI'));
        $this->assertSame(94, $docTipoPara('Pasaporte'));
        $this->assertSame(96, $docTipoPara('DNI'));
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

    public function test_arma_un_unico_bloque_alic_iva_para_alicuota_unica(): void
    {
        $mapeador = new MapeadorComprobante();

        $resultado = $mapeador->mapear([
            'tipo_comprobante' => 'B',
            'punto_venta' => 1,
            'numero' => 1,
            'fecha' => '2026-08-14',
            'cliente' => [],
            'neto' => 1000.0,
            'iva' => 210.0,
            'total' => 1210.0,
            'items' => [
                ['neto' => 1000.0, 'iva_pct' => 21.0],
            ],
        ]);

        $alicIva = $resultado['FeDetReq']['FECAEDetRequest']['Iva']['AlicIva'];
        $this->assertArrayHasKey('Id', $alicIva);
        $this->assertSame(5, $alicIva['Id']);
        $this->assertSame(1000.0, $alicIva['BaseImp']);
        $this->assertSame(210.0, $alicIva['Importe']);
    }

    public function test_arma_dos_bloques_alic_iva_consistentes_para_alicuotas_mixtas(): void
    {
        $mapeador = new MapeadorComprobante();

        $resultado = $mapeador->mapear([
            'tipo_comprobante' => 'B',
            'punto_venta' => 9,
            'numero' => 1,
            'fecha' => '2026-08-14',
            'cliente' => [],
            'neto' => 110000.0,
            'iva' => 23100.0,
            'total' => 133100.0,
            'items' => [
                ['neto' => 100000.0, 'iva_pct' => 21.0],
                ['neto' => 10000.0, 'iva_pct' => 10.5],
            ],
        ]);

        $alicIva = $resultado['FeDetReq']['FECAEDetRequest']['Iva']['AlicIva'];
        $this->assertCount(2, $alicIva);

        $bloque21 = collect($alicIva)->firstWhere('Id', 5);
        $bloque105 = collect($alicIva)->firstWhere('Id', 4);

        $this->assertSame(100000.0, $bloque21['BaseImp']);
        $this->assertSame(21000.0, $bloque21['Importe']);
        $this->assertSame(10000.0, $bloque105['BaseImp']);
        $this->assertSame(1050.0, $bloque105['Importe']);
    }

    public function test_incluye_condicion_iva_receptor_del_cliente(): void
    {
        $mapeador = new MapeadorComprobante();

        $resultado = $mapeador->mapear([
            'tipo_comprobante' => 'A',
            'punto_venta' => 1,
            'numero' => 1,
            'fecha' => '2026-08-14',
            'cliente' => ['cuit' => '20-12345678-9', 'condicion_iva_codigo' => '1'],
            'neto' => 1000.0,
            'iva' => 210.0,
            'total' => 1210.0,
        ]);

        $this->assertSame(1, $resultado['FeDetReq']['FECAEDetRequest']['CondicionIVAReceptorId']);
    }

    public function test_incluye_condicion_iva_consumidor_final_por_defecto_para_receptor_anonimo(): void
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

        $this->assertSame(5, $resultado['FeDetReq']['FECAEDetRequest']['CondicionIVAReceptorId']);
    }
}
