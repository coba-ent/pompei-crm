<?php

namespace Tests\Feature\Ventas;

use App\Models\Cliente;
use App\Models\NotaCreditoDebito;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KPIs del listado de Ventas.
 *
 * La ecuación `Cobrado + A Cobrar + Vencido = Total` es la misma que muestra Contagram arriba de
 * su listado, y se adoptó el 16/08/2026 para que las dos pantallas se lean igual. Antes no valía
 * por dos motivos que este test fija: "Total" era el facturado bruto (no descontaba las notas de
 * crédito) y "Vencido" era un subconjunto de "A Cobrar", así que sumarlos lo contaba dos veces.
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
        return $this->getJson(route('ventas.kpis'))->assertOk()->json();
    }

    public function test_el_total_descuenta_las_notas_de_credito_y_suma_las_de_debito(): void
    {
        $venta = Venta::factory()->create(['total' => 10000, 'cliente_id' => Cliente::factory()->create()->id]);

        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 2000, 'fecha_emision' => now()->toDateString(),
        ]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'tipo' => 'debito', 'monto' => 500, 'fecha_emision' => now()->toDateString(),
        ]);

        // 10.000 − 2.000 + 500. El facturado bruto (10.000) ya no es lo que se muestra.
        $this->assertEqualsWithDelta(8500.0, $this->kpis()['total'], 0.01);
    }

    public function test_a_cobrar_y_vencido_no_se_pisan_y_los_cuatro_importes_cierran(): void
    {
        $cliente = Cliente::factory()->create();

        // Una vencida y otra que todavía no: cada una tiene que caer en un solo KPI.
        Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 3000,
            'fecha_vto_cobro' => now()->subDays(10)->toDateString(),
        ]);
        Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 7000,
            'fecha_vto_cobro' => now()->addDays(10)->toDateString(),
        ]);

        $kpis = $this->kpis();

        $this->assertEqualsWithDelta(3000.0, $kpis['vencido'], 0.01);
        $this->assertEqualsWithDelta(7000.0, $kpis['a_cobrar'], 0.01);
        $this->assertEqualsWithDelta(
            $kpis['total'],
            $kpis['cobrado'] + $kpis['a_cobrar'] + $kpis['vencido'],
            0.01,
        );
    }
}
