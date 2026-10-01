<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El bloque de datos del cliente de la factura salía con un guion en el
 * domicilio aunque el dato estuviera cargado: el alta lo guarda en el campo
 * fiscal y el comprobante leía sólo el comercial.
 */
class DatosClienteComprobanteTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_domicilio_cae_al_fiscal_cuando_el_comercial_esta_vacio(): void
    {
        $cliente = Cliente::factory()->create([
            'domicilio' => '',
            'domicilio_fiscal' => 'CHORROARIN AV. 1015',
        ]);

        $this->assertSame('CHORROARIN AV. 1015', $cliente->domicilioParaComprobante());
    }

    public function test_el_domicilio_comercial_tiene_prioridad_sobre_el_fiscal(): void
    {
        $cliente = Cliente::factory()->create([
            'domicilio' => 'Lima 111',
            'domicilio_fiscal' => 'Otra calle 999',
        ]);

        $this->assertSame('Lima 111', $cliente->domicilioParaComprobante());
    }

    public function test_un_domicilio_en_blanco_no_cuenta_como_cargado(): void
    {
        $cliente = Cliente::factory()->create([
            'domicilio' => '   ',
            'domicilio_fiscal' => 'Belgrano 200',
        ]);

        $this->assertSame('Belgrano 200', $cliente->domicilioParaComprobante());
    }

    public function test_sin_ningun_domicilio_devuelve_null_para_que_salga_el_guion(): void
    {
        $cliente = Cliente::factory()->create([
            'domicilio' => null,
            'domicilio_fiscal' => null,
        ]);

        $this->assertNull($cliente->domicilioParaComprobante());
    }

    public function test_el_telefono_recorre_fijo_celular_y_fiscal_en_ese_orden(): void
    {
        $soloCelular = Cliente::factory()->create([
            'telefono' => null,
            'telefono_celular' => '11-5555-0000',
            'telefono_fiscal' => '11-4444-0000',
        ]);

        $this->assertSame('11-5555-0000', $soloCelular->telefonoParaComprobante());

        $soloFiscal = Cliente::factory()->create([
            'telefono' => null,
            'telefono_celular' => null,
            'telefono_fiscal' => '11-4444-0000',
        ]);

        $this->assertSame('11-4444-0000', $soloFiscal->telefonoParaComprobante());
    }

    public function test_el_proveedor_comparte_el_mismo_criterio(): void
    {
        $proveedor = Proveedor::factory()->create([
            'domicilio' => '',
            'domicilio_fiscal' => 'Parque Patricios 50',
        ]);

        $this->assertSame('Parque Patricios 50', $proveedor->domicilioParaComprobante());
    }
}
