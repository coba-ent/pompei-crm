<?php

namespace Tests\Unit\Support;

use App\Support\ArchivosFiscales\RegistroAnchoFijo;
use PHPUnit\Framework\TestCase;

class RegistroAnchoFijoTest extends TestCase
{
    private RegistroAnchoFijo $registro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registro = new RegistroAnchoFijo;
    }

    public function test_numerico_rellena_con_ceros_a_la_izquierda(): void
    {
        $this->assertSame('00000000000000005669', $this->registro->numerico(5669, 20));
    }

    public function test_importe_multiplica_por_100_y_redondea_decimales_hacia_arriba(): void
    {
        $this->assertSame('000000018967618', $this->registro->importe(189676.176, 15));
    }

    public function test_importe_con_ancho_suficiente_no_trunca_ni_pierde_precision(): void
    {
        $this->assertSame('000000018967617', $this->registro->importe(189676.17, 15));
    }

    public function test_alfanumerico_trunca_texto_mas_largo_que_el_campo_sin_puntos_suspensivos(): void
    {
        $resultado = $this->registro->alfanumerico('ASE SOCIEDADES Y EMPRESAS SOCIEDAD ANONIMA', 30);

        $this->assertSame(30, strlen($resultado));
        $this->assertSame('ASE SOCIEDADES Y EMPRESAS SOCI', $resultado);
    }

    public function test_alfanumerico_conserva_el_ancho_en_bytes_con_enie_y_acentos(): void
    {
        $resultado = $this->registro->alfanumerico('Peirano Muñoz', 30);

        $this->assertSame(30, strlen($resultado));
    }

    public function test_alfanumerico_con_valor_nulo_devuelve_espacios_del_ancho_pedido(): void
    {
        $this->assertSame(str_repeat(' ', 16), $this->registro->alfanumerico(null, 16));
    }

    public function test_fecha_formatea_a_aaaammdd(): void
    {
        $this->assertSame('20260803', $this->registro->fecha('2026-08-03'));
    }

    public function test_alicuota_formatea_a_4_digitos(): void
    {
        $this->assertSame('0005', $this->registro->alicuota(5));
    }

    public function test_linea_concatena_los_campos(): void
    {
        $this->assertSame('ABCDEF', $this->registro->linea(['AB', 'CD', 'EF'], 6));
    }

    public function test_linea_lanza_si_el_ancho_total_no_coincide_con_el_esperado(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->registro->linea(['AB', 'CD'], 5);
    }
}
