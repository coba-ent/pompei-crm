<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\OtroIngreso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel de filtros de Otros Ingresos (informe_contagram_ingresos.md §4.2).
 *
 * La pantalla tenía un solo campo de búsqueda por Descripción, y el endpoint `data` no
 * aplicaba NINGÚN filtro: los 6 de Contagram —Id, Categoría, Medio de Cobro, Estado del
 * Cobro, Descripción y Usuario— más el rango de "Emisión" del header no existían.
 */
class OtrosIngresosFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $catA;
    private Categoria $catB;
    private CuentaTesoreria $cuenta;
    private User $otroUsuario;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );

        $this->catA = Categoria::factory()->create(['tipo' => 'ingreso', 'nombre' => 'Aportes Socios']);
        $this->catB = Categoria::factory()->create(['tipo' => 'ingreso', 'nombre' => 'Préstamos']);
        $this->cuenta = CuentaTesoreria::factory()->create();
        $this->otroUsuario = User::factory()->create();
    }

    /** @return array{alfa: OtroIngreso, beta: OtroIngreso, gamma: OtroIngreso} */
    private function escenario(): array
    {
        return [
            // Cobrado, categoría A, con cuenta y con el usuario autenticado, en agosto.
            'alfa' => OtroIngreso::create([
                'fecha' => '2026-08-10', 'monto' => 100, 'categoria_id' => $this->catA->id,
                'cuenta_tesoreria_id' => $this->cuenta->id, 'descripcion' => 'ALFA aporte',
                'pendiente' => false, 'usuario_id' => auth()->id(),
            ]),
            // Pendiente (sin cuenta), categoría B, otro usuario, en septiembre.
            'beta' => OtroIngreso::create([
                'fecha' => '2026-09-01', 'monto' => 200, 'categoria_id' => $this->catB->id,
                'cuenta_tesoreria_id' => null, 'descripcion' => 'BETA préstamo',
                'pendiente' => true, 'usuario_id' => $this->otroUsuario->id,
            ]),
            // Cobrado, categoría A, sin usuario, en julio.
            'gamma' => OtroIngreso::create([
                'fecha' => '2026-07-05', 'monto' => 300, 'categoria_id' => $this->catA->id,
                'cuenta_tesoreria_id' => $this->cuenta->id, 'descripcion' => 'GAMMA otra cosa',
                'pendiente' => false, 'usuario_id' => null,
            ]),
        ];
    }

    /** @return array<int> ids devueltos por el endpoint, ordenados */
    private function idsCon(array $filtros): array
    {
        $resp = $this->getJson(route('otros-ingresos.data', $filtros));
        $resp->assertOk();

        return collect($resp->json('data'))
            ->pluck('id')
            ->map(fn ($v) => (int) strip_tags((string) $v))
            ->sort()->values()->all();
    }

    public function test_sin_filtros_trae_todo(): void
    {
        $e = $this->escenario();

        $this->assertSame(
            collect($e)->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all(),
            $this->idsCon([])
        );
    }

    public function test_filtra_por_id(): void
    {
        $e = $this->escenario();

        $this->assertSame([$e['alfa']->id], $this->idsCon(['id' => $e['alfa']->id]));
    }

    public function test_filtra_por_categoria(): void
    {
        $e = $this->escenario();

        $this->assertSame(
            collect([$e['alfa']->id, $e['gamma']->id])->sort()->values()->all(),
            $this->idsCon(['categoria_id' => [$this->catA->id]])
        );
    }

    public function test_filtra_por_medio_de_cobro(): void
    {
        $e = $this->escenario();

        $this->assertSame(
            collect([$e['alfa']->id, $e['gamma']->id])->sort()->values()->all(),
            $this->idsCon(['cuenta_tesoreria_id' => [$this->cuenta->id]])
        );
    }

    public function test_filtra_por_estado_del_cobro(): void
    {
        $e = $this->escenario();

        $this->assertSame([$e['beta']->id], $this->idsCon(['estado_cobro' => ['pendiente']]));
        $this->assertSame(
            collect([$e['alfa']->id, $e['gamma']->id])->sort()->values()->all(),
            $this->idsCon(['estado_cobro' => ['cobrado']])
        );
        // Las dos opciones tildadas equivalen a no filtrar.
        $this->assertCount(3, $this->idsCon(['estado_cobro' => ['cobrado', 'pendiente']]));
    }

    public function test_filtra_por_descripcion_parcial(): void
    {
        $e = $this->escenario();

        $this->assertSame([$e['alfa']->id], $this->idsCon(['descripcion' => 'ALFA']));
    }

    public function test_filtra_por_usuario(): void
    {
        $e = $this->escenario();

        $this->assertSame([$e['beta']->id], $this->idsCon(['usuario_id' => [$this->otroUsuario->id]]));
    }

    public function test_filtra_por_rango_de_emision(): void
    {
        $e = $this->escenario();

        $this->assertSame(
            [$e['alfa']->id],
            $this->idsCon(['fecha_desde' => '2026-08-01', 'fecha_hasta' => '2026-08-31'])
        );
    }

    public function test_combina_filtros_con_and(): void
    {
        $e = $this->escenario();

        $this->assertSame(
            [$e['gamma']->id],
            $this->idsCon(['categoria_id' => [$this->catA->id], 'fecha_hasta' => '2026-07-31'])
        );
    }
}
