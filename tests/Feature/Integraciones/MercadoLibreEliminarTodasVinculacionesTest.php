<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 035, US4 (FR-015..FR-022): "Eliminar todas las vinculaciones" borra
 * TODOS los vínculos del lado CRM, sin request hacia Mercado Libre, con
 * candado compartido y sin depender de la función avanzada/modo sólo lectura.
 */
class MercadoLibreEliminarTodasVinculacionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vigente',
            'refresh_token' => 'rtk-vigente',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);
    }

    private function crearVinculo(): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create();

        return MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA-'.$producto->id, 'producto_id' => $producto->id]);
    }

    public function test_elimina_todos_los_vinculos_sin_disparar_requests(): void
    {
        $this->crearVinculo();
        $this->crearVinculo();
        $this->crearVinculo();

        Http::fake();

        $respuesta = $this->deleteJson(route('ingresos.mercadolibre.vinculaciones.eliminarTodas'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'eliminados' => 3]);
        $this->assertSame(0, MercadoLibrePublicacionProducto::count());
        Http::assertNothingSent();
    }

    public function test_registra_un_unico_log_de_la_operacion(): void
    {
        $this->crearVinculo();

        $respuesta = $this->deleteJson(route('ingresos.mercadolibre.vinculaciones.eliminarTodas'));

        $respuesta->assertOk();
        $this->assertSame(1, MercadoLibreOperacionLog::where('operacion', 'eliminar_todas_vinculaciones')->count());
    }

    public function test_candado_tomado_por_una_sincronizacion_rechaza_la_eliminacion(): void
    {
        $this->crearVinculo();

        $lock = Cache::lock(SincronizadorStock::LOCK_KEY, 300);
        $lock->get();

        try {
            $respuesta = $this->deleteJson(route('ingresos.mercadolibre.vinculaciones.eliminarTodas'));

            $respuesta->assertStatus(409);
            $this->assertSame('salteada', $respuesta->json('tipo'));
            $this->assertSame(1, MercadoLibrePublicacionProducto::count(), 'No debe borrar nada si el candado está tomado.');
        } finally {
            $lock->release();
        }
    }

    public function test_sin_cuenta_conectada_rechaza_la_eliminacion(): void
    {
        MercadoLibreCuenta::query()->delete();
        $this->crearVinculo();

        $respuesta = $this->deleteJson(route('ingresos.mercadolibre.vinculaciones.eliminarTodas'));

        $respuesta->assertStatus(409);
        $this->assertSame(1, MercadoLibrePublicacionProducto::count());
    }

    public function test_sin_vinculos_existentes_no_falla(): void
    {
        $respuesta = $this->deleteJson(route('ingresos.mercadolibre.vinculaciones.eliminarTodas'));

        $respuesta->assertOk()->assertJson(['ok' => true, 'eliminados' => 0]);
    }
}
