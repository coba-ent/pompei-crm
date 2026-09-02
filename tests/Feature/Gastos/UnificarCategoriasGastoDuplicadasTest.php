<?php

namespace Tests\Feature\Gastos;

use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `gastos:unificar-categorias-duplicadas`.
 *
 * Las dos tandas de importación desde Contagram crearon la jerarquía de categorías cada una por
 * su lado comparando el nombre crudo del Excel, así que quedaron pares como `Sueldo`/`Sueldos`
 * bajo el mismo padre: la misma categoría dos veces en el desplegable del alta de Gasto.
 *
 * Lo que estos casos protegen, sobre todo, es lo que el comando NO tiene que hacer: unir
 * homónimos que cuelgan de padres distintos (`Alquiler / Juan Personal` vs
 * `Alquiler / Oficina Pompei` son gastos de cosas diferentes) y borrar categorías en uso por
 * otro módulo.
 */
class UnificarCategoriasGastoDuplicadasTest extends TestCase
{
    use RefreshDatabase;

    private function categoria(string $nombre, ?int $padreId = null): Categoria
    {
        return Categoria::create([
            'nombre' => $nombre,
            'tipo' => 'gasto',
            'categoria_padre_id' => $padreId,
            'activo' => true,
        ]);
    }

    private function gasto(Categoria $categoria): Gasto
    {
        return Gasto::create([
            'fecha' => '2026-08-10',
            'monto' => 100,
            'categoria_id' => $categoria->id,
            'cuenta_tesoreria_id' => CuentaTesoreria::factory()->create()->id,
            'descripcion' => 'gasto de prueba',
        ]);
    }

    public function test_unifica_el_par_plural_y_conserva_el_que_mas_gastos_tiene(): void
    {
        $padre = $this->categoria('Empleados');
        $sueldo = $this->categoria('Sueldo', $padre->id);
        $sueldos = $this->categoria('Sueldos', $padre->id);

        $huerfano = $this->gasto($sueldo);
        $this->gasto($sueldos);
        $this->gasto($sueldos);

        $this->artisan('gastos:unificar-categorias-duplicadas')->assertSuccessful();

        // Sobrevive "Sueldos" (2 gastos) y el gasto de "Sueldo" se reapunta, no se pierde.
        $this->assertNull(Categoria::find($sueldo->id));
        $this->assertNotNull(Categoria::find($sueldos->id));
        $this->assertSame($sueldos->id, $huerfano->fresh()->categoria_id);
        $this->assertSame(3, Gasto::where('categoria_id', $sueldos->id)->count());
    }

    public function test_no_une_homonimos_de_padres_distintos(): void
    {
        $juan = $this->categoria('Juan Personal');
        $oficina = $this->categoria('Oficina Pompei');
        $alquilerJuan = $this->categoria('Alquiler', $juan->id);
        $alquilerOficina = $this->categoria('Alquiler', $oficina->id);

        $this->gasto($alquilerJuan);
        $this->gasto($alquilerOficina);

        $this->artisan('gastos:unificar-categorias-duplicadas')->assertSuccessful();

        $this->assertNotNull(Categoria::find($alquilerJuan->id));
        $this->assertNotNull(Categoria::find($alquilerOficina->id));
    }

    public function test_colapsa_la_categoria_anidada_dentro_de_otra_del_mismo_nombre(): void
    {
        $externa = $this->categoria('Juan Personal');
        $interna = $this->categoria('Juan Personal', $externa->id);
        $hija = $this->categoria('Vivo Verde', $interna->id);
        $gasto = $this->gasto($interna);

        $this->artisan('gastos:unificar-categorias-duplicadas')->assertSuccessful();

        // La interna desaparece; sus gastos y sus hijas quedan colgando de la externa.
        $this->assertNull(Categoria::find($interna->id));
        $this->assertSame($externa->id, $gasto->fresh()->categoria_id);
        $this->assertSame($externa->id, $hija->fresh()->categoria_padre_id);
    }

    public function test_tras_colapsar_unifica_las_hijas_que_quedan_hermanas(): void
    {
        // El caso real: "Vivo verde" colgaba de la externa y "Vivo Verde" de la interna, así que
        // sólo se ven como duplicados una vez colapsado el padre repetido.
        $externa = $this->categoria('Juan Personal');
        $interna = $this->categoria('Juan Personal', $externa->id);
        $vieja = $this->categoria('Vivo verde', $externa->id);
        $nueva = $this->categoria('Vivo Verde', $interna->id);

        $this->gasto($nueva);

        $this->artisan('gastos:unificar-categorias-duplicadas')->assertSuccessful();

        $this->assertNull(Categoria::find($vieja->id), 'La vacía tenía que desaparecer.');
        $this->assertNotNull(Categoria::find($nueva->id), 'La que tiene gastos tenía que sobrevivir.');
    }

    public function test_no_borra_una_categoria_en_uso_por_otro_modulo(): void
    {
        $padre = $this->categoria('Empleados');
        $singular = $this->categoria('Sueldo', $padre->id);
        $plural = $this->categoria('Sueldos', $padre->id);
        $this->gasto($plural);

        // Sin gastos propios, pero referenciada desde Compras: no es un duplicado suelto.
        \App\Models\Compra::factory()->create(['categoria_id' => $singular->id]);

        $this->artisan('gastos:unificar-categorias-duplicadas')->assertSuccessful();

        $this->assertNotNull(Categoria::find($singular->id));
    }

    public function test_es_idempotente(): void
    {
        $padre = $this->categoria('Empleados');
        $this->categoria('Sueldo', $padre->id);
        $plural = $this->categoria('Sueldos', $padre->id);
        $this->gasto($plural);

        $this->artisan('gastos:unificar-categorias-duplicadas')->assertSuccessful();
        $quedan = Categoria::where('tipo', 'gasto')->count();

        $this->artisan('gastos:unificar-categorias-duplicadas')->assertSuccessful();

        $this->assertSame($quedan, Categoria::where('tipo', 'gasto')->count());
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $padre = $this->categoria('Empleados');
        $singular = $this->categoria('Sueldo', $padre->id);
        $plural = $this->categoria('Sueldos', $padre->id);
        $gasto = $this->gasto($singular);
        $this->gasto($plural);
        $this->gasto($plural);

        $this->artisan('gastos:unificar-categorias-duplicadas', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(Categoria::find($singular->id));
        $this->assertSame($singular->id, $gasto->fresh()->categoria_id);
    }
}
