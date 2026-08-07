<?php

namespace Tests\Feature;

use App\Models\LogAuditoria;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US2 — filtros combinables (Id, Operación, Usuario, rango de fecha) de spec 054. */
class AuditoriaFiltrosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function datatable(array $params = []): array
    {
        return $this->getJson(route('auditoria.data', array_merge([
            'draw' => 1, 'start' => 0, 'length' => 50,
        ], $params)))->json();
    }

    public function test_filtra_por_id_exacto(): void
    {
        Venta::factory()->create();
        Venta::factory()->create();
        $idBuscado = LogAuditoria::first()->id;

        $data = $this->datatable(['id' => $idBuscado]);

        $this->assertSame(1, $data['recordsFiltered']);
        $this->assertSame($idBuscado, $data['data'][0]['id']);
    }

    public function test_filtra_por_operacion(): void
    {
        Venta::factory()->create();
        Venta::factory()->create();

        $data = $this->datatable(['operacion' => 'venta']);

        $this->assertSame(2, $data['recordsFiltered']);
        foreach ($data['data'] as $fila) {
            $this->assertSame('venta', LogAuditoria::find($fila['id'])->tipo_operacion);
        }
    }

    public function test_filtra_por_usuario(): void
    {
        $usuarioOriginal = auth()->user();
        $otro = User::factory()->create();

        auth()->login($otro);
        Venta::factory()->create();

        // Vuelve al usuario con permiso para pegarle al endpoint.
        auth()->login($usuarioOriginal);

        $data = $this->datatable(['usuario_id' => $otro->id]);

        $this->assertSame(1, $data['recordsFiltered']);
    }

    public function test_filtra_por_rango_de_fecha(): void
    {
        Venta::factory()->create();
        $log = LogAuditoria::first();
        $log->forceFill(['created_at' => now()->subDays(10)])->save();

        $dataFueraDeRango = $this->datatable([
            'fecha_desde' => now()->toDateString(),
            'fecha_hasta' => now()->toDateString(),
        ]);
        $this->assertSame(0, $dataFueraDeRango['recordsFiltered']);

        $dataEnRango = $this->datatable([
            'fecha_desde' => now()->subDays(15)->toDateString(),
            'fecha_hasta' => now()->toDateString(),
        ]);
        $this->assertSame(1, $dataEnRango['recordsFiltered']);
    }

    public function test_combina_operacion_y_usuario_con_and(): void
    {
        $usuario = auth()->user();
        Venta::factory()->create();

        $data = $this->datatable(['operacion' => 'venta', 'usuario_id' => $usuario->id]);
        $this->assertSame(1, $data['recordsFiltered']);

        $data = $this->datatable(['operacion' => 'gasto', 'usuario_id' => $usuario->id]);
        $this->assertSame(0, $data['recordsFiltered']);
    }
}
