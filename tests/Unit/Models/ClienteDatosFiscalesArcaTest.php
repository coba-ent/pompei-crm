<?php

namespace Tests\Unit\Models;

use App\Models\Cliente;
use App\Models\CondicionIva;
use PHPUnit\Framework\TestCase;

class ClienteDatosFiscalesArcaTest extends TestCase
{
    private function cliente(?string $tipoDocumento, ?string $documento, string $codigoAfip = '5'): Cliente
    {
        $cliente = new Cliente(['cuit' => $documento, 'tipo_documento' => $tipoDocumento]);
        $cliente->setRelation('condicionIva', new CondicionIva(['codigo_afip' => $codigoAfip]));

        return $cliente;
    }

    /** Regresión: un cliente con DNI no debe informar `cuit`, o el mapeador lo manda como DocTipo 80. */
    public function test_cliente_con_dni_no_informa_cuit(): void
    {
        $datos = $this->cliente('DNI', '27501362')->datosFiscalesArca();

        $this->assertNull($datos['cuit']);
        $this->assertSame('27501362', $datos['dni']);
        $this->assertSame('DNI', $datos['tipo_documento']);
        $this->assertSame('27501362', $datos['documento']);
        $this->assertSame('5', $datos['condicion_iva_codigo']);
    }

    public function test_cliente_con_cuit_informa_cuit(): void
    {
        $datos = $this->cliente('CUIT', '20123456789', '1')->datosFiscalesArca();

        $this->assertSame('20123456789', $datos['cuit']);
        $this->assertNull($datos['dni']);
    }

    public function test_cliente_con_cuil_no_informa_ni_cuit_ni_dni(): void
    {
        $datos = $this->cliente('CUIL', '20123456789')->datosFiscalesArca();

        $this->assertNull($datos['cuit']);
        $this->assertNull($datos['dni']);
        $this->assertSame('CUIL', $datos['tipo_documento']);
    }

    /** Sin tipo_documento se mantiene el comportamiento histórico: se asume CUIT, no se adivina. */
    public function test_cliente_sin_tipo_documento_se_trata_como_cuit(): void
    {
        $datos = $this->cliente(null, '20123456789')->datosFiscalesArca();

        $this->assertSame('20123456789', $datos['cuit']);
    }
}
