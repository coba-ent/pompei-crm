<?php

namespace Tests\Unit;

use App\Services\Arca\DeterminadorComprobante;
use PHPUnit\Framework\TestCase;

class DeterminadorComprobanteTest extends TestCase
{
    private const RI = '1';

    private const MONOTRIBUTO = '6';

    private const CONSUMIDOR_FINAL = '5';

    private const EXENTO = '4';

    private function determinador(): DeterminadorComprobante
    {
        return new DeterminadorComprobante;
    }

    public function test_ri_a_ri_es_factura_a_sin_ley_27618(): void
    {
        $resultado = $this->determinador()->determinar(self::RI, self::RI);

        $this->assertSame('A', $resultado['tipo_comprobante']);
        $this->assertSame(1, $resultado['cbte_tipo_afip']);
        $this->assertFalse($resultado['aplica_ley_27618']);
    }

    public function test_ri_a_monotributo_es_factura_a_con_ley_27618(): void
    {
        $resultado = $this->determinador()->determinar(self::RI, self::MONOTRIBUTO);

        $this->assertSame('A', $resultado['tipo_comprobante']);
        $this->assertSame(1, $resultado['cbte_tipo_afip']);
        $this->assertTrue($resultado['aplica_ley_27618']);
    }

    public function test_ri_a_consumidor_final_es_factura_b(): void
    {
        $resultado = $this->determinador()->determinar(self::RI, self::CONSUMIDOR_FINAL);

        $this->assertSame('B', $resultado['tipo_comprobante']);
        $this->assertSame(6, $resultado['cbte_tipo_afip']);
        $this->assertFalse($resultado['aplica_ley_27618']);
    }

    public function test_ri_a_exento_es_factura_b(): void
    {
        $resultado = $this->determinador()->determinar(self::RI, self::EXENTO);

        $this->assertSame('B', $resultado['tipo_comprobante']);
        $this->assertSame(6, $resultado['cbte_tipo_afip']);
    }

    public function test_monotributista_a_cualquiera_es_factura_c(): void
    {
        foreach ([self::RI, self::MONOTRIBUTO, self::CONSUMIDOR_FINAL, self::EXENTO] as $codigoCliente) {
            $resultado = $this->determinador()->determinar(self::MONOTRIBUTO, $codigoCliente);
            $this->assertSame('C', $resultado['tipo_comprobante']);
            $this->assertSame(11, $resultado['cbte_tipo_afip']);
            $this->assertFalse($resultado['aplica_ley_27618']);
        }
    }

    public function test_exento_a_cualquiera_es_factura_c(): void
    {
        foreach ([self::RI, self::MONOTRIBUTO, self::CONSUMIDOR_FINAL, self::EXENTO] as $codigoCliente) {
            $resultado = $this->determinador()->determinar(self::EXENTO, $codigoCliente);
            $this->assertSame('C', $resultado['tipo_comprobante']);
        }
    }
}
