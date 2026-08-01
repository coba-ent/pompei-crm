<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Services\Tesoreria\CuentaCorriente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * spec 031 — `CuentaCorriente::aging()`/`porCliente()` incorporan el saldo
 * inicial de Cliente/Proveedor al cálculo (FR-001/FR-002), clasificado en el
 * mismo esquema de buckets que Venta/Compra (FR-003/FR-004), con soporte para
 * saldo a favor negativo (FR-005) y sin regresión para quien no tiene (SC-004).
 */
class CuentaCorrienteSaldoInicialTest extends TestCase
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

    public function test_saldo_inicial_solo_cae_en_el_bucket_correcto_sin_ninguna_venta(): void
    {
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => 50000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(45)->toDateString(),
        ]);

        $porCliente = app(CuentaCorriente::class)->porCliente('cliente')->keyBy('cliente_id');

        $this->assertEquals(50000.0, $porCliente[$cliente->id]['vencido_31_60']);
        $this->assertEquals(50000.0, $porCliente[$cliente->id]['total']);
    }

    public function test_saldo_inicial_mas_una_venta_se_suman_en_sus_buckets_respectivos(): void
    {
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => 50000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(45)->toDateString(),
        ]);

        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 10000,
            'fecha_vto_cobro' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $porCliente = app(CuentaCorriente::class)->porCliente('cliente')->keyBy('cliente_id');

        $this->assertEquals(50000.0, $porCliente[$cliente->id]['vencido_31_60']);
        $this->assertEquals(10000.0, $porCliente[$cliente->id]['a_vencer']);
        $this->assertEquals(60000.0, $porCliente[$cliente->id]['total']);
    }

    public function test_saldo_inicial_sin_fecha_cae_en_a_vencer(): void
    {
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => 15000,
            'saldo_inicial_fecha' => null,
        ]);

        $porCliente = app(CuentaCorriente::class)->porCliente('cliente')->keyBy('cliente_id');

        $this->assertEquals(15000.0, $porCliente[$cliente->id]['a_vencer']);
        $this->assertEquals(15000.0, $porCliente[$cliente->id]['total']);
    }

    public function test_cliente_sin_saldo_inicial_no_cambia_respecto_del_comportamiento_anterior(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);

        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 700,
            'fecha_vto_cobro' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        $porCliente = app(CuentaCorriente::class)->porCliente('cliente')->keyBy('cliente_id');

        $this->assertEquals(700.0, $porCliente[$cliente->id]['vencido_0_30']);
        $this->assertEquals(700.0, $porCliente[$cliente->id]['total']);
    }

    public function test_proveedor_con_saldo_inicial_y_sin_compras_se_refleja_igual_que_cliente(): void
    {
        $proveedor = Proveedor::factory()->create([
            'saldo_inicial' => 20000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(100)->toDateString(),
        ]);

        $porProveedor = app(CuentaCorriente::class)->porCliente('proveedor')->keyBy('proveedor_id');
        $agingProveedor = app(CuentaCorriente::class)->aging('proveedor');

        $this->assertEquals(20000.0, $porProveedor[$proveedor->id]['vencido_mas_90']);
        $this->assertEquals(20000.0, $porProveedor[$proveedor->id]['total']);
        $this->assertEquals(20000.0, $agingProveedor['buckets']['mas_90']);
        $this->assertEquals(20000.0, $agingProveedor['total']);
    }

    public function test_saldo_inicial_negativo_es_saldo_a_favor_y_no_se_excluye(): void
    {
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => -5000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        $porCliente = app(CuentaCorriente::class)->porCliente('cliente')->keyBy('cliente_id');

        $this->assertTrue($porCliente->has($cliente->id));
        $this->assertEquals(-5000.0, $porCliente[$cliente->id]['vencido_0_30']);
        $this->assertEquals(-5000.0, $porCliente[$cliente->id]['total']);
    }

    public function test_saldo_inicial_negativo_resta_de_una_venta_en_el_mismo_bucket(): void
    {
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => -5000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 8000,
            'fecha_vto_cobro' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        $porCliente = app(CuentaCorriente::class)->porCliente('cliente')->keyBy('cliente_id');

        $this->assertEquals(3000.0, $porCliente[$cliente->id]['vencido_0_30']);
        $this->assertEquals(3000.0, $porCliente[$cliente->id]['total']);
    }

    public function test_saldo_inicial_que_compensa_exactamente_el_resto_de_la_deuda_no_aparece(): void
    {
        $cliente = Cliente::factory()->create([
            'saldo_inicial' => -8000,
            'saldo_inicial_fecha' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'total' => 8000,
            'fecha_vto_cobro' => Carbon::today()->subDays(5)->toDateString(),
        ]);

        $porCliente = app(CuentaCorriente::class)->porCliente('cliente')->keyBy('cliente_id');

        $this->assertFalse($porCliente->has($cliente->id));
    }
}
