<?php

namespace Tests\Feature;

use App\Models\LogAuditoria;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SC-003: el listado responde en <2s con miles de filas acumuladas y un filtro combinado aplicado. */
class AuditoriaPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_datatable_responde_rapido_con_10000_filas_y_filtro_combinado(): void
    {
        $usuario = auth()->user();
        $otro = User::factory()->create();

        $lote = [];
        for ($i = 0; $i < 10000; $i++) {
            $lote[] = [
                'usuario_id' => $i % 5 === 0 ? $otro->id : $usuario->id,
                'usuario_nombre' => $i % 5 === 0 ? $otro->name : $usuario->name,
                'origen_sistema' => null,
                'tipo_accion' => 'creo',
                'tipo_operacion' => $i % 2 === 0 ? 'venta' : 'gasto',
                'entidad_tipo' => 'App\\Models\\Venta',
                'entidad_id' => $i + 1,
                'detalle' => "Venta #{$i}",
                'total' => 100,
                'created_at' => now()->subMinutes($i),
            ];

            if (count($lote) >= 1000) {
                LogAuditoria::insert($lote);
                $lote = [];
            }
        }
        if ($lote) {
            LogAuditoria::insert($lote);
        }

        $this->assertSame(10000, LogAuditoria::count());

        $inicio = microtime(true);

        $this->getJson(route('auditoria.data', [
            'draw' => 1, 'start' => 0, 'length' => 25,
            'operacion' => 'venta', 'usuario_id' => $usuario->id,
            'fecha_desde' => now()->subDays(30)->toDateString(), 'fecha_hasta' => now()->toDateString(),
        ]))->assertOk();

        $duracion = microtime(true) - $inicio;

        $this->assertLessThan(2.0, $duracion, "El datatable tardó {$duracion}s (SC-003 pide <2s).");
    }
}
