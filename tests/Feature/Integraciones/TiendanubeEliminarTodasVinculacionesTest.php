<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeRestOperacionLog;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Tiendanube\SincronizadorStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 035, US4 (FR-015..FR-022): "Eliminar todas las vinculaciones" borra
 * TODOS los vínculos del lado CRM, sin request hacia Tiendanube, con candado
 * compartido y sin depender de la función avanzada/modo sólo lectura.
 * Equivalente Tiendanube de MercadoLibreEliminarTodasVinculacionesTest.
 */
class TiendanubeEliminarTodasVinculacionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'atk', 'store_id' => '999', 'estado' => EstadoConexion::Conectada,
        ]);
    }

    private function crearVinculo(): TiendanubeVarianteProducto
    {
        $producto = Producto::factory()->create();

        return TiendanubeVarianteProducto::create([
            'variant_id' => $producto->id * 10,
            'tn_product_id' => (string) $producto->id,
            'producto_id' => $producto->id,
        ]);
    }

    public function test_elimina_todos_los_vinculos_sin_disparar_requests(): void
    {
        $this->crearVinculo();
        $this->crearVinculo();
        $this->crearVinculo();

        Http::fake();

        $respuesta = $this->deleteJson(route('ingresos.tiendanube.vinculaciones.eliminarTodas'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'eliminados' => 3]);
        $this->assertSame(0, TiendanubeVarianteProducto::count());
        Http::assertNothingSent();
    }

    public function test_registra_un_unico_log_de_la_operacion(): void
    {
        $this->crearVinculo();

        $respuesta = $this->deleteJson(route('ingresos.tiendanube.vinculaciones.eliminarTodas'));

        $respuesta->assertOk();
        $this->assertSame(1, TiendanubeRestOperacionLog::where('operacion', 'eliminar_todas_vinculaciones')->count());
    }

    public function test_candado_tomado_por_una_sincronizacion_rechaza_la_eliminacion(): void
    {
        $this->crearVinculo();

        $lock = Cache::lock(SincronizadorStock::LOCK_KEY, 300);
        $lock->get();

        try {
            $respuesta = $this->deleteJson(route('ingresos.tiendanube.vinculaciones.eliminarTodas'));

            $respuesta->assertStatus(409);
            $this->assertSame('salteada', $respuesta->json('tipo'));
            $this->assertSame(1, TiendanubeVarianteProducto::count(), 'No debe borrar nada si el candado está tomado.');
        } finally {
            $lock->release();
        }
    }

    public function test_sin_conexion_establecida_rechaza_la_eliminacion(): void
    {
        TiendanubeConexionRest::actual()->update(['access_token' => null, 'estado' => EstadoConexion::NoConfigurada]);
        $this->crearVinculo();

        $respuesta = $this->deleteJson(route('ingresos.tiendanube.vinculaciones.eliminarTodas'));

        $respuesta->assertStatus(409);
        $this->assertSame(1, TiendanubeVarianteProducto::count());
    }

    public function test_sin_vinculos_existentes_no_falla(): void
    {
        $respuesta = $this->deleteJson(route('ingresos.tiendanube.vinculaciones.eliminarTodas'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'eliminados' => 0]);
    }
}
