<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InformeVentasTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtra_solo_las_ventas_del_rango_y_calcula_la_fila_de_totales(): void
    {
        $cliente = Cliente::factory()->create();

        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-06-15',
            'subtotal' => 1000,
            'total' => 1210,
        ]);
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-06-20',
            'subtotal' => 2000,
            'total' => 2420,
        ]);
        // Fuera de rango: no debe contarse.
        Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-05-01',
            'subtotal' => 5000,
            'total' => 6050,
        ]);

        $resp = $this->getJson(route('informes.ventas.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(2, $resp['data']);
        $this->assertEquals(3000.0, $resp['total_general']['subtotal']);
        $this->assertEquals(630.0, $resp['total_general']['iva']);
        $this->assertEquals(3630.0, $resp['total_general']['total']);
    }

    public function test_excluye_ventas_soft_deleted(): void
    {
        $cliente = Cliente::factory()->create();
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-06-10',
            'subtotal' => 1000,
            'total' => 1210,
        ]);
        $venta->delete();

        $resp = $this->getJson(route('informes.ventas.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(0, $resp['data']);
        $this->assertEquals(0.0, $resp['total_general']['total']);
    }

    public function test_rango_sin_ventas_devuelve_tabla_vacia_sin_error(): void
    {
        $resp = $this->getJson(route('informes.ventas.data', [
            'fecha_desde' => '2020-01-01', 'fecha_hasta' => '2020-01-31',
        ]))->assertOk()->json();

        $this->assertCount(0, $resp['data']);
        $this->assertEquals(0.0, $resp['total_general']['subtotal']);
        $this->assertEquals(0.0, $resp['total_general']['total']);
    }

    public function test_rango_invalido_devuelve_422(): void
    {
        $this->getJson(route('informes.ventas.data', [
            'fecha_desde' => '2026-06-30', 'fecha_hasta' => '2026-06-01',
        ]))->assertStatus(422);
    }
}
