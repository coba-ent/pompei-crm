<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActuaComoUsuarioConPermisos;
use Tests\TestCase;

/**
 * El Informe de Stock no muestra los pares reverso/reaplicación que deja editar una compra o una
 * venta (pedido de la clienta, 13/10/2026): una compra editada aparecía como "Disminución".
 */
class InformeStockOcultaEdicionesTest extends TestCase
{
    use ActuaComoUsuarioConPermisos;
    use RefreshDatabase;

    private Deposito $deposito;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUsuarioConPermisos(['informes.stock']);
        $this->deposito = Deposito::create(['nombre' => 'Central']);
        $this->producto = Producto::factory()->create();
    }

    private function mov(Compra $compra, float $cantidad, string $creado): MovimientoStock
    {
        Carbon::setTestNow($creado);
        $m = MovimientoStock::create([
            'producto_id' => $this->producto->id, 'deposito_id' => $this->deposito->id,
            'tipo' => $cantidad > 0 ? 'entrada' : 'salida', 'cantidad' => $cantidad,
            'fecha' => '2026-10-09', 'origen_type' => Compra::class, 'origen_id' => $compra->id,
        ]);
        Carbon::setTestNow();

        return $m;
    }

    private function idsVisibles(): array
    {
        return collect($this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 50]))
            ->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function compra(): Compra
    {
        return Compra::create([
            'proveedor_id' => Proveedor::factory()->create()->id, 'fecha_emision' => '2026-10-09',
            'tipo_comprobante' => 'A', 'nro_comprobante' => '0001-'.random_int(10000000, 99999999),
            'subtotal_sin_descuento' => 0, 'descuento' => 0, 'subtotal_con_descuento' => 0, 'total' => 0,
        ]);
    }

    public function test_la_edicion_de_una_compra_no_aparece_y_queda_lo_que_aplica_hoy(): void
    {
        $compra = $this->compra();
        $alta = $this->mov($compra, 3, '2026-10-09 10:00:00');
        $reverso = $this->mov($compra, -3, '2026-10-09 14:28:06');
        $reaplicacion = $this->mov($compra, 5, '2026-10-09 14:28:07');
        Stock::create(['producto_id' => $this->producto->id, 'deposito_id' => $this->deposito->id, 'cantidad' => 5]);

        $this->assertSame([$reaplicacion->id], $this->idsVisibles());
        $this->assertNotContains($alta->id, $this->idsVisibles());
        $this->assertNotContains($reverso->id, $this->idsVisibles());
    }

    public function test_una_compra_eliminada_sigue_mostrando_su_alta_y_su_eliminacion(): void
    {
        $compra = $this->compra();
        $alta = $this->mov($compra, 3, '2026-10-09 10:00:00');
        $this->mov($compra, -3, '2026-10-09 11:00:00');
        $this->mov($compra, 3, '2026-10-09 11:00:01');
        $eliminacion = $this->mov($compra, -3, '2026-10-09 12:00:00');
        $compra->delete();

        $this->assertNotContains($alta->id, $this->idsVisibles(), 'El par de la edición se oculta.');
        $this->assertCount(2, $this->idsVisibles());
        $this->assertContains($eliminacion->id, $this->idsVisibles());
    }

    public function test_dos_renglones_opuestos_del_mismo_alta_no_se_ocultan(): void
    {
        $compra = $this->compra();
        $a = $this->mov($compra, 4, '2026-10-09 10:00:00');
        $b = $this->mov($compra, -4, '2026-10-09 10:00:00');

        $this->assertSame([$a->id, $b->id], $this->idsVisibles());
    }
}
