<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chequeo de rendimiento (SC-003, research.md D-009): el listado de saldos y
 * el detalle de movimientos deben resolverse con una consulta agregada
 * (sin N+1) y los índices compuestos de T005. No reproduce el volumen exacto
 * de SC-003 (500/20.000) para no volver lenta la suite, pero valida que la
 * cantidad de queries no escala con la cantidad de clientes.
 */
class CuentaCorrientePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_listado_de_saldos_no_hace_una_query_por_cliente(): void
    {
        $clientes = Cliente::factory()->count(50)->create();
        foreach ($clientes as $cliente) {
            $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000]);
            Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 400]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $inicio = microtime(true);

        $this->getJson(route('cuentas-corrientes.clientes.data').'?length=50')->assertOk();

        $duracion = microtime(true) - $inicio;
        $cantidadQueries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Debe resolverse con un puñado de queries fijas, no una por cliente (N+1).
        $this->assertLessThan(50, $cantidadQueries, 'El listado parece hacer una query por cliente (N+1).');
        $this->assertLessThan(3.0, $duracion, 'El listado de 50 clientes tardó más de lo esperado.');
    }
}
