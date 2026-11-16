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

    /** Producción 16/11/2026: B a un monotributista → ARCA 10243. Se ataja antes con un mensaje claro. */
    public function test_factura_b_a_monotributista_se_rechaza_antes_de_arca(): void
    {
        $motivo = (new ValidadorDatosFiscales())->validar('B', ['cuit' => '20385332166', 'condicion_iva_codigo' => '6']);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('Monotributista', $motivo);
        $this->assertStringContainsString('cambiá el comprobante a A', $motivo);
    }

    public function test_factura_b_a_responsable_inscripto_se_rechaza_antes_de_arca(): void
    {
        $motivo = (new ValidadorDatosFiscales())->validar('B', ['cuit' => '20928490530', 'condicion_iva_codigo' => '1']);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('Responsable Inscripto', $motivo);
    }

    public function test_factura_a_a_monotributista_pasa(): void
    {
        $this->assertNull((new ValidadorDatosFiscales())->validar('A', ['cuit' => '20385332166', 'condicion_iva_codigo' => '6']));
    }

    public function test_factura_a_a_consumidor_final_se_rechaza_antes_de_arca(): void
    {
        $motivo = (new ValidadorDatosFiscales())->validar('A', ['cuit' => '20304050607', 'condicion_iva_codigo' => '5']);

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('cambiá el comprobante a B', $motivo);
    }

    public function test_factura_b_a_consumidor_final_pasa(): void
    {
        $this->assertNull((new ValidadorDatosFiscales())->validar('B', ['dni' => '30111222', 'condicion_iva_codigo' => '5']));
    }
}
