<?php

namespace Tests\Feature\Monitoreo;

use App\Models\Deposito;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * spec 073 — la regla `stock <= punto_reposicion` en sus dos formas: Local para "a reponer"
 * (FR-018), Local+Full sólo publicados para "riesgo ML" (FR-019). El caso que separa los bloques:
 * Local 1 / Full 50 aparece en reponer y NO en riesgo (FR-019a). null/0 no generan alerta
 * (FR-011a). Sin fila en stocks = 0. Stock negativo entra.
 */
class PuntoReposicionTest extends TestCase
{
    use RefreshDatabase;

    private Deposito $local;
    private Deposito $full;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->local = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $this->full = Deposito::create(['nombre' => 'Full', 'activo' => true]);

        DB::table('ml_configuracion')->insert([
            'deposito_id' => $this->local->id,
            'deposito_full_id' => $this->full->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rol = Rol::create(['nombre' => 'Con monitoreo', 'es_sistema' => false]);
        $permiso = Permiso::create(['codigo' => 'monitoreo.ver', 'descripcion' => 'x', 'modulo' => 'monitoreo']);
        $rol->permisos()->attach($permiso->id);
        $this->usuario = User::factory()->create();
        $this->usuario->roles()->attach($rol->id);
    }

    private function producto(array $attrs = []): Producto
    {
        return Producto::factory()->create(array_merge(['tipo' => 'producto', 'activo' => true], $attrs));
    }

    private function stock(Producto $producto, Deposito $deposito, float $cantidad): void
    {
        Stock::create(['producto_id' => $producto->id, 'deposito_id' => $deposito->id, 'cantidad' => $cantidad]);
    }

    public function test_producto_bajo_su_punto_aparece_en_reponer(): void
    {
        $p = $this->producto(['punto_reposicion' => 6]);
        $this->stock($p, $this->local, 2);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();

        $this->assertContains($p->id, array_column($resp['data'], 'id'));
    }

    public function test_producto_con_stock_local_lleno_y_full_bajo_no_aparece_en_reponer(): void
    {
        // El bloque "a reponer" mira sólo Local: si ahí está por encima del punto, no aparece.
        $p = $this->producto(['punto_reposicion' => 6]);
        $this->stock($p, $this->local, 20);
        $this->stock($p, $this->full, 0);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();

        $this->assertNotContains($p->id, array_column($resp['data'], 'id'));
    }

    public function test_local_1_full_50_esta_en_reponer_y_no_en_riesgo(): void
    {
        // El caso que separa los dos bloques: hay que reponerlo en Local, pero la publicación
        // no corre ningún riesgo porque Full lo cubre (FR-019a).
        $p = $this->producto(['punto_reposicion' => 6]);
        $this->stock($p, $this->local, 1);
        $this->stock($p, $this->full, 50);
        DB::table('ml_publicacion_producto')->insert([
            'ml_item_id' => 'MLA'.$p->id, 'producto_id' => $p->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $reponer = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();
        $riesgo = $this->actingAs($this->usuario)->getJson(route('monitoreo.riesgoMl'))->json();

        $this->assertContains($p->id, array_column($reponer['data'], 'id'));
        $this->assertNotContains($p->id, array_column($riesgo['data'], 'id'));
    }

    public function test_publicado_con_local_y_full_bajos_aparece_en_riesgo(): void
    {
        $p = $this->producto(['punto_reposicion' => 6]);
        $this->stock($p, $this->local, 1);
        $this->stock($p, $this->full, 1);
        DB::table('ml_publicacion_producto')->insert([
            'ml_item_id' => 'MLA'.$p->id, 'producto_id' => $p->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $riesgo = $this->actingAs($this->usuario)->getJson(route('monitoreo.riesgoMl'))->json();

        $this->assertContains($p->id, array_column($riesgo['data'], 'id'));
    }

    public function test_producto_sin_publicar_no_aparece_en_riesgo_ml(): void
    {
        $p = $this->producto(['punto_reposicion' => 6]);
        $this->stock($p, $this->local, 1);

        $riesgo = $this->actingAs($this->usuario)->getJson(route('monitoreo.riesgoMl'))->json();

        $this->assertNotContains($p->id, array_column($riesgo['data'], 'id'));
    }

    public function test_punto_reposicion_null_no_genera_alerta(): void
    {
        $p = $this->producto(['punto_reposicion' => null]);
        $this->stock($p, $this->local, 0);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();

        $this->assertNotContains($p->id, array_column($resp['data'], 'id'));
    }

    public function test_punto_reposicion_cero_no_genera_alerta(): void
    {
        $p = $this->producto(['punto_reposicion' => 0]);
        $this->stock($p, $this->local, 0);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();

        $this->assertNotContains($p->id, array_column($resp['data'], 'id'));
    }

    public function test_sin_fila_en_stocks_cuenta_como_cero(): void
    {
        $p = $this->producto(['punto_reposicion' => 5]);
        // Sin crear ninguna fila en `stocks`.

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();

        $this->assertContains($p->id, array_column($resp['data'], 'id'));
    }

    public function test_stock_negativo_entra_en_la_alerta(): void
    {
        $p = $this->producto(['punto_reposicion' => 5]);
        $this->stock($p, $this->local, -3);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();

        $this->assertContains($p->id, array_column($resp['data'], 'id'));
    }

    public function test_producto_de_tipo_servicio_no_aparece(): void
    {
        $p = $this->producto(['tipo' => 'servicio', 'punto_reposicion' => 5]);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();

        $this->assertNotContains($p->id, array_column($resp['data'], 'id'));
    }

    public function test_producto_inactivo_no_aparece(): void
    {
        $p = $this->producto(['activo' => false, 'punto_reposicion' => 5]);
        $this->stock($p, $this->local, 0);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.reponer'))->json();

        $this->assertNotContains($p->id, array_column($resp['data'], 'id'));
    }
}
