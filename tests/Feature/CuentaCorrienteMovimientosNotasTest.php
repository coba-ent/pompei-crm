<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ComprobanteFiscal;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las filas de Nota de Crédito/Débito salían en blanco salvo Id/Emisión/Operación.
 * Deben traer cliente, categoría de la venta afectada, el monto en "Cobrado" y el
 * Nº de comprobante — el propio, o el del comprobante fiscal aprobado si la nota
 * se emitió por ARCA (que es donde queda el número cuando la emite el CRM).
 */
class CuentaCorrienteMovimientosNotasTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function filaNota(Cliente $cliente, NotaCreditoDebito $nota): array
    {
        $data = $this->getJson(route('informes.cuenta-corriente.movimientos.data', [
            'draw' => 1, 'start' => 0, 'length' => 50, 'cliente_id' => $cliente->id,
        ]))->assertOk()->json('data');

        // El id no alcanza: una venta y una nota pueden compartir id en el UNION.
        return collect($data)
            ->where('operacion', $nota->tipo === 'credito' ? 'nota_credito' : 'nota_debito')
            ->firstWhere('id', $nota->id);
    }

    public function test_la_nota_trae_cliente_categoria_y_monto(): void
    {
        $categoria = Categoria::factory()->create(['nombre' => 'Mercadolibre']);
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente de Prueba']);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'categoria_id' => $categoria->id,
            'total' => 1000,
        ]);
        $nota = NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id,
            'tipo' => 'credito',
            'monto' => 250,
            'nro_comprobante' => null,
        ]);

        $fila = $this->filaNota($cliente, $nota);

        $this->assertSame('nota_credito', $fila['operacion']);
        $this->assertSame('Cliente de Prueba', $fila['cliente']);
        $this->assertSame('Mercadolibre', $fila['categoria']);
        $this->assertEquals(250, (float) $fila['cobrado']);
    }

    public function test_el_nro_de_comprobante_sale_del_comprobante_fiscal_aprobado(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $nota = NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id,
            'tipo' => 'credito',
            'monto' => 100,
            'nro_comprobante' => null,
        ]);
        ComprobanteFiscal::create([
            'comprobantable_type' => NotaCreditoDebito::class,
            'comprobantable_id' => $nota->id,
            'tipo_comprobante' => 'B',
            'numero' => '0009-00000001',
            'estado' => 'aprobado',
        ]);

        $this->assertSame('0009-00000001', $this->filaNota($cliente, $nota)['nro_comprobante']);
    }

    public function test_el_nro_propio_de_la_nota_tiene_prioridad(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $nota = NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id,
            'tipo' => 'credito',
            'monto' => 100,
            'nro_comprobante' => '0005-00000227',
        ]);

        $this->assertSame('0005-00000227', $this->filaNota($cliente, $nota)['nro_comprobante']);
    }
}
