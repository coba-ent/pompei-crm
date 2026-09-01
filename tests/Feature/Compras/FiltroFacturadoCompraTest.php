<?php

namespace Tests\Feature\Compras;

use App\Models\Compra;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtro "Facturado" del listado de Compras.
 *
 * El filtro miraba la relación `comprobanteFiscal`, que guarda lo que emitimos NOSOTROS por
 * ARCA — algo que en Compras no existe nunca: ahí la factura la emite el proveedor y se
 * registra en la compra misma (`tipo_comprobante` + `nro_comprobante`). Resultado: "Sí" no
 * devolvía nada, con 1.460 compras facturadas en producción.
 *
 * "Sin factura" es `tipo_comprobante` en [NULL, '', 'S'] — el mismo criterio que ya usaba
 * IvaDigitalPaquete::generarLadoCompras() para excluirlas del TXT de RG 3685.
 */
class FiltroFacturadoCompraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );
    }

    /** @return array<string, int> ids por escenario */
    private function escenario(): array
    {
        return [
            'conFacturaA' => Compra::factory()->create(['tipo_comprobante' => 'A', 'nro_comprobante' => '0001-00000001'])->id,
            'conFacturaB' => Compra::factory()->create(['tipo_comprobante' => 'B', 'nro_comprobante' => '0001-00000002'])->id,
            'sinFacturaS' => Compra::factory()->create(['tipo_comprobante' => 'S', 'nro_comprobante' => null])->id,
            'sinTipoNull' => Compra::factory()->create(['tipo_comprobante' => null, 'nro_comprobante' => null])->id,
            'sinTipoVacio' => Compra::factory()->create(['tipo_comprobante' => '', 'nro_comprobante' => null])->id,
        ];
    }

    public function test_facturado_si_devuelve_las_que_tienen_comprobante_del_proveedor(): void
    {
        $ids = $this->escenario();

        $resp = $this->getJson(route('compras.data', ['facturado' => ['1']]));

        $resp->assertOk();
        $devueltos = collect($resp->json('data'))->pluck('id')->map(fn ($v) => (int) strip_tags((string) $v))->sort()->values()->all();
        $esperados = collect([$ids['conFacturaA'], $ids['conFacturaB']])->sort()->values()->all();

        $this->assertSame($esperados, $devueltos);
    }

    public function test_facturado_no_devuelve_las_sin_factura_incluida_la_opcion_s(): void
    {
        $ids = $this->escenario();

        $resp = $this->getJson(route('compras.data', ['facturado' => ['0']]));

        $resp->assertOk();
        $devueltos = collect($resp->json('data'))->pluck('id')->map(fn ($v) => (int) strip_tags((string) $v))->sort()->values()->all();
        $esperados = collect([$ids['sinFacturaS'], $ids['sinTipoNull'], $ids['sinTipoVacio']])->sort()->values()->all();

        $this->assertSame($esperados, $devueltos);
    }

    public function test_ambas_opciones_juntas_devuelven_todo_sin_duplicar(): void
    {
        $ids = $this->escenario();

        $resp = $this->getJson(route('compras.data', ['facturado' => ['1', '0']]));

        $resp->assertOk();
        $devueltos = collect($resp->json('data'))->pluck('id')->map(fn ($v) => (int) strip_tags((string) $v))->sort()->values()->all();

        $this->assertSame(collect($ids)->map(fn ($v) => (int) $v)->sort()->values()->all(), $devueltos);
    }
}
