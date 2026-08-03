<?php

namespace Tests\Unit\Services\Arca;

use App\Services\Arca\ValidadorDatosFiscales;
use PHPUnit\Framework\TestCase;

class ValidadorDatosFiscalesTest extends TestCase
{
    public function test_factura_a_requiere_cuit(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('A', []);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('CUIT', $motivo);
    }

    public function test_factura_a_con_cuit_invalido_es_rechazada(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('A', ['cuit' => '123']);

        $this->assertNotNull($motivo);
    }

    public function test_factura_a_con_cuit_valido_pasa(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('A', ['cuit' => '20-12345678-9']);

        $this->assertNull($motivo);
    }

    public function test_factura_b_no_requiere_cuit(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('B', []);

        $this->assertNull($motivo);
    }

    public function test_factura_c_no_requiere_cuit(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('C', []);

        $this->assertNull($motivo);
    }
}
