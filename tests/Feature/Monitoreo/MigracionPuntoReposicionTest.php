<?php

namespace Tests\Feature\Monitoreo;

use App\Models\Cliente;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** spec 073 — los 8 casos fijados en contracts/migracion-punto-reposicion.md. */
class MigracionPuntoReposicionTest extends TestCase
{
    use RefreshDatabase;

    private function crearLista(): int
    {
        return DB::table('listas_precio')->insertGetId([
            'nombre' => 'Punto Reposición', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function precio(int $listaId, Producto $producto, ?float $valor): void
    {
        DB::table('precios_producto')->insert([
            'lista_precio_id' => $listaId, 'producto_id' => $producto->id, 'precio' => $valor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dry_run_no_escribe(): void
    {
        $lista = $this->crearLista();
        $p = Producto::factory()->create(['punto_reposicion' => 0]);
        $this->precio($lista, $p, 6);

        $this->artisan('migracion:punto-reposicion')->assertSuccessful();

        $this->assertSame(0, $p->fresh()->punto_reposicion);
        $this->assertDatabaseHas('listas_precio', ['id' => $lista]);
    }

    public function test_migra_valores_enteros(): void
    {
        $lista = $this->crearLista();
        $p = Producto::factory()->create(['punto_reposicion' => 0]);
        $this->precio($lista, $p, 6.00);

        $this->artisan('migracion:punto-reposicion --aplicar')->assertSuccessful();

        $this->assertSame(6, $p->fresh()->punto_reposicion);
    }

    public function test_redondea_decimales(): void
    {
        $lista = $this->crearLista();
        $p = Producto::factory()->create(['punto_reposicion' => 0]);
        $this->precio($lista, $p, 5.60);

        $this->artisan('migracion:punto-reposicion --aplicar')->assertSuccessful();

        $this->assertSame(6, $p->fresh()->punto_reposicion);
    }

    public function test_negativo_y_cero_quedan_en_cero(): void
    {
        $lista = $this->crearLista();
        $negativo = Producto::factory()->create(['punto_reposicion' => 0]);
        $cero = Producto::factory()->create(['punto_reposicion' => 0]);
        $this->precio($lista, $negativo, -1);
        $this->precio($lista, $cero, 0);

        $this->artisan('migracion:punto-reposicion --aplicar')->assertSuccessful();

        $this->assertSame(0, $negativo->fresh()->punto_reposicion);
        $this->assertSame(0, $cero->fresh()->punto_reposicion);
    }

    public function test_aborta_con_referencias(): void
    {
        $lista = $this->crearLista();
        $p = Producto::factory()->create(['punto_reposicion' => 0]);
        $this->precio($lista, $p, 6);
        Cliente::factory()->create(['lista_precio_id' => $lista]);

        $this->artisan('migracion:punto-reposicion --aplicar --eliminar-lista')->assertFailed();

        $this->assertDatabaseHas('listas_precio', ['id' => $lista]);
    }

    public function test_elimina_limpio_sin_referencias(): void
    {
        $lista = $this->crearLista();
        $p = Producto::factory()->create(['punto_reposicion' => 0]);
        $this->precio($lista, $p, 6);

        $this->artisan('migracion:punto-reposicion --aplicar --eliminar-lista')->assertSuccessful();

        $this->assertDatabaseMissing('listas_precio', ['id' => $lista]);
        $this->assertDatabaseMissing('precios_producto', ['lista_precio_id' => $lista]);
        $this->assertSame(6, $p->fresh()->punto_reposicion);
    }

    public function test_idempotente(): void
    {
        $lista = $this->crearLista();
        $p = Producto::factory()->create(['punto_reposicion' => 0]);
        $this->precio($lista, $p, 6);

        $this->artisan('migracion:punto-reposicion --aplicar')->assertSuccessful();
        $this->artisan('migracion:punto-reposicion --aplicar')->assertSuccessful();

        $this->assertSame(6, $p->fresh()->punto_reposicion);
    }

    public function test_no_pisa_lo_cargado_a_mano(): void
    {
        $lista = $this->crearLista();
        $p = Producto::factory()->create(['punto_reposicion' => 99]);
        $this->precio($lista, $p, 6);

        $this->artisan('migracion:punto-reposicion --aplicar')->assertSuccessful();

        $this->assertSame(99, $p->fresh()->punto_reposicion);
    }
}
