<?php

namespace Tests\Unit;

use App\Services\Arca\MapeadorComprobante;
use PHPUnit\Framework\TestCase;

/**
 * Códigos ARCA nuevos para IVA Digital (spec 086, research §6): deben producir exactamente los
 * valores observados en el fixture de `contador/` (T007).
 */
class MapeadorComprobanteIvaDigitalTest extends TestCase
{
    private MapeadorComprobante $mapeador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapeador = new MapeadorComprobante;
    }

    public function test_documento_receptor_sin_identificar_devuelve_codigo_99(): void
    {
        [$docTipo, $docNro] = $this->mapeador->documentoReceptor([]);

        $this->assertSame(99, $docTipo);
        $this->assertSame('0', $docNro);
    }

    public function test_documento_receptor_dni_devuelve_codigo_96(): void
    {
        [$docTipo] = $this->mapeador->documentoReceptor(['tipo_documento' => 'DNI', 'documento' => '18209989']);

        $this->assertSame(96, $docTipo);
    }

    public function test_documento_vendedor_cuit_devuelve_codigo_80(): void
    {
        [$docTipo, $docNro] = $this->mapeador->documentoVendedor('30-50199107-0');

        $this->assertSame(80, $docTipo);
        $this->assertSame('30501991070', $docNro);
    }

    public function test_documento_vendedor_sin_cuit_devuelve_codigo_99(): void
    {
        [$docTipo, $docNro] = $this->mapeador->documentoVendedor(null);

        $this->assertSame(99, $docTipo);
        $this->assertSame('0', $docNro);
    }

    public function test_cbte_tipo_factura_b_devuelve_006(): void
    {
        $this->assertSame(6, $this->mapeador->cbteTipo('B'));
    }

    public function test_cbte_tipo_factura_a_devuelve_001(): void
    {
        $this->assertSame(1, $this->mapeador->cbteTipo('A'));
    }

    public function test_cbte_tipo_nota_debito_a_devuelve_002(): void
    {
        $this->assertSame(2, $this->mapeador->cbteTipo('A', 'debito'));
    }

    public function test_cbte_tipo_nota_credito_a_devuelve_003(): void
    {
        $this->assertSame(3, $this->mapeador->cbteTipo('A', 'credito'));
    }

    public function test_codigo_alicuota_21_devuelve_0005(): void
    {
        $this->assertSame(5, $this->mapeador->codigoAlicuotaIva(21.0));
    }
}
