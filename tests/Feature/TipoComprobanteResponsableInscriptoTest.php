<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Al cargar una venta a un Responsable Inscripto el formulario proponía B y
 * había que corregirlo a mano en cada comprobante, porque el default guardado
 * del cliente estaba vacío en 1.873 de ellos y el <select> arranca en B.
 */
class TipoComprobanteResponsableInscriptoTest extends TestCase
{
    use RefreshDatabase;

    private function condicion(string $nombre, string $codigoAfip): CondicionIva
    {
        return CondicionIva::create([
            'nombre' => $nombre,
            'codigo_afip' => $codigoAfip,
            'requiere_cuit' => $codigoAfip === '1',
        ]);
    }

    public function test_un_responsable_inscripto_sin_default_factura_A(): void
    {
        $cliente = Cliente::factory()->create([
            'condicion_iva_id' => $this->condicion('Responsable Inscripto', '1')->id,
            'tipo_comprobante_defecto' => null,
        ]);

        $this->assertSame('A', $cliente->tipoComprobanteQueCorresponde());
    }

    public function test_la_condicion_de_iva_corrige_un_responsable_inscripto_guardado_como_B(): void
    {
        $cliente = Cliente::factory()->create([
            'condicion_iva_id' => $this->condicion('Responsable Inscripto', '1')->id,
            'tipo_comprobante_defecto' => 'B',
        ]);

        $this->assertSame('A', $cliente->tipoComprobanteQueCorresponde());
    }

    public function test_un_consumidor_final_sigue_facturando_B(): void
    {
        $cliente = Cliente::factory()->create([
            'condicion_iva_id' => $this->condicion('Consumidor Final', '5')->id,
            'tipo_comprobante_defecto' => null,
        ]);

        $this->assertSame('B', $cliente->tipoComprobanteQueCorresponde());
    }

    public function test_el_default_guardado_manda_en_las_condiciones_que_no_son_RI(): void
    {
        $cliente = Cliente::factory()->create([
            'condicion_iva_id' => $this->condicion('Exento', '4')->id,
            'tipo_comprobante_defecto' => 'C',
        ]);

        $this->assertSame('C', $cliente->tipoComprobanteQueCorresponde());
    }

    public function test_un_cliente_sin_condicion_de_iva_cae_en_B_y_no_rompe(): void
    {
        $cliente = Cliente::factory()->create([
            'condicion_iva_id' => null,
            'tipo_comprobante_defecto' => null,
        ]);

        $this->assertSame('B', $cliente->tipoComprobanteQueCorresponde());
        $this->assertFalse($cliente->esResponsableInscripto());
    }

    public function test_el_buscador_de_clientes_propone_A_para_un_responsable_inscripto(): void
    {
        $cliente = Cliente::factory()->create([
            'nombre' => 'BRISTOL MEDICINE SRL',
            'activo' => true,
            'condicion_iva_id' => $this->condicion('Responsable Inscripto', '1')->id,
            'tipo_comprobante_defecto' => null,
        ]);

        $this->getJson(route('clientes.opciones', ['q' => 'BRISTOL']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $cliente->id)
            ->assertJsonPath('data.0.tipo_comprobante_defecto', 'A');
    }
}
