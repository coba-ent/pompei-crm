<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SC-001/SC-007 (research.md D-001): el Informe de Ventas se resuelve con
 * una consulta agregada sobre `ventas`, sin una query por fila (N+1), igual
 * que CuentaCorrientePerformanceTest. No reproduce el volumen exacto de
 * SC-007 (≥5.000 operaciones) para no volver lenta la suite, pero valida que
 * la cantidad de queries no escala con la cantidad de ventas.
 */
class InformePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_informe_de_ventas_no_hace_una_query_por_fila(): void
    {
        $cliente = Cliente::factory()->create();
        Venta::factory()->count(100)->create(['cliente_id' => $cliente->id, 'fecha_emision' => '2026-06-15', 'total' => 1000]);

        DB::enableQueryLog();
        $inicio = microtime(true);

        $this->getJson(route('informes.ventas.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30', 'length' => 100,
        ]))->assertOk();

        $duracion = microtime(true) - $inicio;
        $cantidadQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(50, $cantidadQueries, 'El informe de ventas parece hacer una query por fila (N+1).');
        $this->assertLessThan(3.0, $duracion, 'El informe de 100 ventas tardó más de lo esperado.');
    }
}
