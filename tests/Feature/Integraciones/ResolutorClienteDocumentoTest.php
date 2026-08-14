<?php

namespace Tests\Feature\Integraciones;

use App\Models\Cliente;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Services\MercadoLibre\ResolutorCliente;
use Database\Seeders\CondicionIvaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El documento del comprador va a `clientes.cuit` cualquiera sea su tipo (14/08/2026). Antes sólo se
 * guardaba si era CUIT, así que el comprador con DNI quedaba sin número y su Venta se enviaba a ARCA
 * como Consumidor Final sin identificar aunque Mercado Libre hubiera informado el DNI.
 */
class ResolutorClienteDocumentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CondicionIvaSeeder::class);
    }

    private function orden(string $mlUserId = '204859164', string $apodo = 'COMPRADOR1'): MercadoLibreOrden
    {
        return new MercadoLibreOrden([
            'comprador_ml_id' => $mlUserId,
            'comprador_apodo' => $apodo,
            'comprador_nombre' => 'Comprador de Prueba',
        ]);
    }

    /** @return array<string, mixed> */
    private function datosFiscales(string $tipo, string $numero, string $condicion = 'Consumidor Final'): array
    {
        return [
            'tipo_comprobante' => $condicion === 'Responsable Inscripto' ? 'A' : 'B',
            'condicion_iva' => $condicion,
            'doc_tipo' => $tipo,
            'doc_numero' => $numero,
            'aproximado' => false,
            'razon_social' => null,
            'domicilio_fiscal' => null,
            'localidad_fiscal' => null,
            'provincia_fiscal' => null,
        ];
    }

    public function test_cliente_nuevo_con_dni_guarda_el_numero(): void
    {
        $resultado = (new ResolutorCliente())->resolver($this->orden(), $this->datosFiscales('DNI', '33258370'));

        $this->assertSame('DNI', $resultado['cliente']->tipo_documento);
        $this->assertSame('33258370', $resultado['cliente']->cuit);
    }

    public function test_cliente_nuevo_con_cuit_sigue_guardando_el_numero(): void
    {
        $resultado = (new ResolutorCliente())->resolver($this->orden(), $this->datosFiscales('CUIT', '30572634651', 'Responsable Inscripto'));

        $this->assertSame('CUIT', $resultado['cliente']->tipo_documento);
        $this->assertSame('30572634651', $resultado['cliente']->cuit);
        $this->assertSame('A', $resultado['cliente']->tipo_comprobante_defecto);
    }

    public function test_cliente_existente_sin_documento_lo_completa(): void
    {
        $cliente = Cliente::factory()->create(['ml_user_id' => '204859164', 'cuit' => null, 'tipo_documento' => null]);

        (new ResolutorCliente())->resolver($this->orden(), $this->datosFiscales('DNI', '28402077'));

        $this->assertSame('28402077', $cliente->fresh()->cuit);
    }

    public function test_no_pisa_el_documento_ya_cargado_a_mano(): void
    {
        $cliente = Cliente::factory()->create(['ml_user_id' => '204859164', 'cuit' => '11111111', 'tipo_documento' => 'DNI']);

        (new ResolutorCliente())->resolver($this->orden(), $this->datosFiscales('DNI', '28402077'));

        $this->assertSame('11111111', $cliente->fresh()->cuit);
    }

    /** La columna es única: una colisión no puede hacer fallar el alta entera de la Venta. */
    public function test_documento_ya_usado_por_otro_cliente_no_rompe_el_alta(): void
    {
        Cliente::factory()->create(['cuit' => '28402077', 'tipo_documento' => 'DNI']);

        $resultado = (new ResolutorCliente())->resolver($this->orden(), $this->datosFiscales('DNI', '28402077'));

        $this->assertNotNull($resultado['cliente']);
        $this->assertNull($resultado['cliente']->cuit);
        $this->assertSame('DNI', $resultado['cliente']->tipo_documento);
    }
}
