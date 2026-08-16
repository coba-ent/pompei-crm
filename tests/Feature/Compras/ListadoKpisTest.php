<?php

namespace Tests\Feature\Compras;

use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KPIs del listado de Compras — espejo de {@see \Tests\Feature\Ventas\ListadoKpisTest}.
 *
 * `Pagado + A Pagar + Vencido = Total` es la ecuación que muestra Contagram arriba de su listado.
 * Antes no valía: "Total" era el bruto (sin descontar notas de crédito) y "Vencido" era un
 * subconjunto de "A Pagar", así que sumarlos lo contaba dos veces.
 */
class ListadoKpisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function kpis(): array
    {
        return $this->getJson(route('compras.kpis'))->assertOk()->json();
    }

    public function test_el_total_descuenta_las_notas_de_credito_y_suma_las_de_debito(): void
    {
        $compra = Compra::factory()->create([
            'proveedor_id' => Proveedor::factory()->create()->id,
            'total' => 10000,
        ]);

        NotaCreditoDebito::factory()->create([
            'compra_id' => $compra->id, 'venta_id' => null,
            'tipo' => 'credito', 'monto' => 2000, 'fecha_emision' => now()->toDateString(),
        ]);
        NotaCreditoDebito::factory()->create([
            'compra_id' => $compra->id, 'venta_id' => null,
            'tipo' => 'debito', 'monto' => 500, 'fecha_emision' => now()->toDateString(),
        ]);

        $this->assertEqualsWithDelta(8500.0, $this->kpis()['total'], 0.01);
    }

    public function test_a_pagar_y_vencido_no_se_pisan_y_los_cuatro_importes_cierran(): void
    {
        $proveedor = Proveedor::factory()->create();

        Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'total' => 3000,
            'fecha_vto_pago' => now()->subDays(10)->toDateString(),
        ]);
        Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'total' => 7000,
            'fecha_vto_pago' => now()->addDays(10)->toDateString(),
        ]);

        $kpis = $this->kpis();

        $this->assertEqualsWithDelta(3000.0, $kpis['vencido'], 0.01);
        $this->assertEqualsWithDelta(7000.0, $kpis['a_pagar'], 0.01);
        $this->assertEqualsWithDelta(
            $kpis['total'],
            $kpis['pagado'] + $kpis['a_pagar'] + $kpis['vencido'],
            0.01,
        );
    }
}
