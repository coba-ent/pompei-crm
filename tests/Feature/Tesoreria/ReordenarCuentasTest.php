<?php

namespace Tests\Feature\Tesoreria;

use App\Models\CuentaTesoreria;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Spec 085 — PATCH /tesoreria/cuentas/orden.
 *
 * Lo que estos tests fijan no es "se puede reordenar", sino que el reordenamiento
 * es una escritura acotada: toca `orden` y nada más, y ante cualquier discrepancia
 * de conjunto no escribe absolutamente nada.
 */
class ReordenarCuentasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La respuesta 200 incluye `saldos()` para que el front repinte las cards sin un
     * segundo request. Ese cálculo pasa por `bucketsEnSql()`, que usa `DATEDIFF` —
     * función de MySQL que SQLite no tiene, así que bajo la suite revienta (limitación
     * previa a esta feature; ver tests/Feature/Creditos/TesoreriaIntactaTest.php). Se
     * lo reemplaza acá por un doble: lo que fijan estos tests es el reordenamiento, no
     * el cálculo de saldos, que tiene su propia cobertura.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(Tesoreria::class, new class extends Tesoreria
        {
            public function __construct() {}

            public function saldos(?Carbon $fecha = null): array
            {
                return ['stub' => true];
            }
        });
    }

    /**
     * El grupo de rutas de tesorería exige `tesoreria.ver`; la ruta de reordenamiento
     * suma `tesoreria.editar` encima. Por eso `ver` va siempre, y `$codigos` dice qué
     * permisos adicionales tiene el usuario: sin `editar` la respuesta debe ser 403.
     *
     * @param  array<int, string>  $codigos
     */
    private function usuarioCon(array $codigos = ['tesoreria.editar']): User
    {
        $rol = Rol::create(['nombre' => 'Tesorero '.implode('-', $codigos).'-'.uniqid(), 'es_sistema' => false]);

        foreach (array_unique(array_merge(['tesoreria.ver'], $codigos)) as $codigo) {
            $permiso = Permiso::firstOrCreate(
                ['codigo' => $codigo],
                ['descripcion' => 'Test', 'modulo' => 'tesoreria'],
            );
            $rol->permisos()->attach($permiso->id);
        }

        $user = User::factory()->create();
        $user->roles()->attach($rol->id);

        return $user;
    }

    /** @return array<int, CuentaTesoreria> */
    private function cuentas(string $tipo, int $cantidad): array
    {
        return collect(range(1, $cantidad))
            ->map(fn (int $i) => CuentaTesoreria::factory()->tipo($tipo)->create(['orden' => $i]))
            ->all();
    }

    private function reordenar(User $user, string $tipo, array $ids)
    {
        return $this->actingAs($user)->patchJson(route('tesoreria.cuentas.orden'), [
            'tipo' => $tipo,
            'ids' => $ids,
        ]);
    }

    /** @return array<int, int|null> */
    private function ordenesActuales(): array
    {
        return CuentaTesoreria::orderBy('id')->pluck('orden', 'id')->all();
    }

    // (a) camino feliz
    public function test_reordenar_persiste_orden_consecutivo_desde_uno(): void
    {
        $user = $this->usuarioCon();
        [$a, $b, $c] = $this->cuentas('efectivo', 3);

        $this->reordenar($user, 'efectivo', [$c->id, $a->id, $b->id])
            ->assertOk()
            ->assertJson(['ok' => true, 'mensaje' => 'Orden actualizado con éxito.'])
            ->assertJsonStructure(['ok', 'mensaje', 'saldos']);

        $this->assertSame(1, $c->fresh()->orden);
        $this->assertSame(2, $a->fresh()->orden);
        $this->assertSame(3, $b->fresh()->orden);
    }

    // (b) normalización de NULL heredado
    public function test_orden_null_heredado_queda_normalizado_a_consecutivo(): void
    {
        $user = $this->usuarioCon();
        $a = CuentaTesoreria::factory()->tipo('banco')->create(['orden' => null]);
        $b = CuentaTesoreria::factory()->tipo('banco')->create(['orden' => null]);

        $this->reordenar($user, 'banco', [$b->id, $a->id])->assertOk();

        $this->assertSame(1, $b->fresh()->orden);
        $this->assertSame(2, $a->fresh()->orden);
    }

    // (c) FR-008: id de otro tipo → 409, y ninguno de los dos bloques se movió
    public function test_id_de_otro_tipo_devuelve_409_sin_tocar_ningun_bloque(): void
    {
        $user = $this->usuarioCon();
        [$a, $b] = $this->cuentas('efectivo', 2);
        $intruso = CuentaTesoreria::factory()->tipo('banco')->create(['orden' => 1]);

        $this->reordenar($user, 'efectivo', [$intruso->id, $a->id, $b->id])
            ->assertStatus(409)
            ->assertJson(['ok' => false]);

        $this->assertSame(1, $a->fresh()->orden);
        $this->assertSame(2, $b->fresh()->orden);
        $this->assertSame(1, $intruso->fresh()->orden);
        $this->assertSame('banco', $intruso->fresh()->tipo);
    }

    // (d) FR-008: lista incompleta → 409
    public function test_lista_incompleta_devuelve_409(): void
    {
        $user = $this->usuarioCon();
        [$a, $b, $c] = $this->cuentas('a_cobrar', 3);

        $this->reordenar($user, 'a_cobrar', [$c->id, $a->id])->assertStatus(409);

        $this->assertSame([1, 2, 3], [$a->fresh()->orden, $b->fresh()->orden, $c->fresh()->orden]);
    }

    // (e) FR-008: id sobrante de una cuenta borrada en paralelo.
    // Cae en 422 y no en 409 porque `exists` se evalúa antes que la comparación de
    // conjunto; el efecto que importa es el mismo: se rechaza sin escribir nada.
    public function test_id_sobrante_de_cuenta_borrada_no_escribe_nada(): void
    {
        $user = $this->usuarioCon();
        [$a, $b] = $this->cuentas('a_pagar', 2);
        $borrada = CuentaTesoreria::factory()->tipo('a_pagar')->create(['orden' => 3]);
        $idBorrada = $borrada->id;
        $borrada->delete();

        $this->reordenar($user, 'a_pagar', [$b->id, $a->id, $idBorrada])
            ->assertStatus(422);

        $this->assertSame(1, $a->fresh()->orden);
        $this->assertSame(2, $b->fresh()->orden);
    }

    // (f) forma inválida
    public function test_id_repetido_devuelve_422(): void
    {
        $user = $this->usuarioCon();
        [$a, $b] = $this->cuentas('efectivo', 2);

        $this->reordenar($user, 'efectivo', [$a->id, $a->id])
            ->assertStatus(422);

        $this->assertSame(1, $a->fresh()->orden);
        $this->assertSame(2, $b->fresh()->orden);
    }

    public function test_tipo_invalido_devuelve_422(): void
    {
        $user = $this->usuarioCon();
        [$a] = $this->cuentas('efectivo', 1);

        $this->reordenar($user, 'inventado', [$a->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');
    }

    // (g) atomicidad
    public function test_el_rechazo_no_deja_ninguna_fila_escrita(): void
    {
        $user = $this->usuarioCon();
        [, $b, $c] = $this->cuentas('banco', 3);
        $antes = $this->ordenesActuales();

        // Orden nuevo válido salvo por una cuenta faltante: si el guardado no fuera
        // atómico, las dos primeras ya habrían quedado escritas antes del rechazo.
        $this->reordenar($user, 'banco', [$c->id, $b->id])->assertStatus(409);

        $this->assertSame($antes, $this->ordenesActuales());
    }

    // (h) permiso
    public function test_sin_permiso_de_edicion_devuelve_403(): void
    {
        $user = $this->usuarioCon([]);
        [$a, $b] = $this->cuentas('efectivo', 2);

        $this->reordenar($user, 'efectivo', [$b->id, $a->id])->assertForbidden();

        $this->assertSame(1, $a->fresh()->orden);
    }

    // (i) invariancia: SC-003 / SC-004 / FR-011
    public function test_reordenar_no_altera_ningun_otro_campo_ni_los_saldos(): void
    {
        $user = $this->usuarioCon();
        $a = CuentaTesoreria::factory()->tipo('efectivo')->sistema()->create(['orden' => 1, 'saldo_inicial' => 1500.50]);
        $b = CuentaTesoreria::factory()->tipo('efectivo')->oculta()->create(['orden' => 2, 'saldo_inicial' => -300.25]);

        $campos = ['nombre', 'tipo', 'visible', 'es_sistema', 'saldo_inicial'];
        $antes = CuentaTesoreria::orderBy('id')->get()->map(fn ($c) => $c->only($campos))->all();
        $saldosAntes = [$a->saldoA(), $b->saldoA()];

        $this->reordenar($user, 'efectivo', [$b->id, $a->id])->assertOk();

        $despues = CuentaTesoreria::orderBy('id')->get()->map(fn ($c) => $c->only($campos))->all();

        $this->assertEquals($antes, $despues);
        $this->assertSame($saldosAntes, [$a->fresh()->saldoA(), $b->fresh()->saldoA()]);
        $this->assertSame(1, $b->fresh()->orden);
        $this->assertSame(2, $a->fresh()->orden);
    }
}
