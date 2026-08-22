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
 * spec 073 — ciclo de vida de las notificaciones: aparece, marcar leída baja el contador de ESE
 * usuario y no el de otro, al resolverse desaparece sola y su marca se descarta, y al reaparecer
 * cuenta de nuevo como no leída (FR-035, historia 5 escenario 6) — sin timestamp en la clave, así
 * que una venta que deja al producto igual de bajo NO la vuelve a marcar como no leída.
 */
class NotificacionesTest extends TestCase
{
    use RefreshDatabase;

    private Deposito $local;
    private User $usuario;
    private User $otroUsuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->local = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        DB::table('ml_configuracion')->insert([
            'deposito_id' => $this->local->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rol = Rol::create(['nombre' => 'Con monitoreo', 'es_sistema' => false]);
        $permiso = Permiso::create(['codigo' => 'monitoreo.ver', 'descripcion' => 'x', 'modulo' => 'monitoreo']);
        $rol->permisos()->attach($permiso->id);

        $this->usuario = User::factory()->create();
        $this->usuario->roles()->attach($rol->id);
        $this->otroUsuario = User::factory()->create();
        $this->otroUsuario->roles()->attach($rol->id);
    }

    private function productoBajo(): Producto
    {
        $p = Producto::factory()->create(['tipo' => 'producto', 'activo' => true, 'punto_reposicion' => 5]);
        Stock::create(['producto_id' => $p->id, 'deposito_id' => $this->local->id, 'cantidad' => 1]);

        return $p;
    }

    public function test_la_alerta_aparece_como_no_leida(): void
    {
        $p = $this->productoBajo();

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.resumen'))->json();

        $claves = array_column($resp['notificaciones']['items'], 'clave');
        $this->assertContains('reposicion:'.$p->id, $claves);
        $this->assertSame(1, $resp['notificaciones']['sinLeer']);
    }

    public function test_marcar_leida_baja_el_contador_de_ese_usuario_y_no_el_de_otro(): void
    {
        $p = $this->productoBajo();
        $clave = 'reposicion:'.$p->id;

        $this->actingAs($this->usuario)
            ->postJson(route('monitoreo.notificaciones.leer'), ['claves' => [$clave]])
            ->assertOk()
            ->assertJson(['ok' => true, 'sinLeer' => 0]);

        $respUsuario = $this->actingAs($this->usuario)->getJson(route('monitoreo.resumen'))->json();
        $respOtro = $this->actingAs($this->otroUsuario)->getJson(route('monitoreo.resumen'))->json();

        $this->assertSame(0, $respUsuario['notificaciones']['sinLeer']);
        $this->assertSame(1, $respOtro['notificaciones']['sinLeer']);
    }

    public function test_al_resolverse_desaparece_y_su_marca_se_descarta(): void
    {
        $p = $this->productoBajo();
        $clave = 'reposicion:'.$p->id;

        $this->actingAs($this->usuario)
            ->postJson(route('monitoreo.notificaciones.leer'), ['claves' => [$clave]]);

        // Se resuelve: sube el stock por encima del punto de reposición.
        Stock::where('producto_id', $p->id)->update(['cantidad' => 50]);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.resumen'))->json();
        $claves = array_column($resp['notificaciones']['items'], 'clave');
        $this->assertNotContains($clave, $claves);

        $this->assertDatabaseMissing('notificaciones_leidas', [
            'user_id' => $this->usuario->id, 'clave' => $clave,
        ]);
    }

    public function test_al_reaparecer_cuenta_como_no_leida(): void
    {
        $p = $this->productoBajo();
        $clave = 'reposicion:'.$p->id;

        $this->actingAs($this->usuario)
            ->postJson(route('monitoreo.notificaciones.leer'), ['claves' => [$clave]]);

        // Resuelve...
        Stock::where('producto_id', $p->id)->update(['cantidad' => 50]);
        $this->actingAs($this->usuario)->getJson(route('monitoreo.resumen')); // dispara la limpieza

        // ...y vuelve a caer.
        Stock::where('producto_id', $p->id)->update(['cantidad' => 1]);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.resumen'))->json();
        $item = collect($resp['notificaciones']['items'])->firstWhere('clave', $clave);

        $this->assertNotNull($item);
        $this->assertFalse($item['leida']);
    }

    public function test_una_venta_que_deja_el_producto_igual_de_bajo_no_reaparece_como_no_leida(): void
    {
        // El defecto que tenía la clave con timestamp: cada movimiento de stock cambiaba el
        // MAX(created_at), así que un producto que se mantiene bajo su punto volvía a alertar
        // como no leído en cada venta. Sin timestamp en la clave, no pasa.
        $p = $this->productoBajo();
        $clave = 'reposicion:'.$p->id;

        $this->actingAs($this->usuario)
            ->postJson(route('monitoreo.notificaciones.leer'), ['claves' => [$clave]]);

        // Una venta que mueve stock pero deja al producto igual de bajo (sigue en 1, por debajo
        // del punto de reposición de 5): se registra un movimiento sin cambiar el estado.
        DB::table('movimientos_stock')->insert([
            'producto_id' => $p->id, 'deposito_id' => $this->local->id, 'cantidad' => 0,
            'tipo' => 'salida', 'origen_type' => 'App\\Models\\Venta', 'origen_id' => 1,
            'fecha' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.resumen'))->json();
        $item = collect($resp['notificaciones']['items'])->firstWhere('clave', $clave);

        $this->assertNotNull($item);
        $this->assertTrue($item['leida']);
    }

    public function test_marcar_todas_no_silencia_lo_que_aparecio_despues(): void
    {
        // FR-036a: "todas" marca únicamente las claves que el cliente envía (las que tenía a la
        // vista), no todo lo que exista en el servidor en este instante.
        $p1 = $this->productoBajo();

        $this->actingAs($this->usuario)
            ->postJson(route('monitoreo.notificaciones.leer'), ['claves' => ['reposicion:'.$p1->id], 'todas' => true])
            ->assertOk();

        // Aparece un segundo problema después de que el cliente ya había marcado "todas".
        $p2 = $this->productoBajo();

        $resp = $this->actingAs($this->usuario)->getJson(route('monitoreo.resumen'))->json();

        $this->assertSame(1, $resp['notificaciones']['sinLeer']);
        $item2 = collect($resp['notificaciones']['items'])->firstWhere('clave', 'reposicion:'.$p2->id);
        $this->assertFalse($item2['leida']);
    }
}
