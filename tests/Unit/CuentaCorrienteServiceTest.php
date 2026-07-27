<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Services\CuentaCorrienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CuentaCorrienteServiceTest extends TestCase
{
    use RefreshDatabase;

    private CuentaCorrienteService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CuentaCorrienteService();
    }

    public function test_saldo_cliente_venta_menos_cobro(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 40000]);

        $this->assertSame(60000.0, $this->service->saldoCliente($cliente->fresh()));
    }

    public function test_saldo_cliente_sin_operaciones_usa_saldo_inicial(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 25000]);

        $this->assertSame(25000.0, $this->service->saldoCliente($cliente));
    }

    public function test_saldo_cliente_puede_ser_negativo_a_favor(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 50000]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 70000]);

        $this->assertSame(-20000.0, $this->service->saldoCliente($cliente->fresh()));
    }

    public function test_saldo_proveedor_compra_menos_pago(): void
    {
        $proveedor = Proveedor::factory()->create(['saldo_inicial' => 0]);
        $compra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 80000]);
        \App\Models\Pago::factory()->create(['compra_id' => $compra->id, 'monto' => 80000]);

        $this->assertSame(0.0, $this->service->saldoProveedor($proveedor->fresh()));
    }

    /** T023 — dos compras impagas: cada una con su pendiente y el total coincide. */
    public function test_saldo_proveedor_con_dos_compras_impagas_suma_ambas(): void
    {
        $proveedor = Proveedor::factory()->create(['saldo_inicial' => 0]);
        Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 30000]);
        Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 45000]);

        $this->assertSame(75000.0, $this->service->saldoProveedor($proveedor->fresh()));
    }
}
