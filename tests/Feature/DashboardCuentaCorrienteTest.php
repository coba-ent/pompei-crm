<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Services\Tesoreria\CuentaCorriente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardCuentaCorrienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_clasifica_correctamente_segun_dias_de_vencimiento(): void
    {
        $cliente = Cliente::factory()->create();

        // Vencida hace 45 días → bucket 31_60.
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 1000,
            'fecha_vto_cobro' => Carbon::today()->subDays(45)->toDateString(),
        ]);
        // A vencer (vencimiento futuro).
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 500,
            'fecha_vto_cobro' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $aging = app(CuentaCorriente::class)->aging('cliente');

        $this->assertEquals(1000.0, $aging['buckets']['31_60']);
        $this->assertEquals(0.0, $aging['buckets']['0_30']);
        $this->assertEquals(500.0, $aging['buckets']['a_vencer']);
        $this->assertEquals(1000.0, $aging['buckets']['vencido']);
        $this->assertEquals(1500.0, $aging['total']);
    }

    public function test_documento_con_saldo_cero_no_aporta_a_ningun_bucket(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 1000,
            'fecha_vto_cobro' => Carbon::today()->subDays(45)->toDateString(),
        ]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 1000]);

        $aging = app(CuentaCorriente::class)->aging('cliente');

        $this->assertEquals(0.0, $aging['total']);
        foreach ($aging['buckets'] as $monto) {
            $this->assertEquals(0.0, $monto);
        }
    }

    public function test_el_total_es_la_suma_de_acobrar_de_los_documentos_pendientes(): void
    {
        $cliente = Cliente::factory()->create();
        $venta1 = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
        $venta2 = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 2000]);
        Cobro::factory()->create(['venta_id' => $venta2->id, 'monto' => 500]);

        $aging = app(CuentaCorriente::class)->aging('cliente');

        $this->assertEquals($venta1->aCobrar() + $venta2->aCobrar(), $aging['total']);
    }

    public function test_nota_de_credito_reduce_el_saldo_usado_para_el_aging(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 1000,
            'fecha_vto_cobro' => Carbon::today()->subDays(10)->toDateString(),
        ]);
        NotaCreditoDebito::factory()->create(['venta_id' => $venta->id, 'tipo' => 'credito', 'monto' => 400]);

        $aging = app(CuentaCorriente::class)->aging('cliente');

        $this->assertEquals(600.0, $aging['total']);
        $this->assertEquals(600.0, $aging['buckets']['0_30']);
    }

    public function test_aging_de_proveedor_clasifica_compras_a_pagar(): void
    {
        $proveedor = Proveedor::factory()->create();
        Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'total' => 1500,
            'fecha_vto_pago' => Carbon::today()->subDays(70)->toDateString(),
        ]);

        $aging = app(CuentaCorriente::class)->aging('proveedor');

        $this->assertEquals(1500.0, $aging['buckets']['61_90']);
        $this->assertEquals(1500.0, $aging['total']);
    }
}
