<?php

namespace Tests\Unit;

use App\Rules\CuitValido;
use PHPUnit\Framework\TestCase;

class CuitValidoTest extends TestCase
{
    public function test_acepta_cuit_valido(): void
    {
        $this->assertTrue(CuitValido::esValido('20111111112'));
        $this->assertTrue(CuitValido::esValido('27111111117'));
    }

    public function test_rechaza_digito_verificador_invalido(): void
    {
        $this->assertFalse(CuitValido::esValido('20111111113'));
    }

    public function test_rechaza_longitud_invalida(): void
    {
        $this->assertFalse(CuitValido::esValido('2011111111'));
        $this->assertFalse(CuitValido::esValido('201111111123'));
    }

    public function test_rechaza_prefijo_invalido(): void
    {
        // Prefijo 99 no está entre los válidos.
        $this->assertFalse(CuitValido::esValido('99111111112'));
    }

    public function test_rechaza_no_numerico(): void
    {
        $this->assertFalse(CuitValido::esValido('20-11111111-2'));
        $this->assertFalse(CuitValido::esValido('abcdefghijk'));
    }
}
