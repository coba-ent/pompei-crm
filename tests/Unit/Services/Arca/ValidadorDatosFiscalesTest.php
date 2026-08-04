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

        $motivo = $validador->validar('A', ['cuit' => '20-12345678-9', 'condicion_iva_codigo' => '1']);

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

    public function test_cliente_identificado_sin_condicion_iva_es_rechazado(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('B', ['cuit' => '20-12345678-9']);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('Condición de IVA', $motivo);
    }

    public function test_receptor_anonimo_sin_condicion_iva_no_es_rechazado(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('B', []);

        $this->assertNull($motivo);
    }

    public function test_rechaza_alicuota_no_soportada(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('B', [], [
            ['neto' => 100.0, 'iva_pct' => 15.0],
        ], 100.0, 15.0);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('no soportada', $motivo);
    }

    public function test_rechaza_inconsistencia_de_importes_fuera_de_tolerancia(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('B', [], [
            ['neto' => 100.0, 'iva_pct' => 21.0],
        ], 100.0, 1500.0);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('IVA calculado', $motivo);
    }

    public function test_acepta_inconsistencia_dentro_de_tolerancia_de_un_centavo(): void
    {
        $validador = new ValidadorDatosFiscales();

        $motivo = $validador->validar('B', [], [
            ['neto' => 100.0, 'iva_pct' => 21.0],
        ], 100.0, 21.005);

        $this->assertNull($motivo);
    }
}
