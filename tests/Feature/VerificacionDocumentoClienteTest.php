<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint del botón "Verificar" (US1, spec 014): validación local de
 * CUIT/CUIL, sin persistir nada ni consultar servicios externos (FR-002).
 */
class VerificacionDocumentoClienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_cuit_valido_devuelve_aplica_y_valido_true(): void
    {
        $response = $this->getJson(route('clientes.verificar-documento', [
            'tipo_documento' => 'CUIT', 'numero' => '20123456786',
        ]));

        $response->assertOk()->assertJson(['aplica' => true, 'valido' => true]);
    }

    public function test_cuit_invalido_devuelve_aplica_true_valido_false_con_el_mismo_mensaje_del_guardado(): void
    {
        $response = $this->getJson(route('clientes.verificar-documento', [
            'tipo_documento' => 'CUIT', 'numero' => '20304050600',
        ]));

        $response->assertOk()->assertJson([
            'aplica' => true,
            'valido' => false,
            'mensaje' => 'El CUIT ingresado no es válido.',
        ]);
    }

    public function test_tipo_documento_dni_no_aplica(): void
    {
        $response = $this->getJson(route('clientes.verificar-documento', [
            'tipo_documento' => 'DNI', 'numero' => '30712345',
        ]));

        $response->assertOk()->assertExactJson(['aplica' => false]);
    }

    public function test_numero_vacio_no_aplica(): void
    {
        $response = $this->getJson(route('clientes.verificar-documento', [
            'tipo_documento' => 'CUIT', 'numero' => '',
        ]));

        $response->assertOk()->assertExactJson(['aplica' => false]);
    }

    public function test_falta_un_query_param_devuelve_422(): void
    {
        $response = $this->getJson(route('clientes.verificar-documento', [
            'tipo_documento' => 'CUIT',
        ]));

        $response->assertStatus(422);
    }

    public function test_cuil_se_trata_igual_que_cuit(): void
    {
        $response = $this->getJson(route('clientes.verificar-documento', [
            'tipo_documento' => 'CUIL', 'numero' => '20123456786',
        ]));

        $response->assertOk()->assertJson(['aplica' => true, 'valido' => true]);
    }

    public function test_numero_con_guiones_se_normaliza_antes_de_validar(): void
    {
        $response = $this->getJson(route('clientes.verificar-documento', [
            'tipo_documento' => 'CUIT', 'numero' => '20-12345678-6',
        ]));

        $response->assertOk()->assertJson(['aplica' => true, 'valido' => true]);
    }
}
