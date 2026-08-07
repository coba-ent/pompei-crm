<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US3 — exportar refleja exactamente el filtro aplicado, nunca el total de la tabla (spec 054). */
class AuditoriaExportarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_exportar_con_filtro_devuelve_solo_las_filas_filtradas(): void
    {
        Venta::factory()->create();
        Venta::factory()->create();
        $ventaBuscada = Venta::factory()->create(['total' => 999]);

        $response = $this->get(route('auditoria.exportar', ['id' => \App\Models\LogAuditoria::where('entidad_id', $ventaBuscada->id)->first()->id]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $lineas = array_values(array_filter(explode("\n", trim($csv))));

        // Encabezado + exactamente 1 fila de datos.
        $this->assertCount(2, $lineas);
        $this->assertStringContainsString('999', $lineas[1]);
    }

    public function test_exportar_sin_resultados_devuelve_error_manejable(): void
    {
        Venta::factory()->create();

        $response = $this->getJson(route('auditoria.exportar', ['operacion' => 'compra']));

        $response->assertStatus(422)->assertJsonPath('ok', false);
    }
}
