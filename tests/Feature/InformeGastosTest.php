<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Gasto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InformeGastosTest extends TestCase
{
    use RefreshDatabase;

    public function test_agrupa_por_categoria_y_subcategoria_con_subtotales(): void
    {
        $servicios = Categoria::factory()->create(['nombre' => 'Servicios', 'categoria_padre_id' => null]);
        $luz = Categoria::factory()->create(['nombre' => 'Luz', 'categoria_padre_id' => $servicios->id]);
        $gas = Categoria::factory()->create(['nombre' => 'Gas', 'categoria_padre_id' => $servicios->id]);

        Gasto::factory()->create([
            'categoria_id' => $servicios->id, 'subcategoria_id' => $luz->id,
            'fecha' => '2026-06-05', 'importe' => 1000, 'estado' => 'pagado',
        ]);
        Gasto::factory()->create([
            'categoria_id' => $servicios->id, 'subcategoria_id' => $gas->id,
            'fecha' => '2026-06-10', 'importe' => 500, 'estado' => 'pendiente',
        ]);

        $resp = $this->getJson(route('informes.gastos.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(2, $resp['data']);
        $this->assertEquals(1500.0, $resp['total_general']);

        $grupoServicios = collect($resp['grupos'])->firstWhere('categoria', 'Servicios');
        $this->assertNotNull($grupoServicios);
        $this->assertEquals(1500.0, $grupoServicios['subtotal_categoria']);
        $this->assertCount(2, $grupoServicios['subcategorias']);
    }

    public function test_muestra_gastos_pagados_y_pendientes(): void
    {
        $categoria = Categoria::factory()->create();
        Gasto::factory()->create(['categoria_id' => $categoria->id, 'fecha' => '2026-06-05', 'estado' => 'pagado']);
        Gasto::factory()->create(['categoria_id' => $categoria->id, 'fecha' => '2026-06-06', 'estado' => 'pendiente']);

        $resp = $this->getJson(route('informes.gastos.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(2, $resp['data']);
    }
}
