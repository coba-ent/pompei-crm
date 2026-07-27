<?php

namespace Tests\Unit;

use App\Models\Categoria;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\OtroIngreso;
use App\Models\Pago;
use App\Models\Retencion;
use App\Models\Venta;
use App\Services\Informes\ReporteFinalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteFinalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function filtros(): array
    {
        return ['fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30'];
    }

    public function test_clasifica_por_cuenta_columna_y_calcula_neto_y_total_general(): void
    {
        $caja = CuentaTesoreria::factory()->create(['nombre' => 'Caja']);
        $banco = CuentaTesoreria::factory()->create(['nombre' => 'Banco']);
        CuentaTesoreria::factory()->create(['nombre' => 'Vacía']);

        $venta = Venta::factory()->create();
        $cobro = Cobro::factory()->create([
            'venta_id' => $venta->id, 'cuenta_tesoreria_id' => $caja->id,
            'fecha' => '2026-06-10', 'monto' => 5000,
        ]);
        Retencion::factory()->create([
            'retenible_type' => Cobro::class, 'retenible_id' => $cobro->id,
            'fecha' => '2026-06-10', 'monto' => 100,
        ]);

        $compra = Compra::factory()->create();
        Pago::factory()->create([
            'compra_id' => $compra->id, 'cuenta_tesoreria_id' => $banco->id,
            'fecha' => '2026-06-12', 'monto' => 2000,
        ]);

        Gasto::factory()->create([
            'cuenta_tesoreria_id' => $caja->id, 'fecha' => '2026-06-15', 'importe' => 300, 'estado' => 'pagado',
        ]);

        $categoria = Categoria::factory()->create();
        OtroIngreso::create([
            'categoria_id' => $categoria->id, 'cuenta_tesoreria_id' => $caja->id,
            'fecha' => '2026-06-18', 'monto' => 700, 'estado' => 'registrado',
        ]);

        $resultado = app(ReporteFinalService::class)->generar($this->filtros());
        $cuentas = collect($resultado['cuentas'])->keyBy('cuenta');

        $this->assertTrue($cuentas->has('Caja'));
        $this->assertTrue($cuentas->has('Banco'));
        $this->assertFalse($cuentas->has('Vacía'), 'FR-022: cuentas sin movimientos no deben listarse.');

        $filaCaja = $cuentas['Caja'];
        $this->assertEquals(5000.0, $filaCaja['ventas_cobradas']);
        $this->assertEquals(700.0, $filaCaja['otros_ingresos']);
        $this->assertEquals(100.0, $filaCaja['retenciones_sufridas']);
        $this->assertEquals(0.0, $filaCaja['compras_pagadas']);
        $this->assertEquals(300.0, $filaCaja['gastos']);
        $this->assertEquals(5500.0, $filaCaja['neto']);

        $filaBanco = $cuentas['Banco'];
        $this->assertEquals(2000.0, $filaBanco['compras_pagadas']);
        $this->assertEquals(-2000.0, $filaBanco['neto']);

        $this->assertEquals(5800.0, $resultado['total_general']['ingresos']);
        $this->assertEquals(2300.0, $resultado['total_general']['egresos']);
        $this->assertEquals(3500.0, $resultado['total_general']['neto']);
    }

    public function test_excluye_gastos_pendientes_aunque_tengan_cuenta_asignada(): void
    {
        $caja = CuentaTesoreria::factory()->create();

        Gasto::factory()->create([
            'cuenta_tesoreria_id' => $caja->id, 'fecha' => '2026-06-05', 'importe' => 999, 'estado' => 'pendiente',
        ]);
        Gasto::factory()->create([
            'cuenta_tesoreria_id' => $caja->id, 'fecha' => '2026-06-06', 'importe' => 111, 'estado' => 'pagado',
        ]);

        $resultado = app(ReporteFinalService::class)->generar($this->filtros());
        $fila = collect($resultado['cuentas'])->firstWhere('cuenta', $caja->nombre);

        $this->assertEquals(111.0, $fila['gastos'], 'FR-020: los gastos pendientes no deben sumar al Reporte Final.');
    }
}
