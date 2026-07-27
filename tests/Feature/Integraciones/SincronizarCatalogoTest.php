<?php

namespace Tests\Feature\Integraciones;

use App\Models\Integracion;
use App\Models\IntegracionEvento;
use App\Models\Producto;
use App\Models\ProductoCanal;
use App\Models\ProductoVariante;
use App\Models\Rol;
use App\Models\User;
use App\Services\Integraciones\SincronizadorCatalogo;
use App\Services\Integraciones\TiendaNube\ClienteTiendaNube;
use App\Services\Integraciones\TiendaNube\ClienteTiendaNubeFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US3 — Mapeo de catálogo TiendaNube por SKU (FR-016..020, SC-005). */
class SincronizarCatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    private Integracion $integracion;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);

        $this->integracion = Integracion::create([
            'canal' => 'tiendanube',
            'credenciales' => ['access_token' => 'x', 'refresh_token' => 'y', 'expires_at' => now()->addDays(30), 'cuenta_id' => '1'],
            'config' => ['lista_precio_id' => null, 'deposito_id' => null],
            'estado' => 'conectado',
            'activo' => true,
        ]);
    }

    /** @return ClienteTiendaNubeFake */
    private function fakeTn()
    {
        return app(ClienteTiendaNube::class);
    }

    public function test_sincronizar_catalogo_mapea_por_sku_y_reporta_no_sincronizados_con_motivo(): void
    {
        $productoMatcheado = Producto::factory()->create(['codigo' => 'SKU-OK', 'activo' => true]);
        $productoInactivo = Producto::factory()->create(['codigo' => 'SKU-INACTIVO', 'activo' => false]);

        $productoAmbiguoA = Producto::factory()->create(['codigo' => 'AMBIGUO']);
        $productoAmbiguoB = Producto::factory()->create(['codigo' => 'BASE-AMBIGUO']);
        ProductoVariante::create(['producto_id' => $productoAmbiguoB->id, 'sku' => 'AMBIGUO', 'activo' => true]);

        $fake = $this->fakeTn();
        $fake->agregarItemCatalogo(['id_externo' => 'EXT-1', 'sku' => 'SKU-OK', 'nombre' => 'Producto OK', 'precio' => 10, 'stock' => 5]);
        $fake->agregarItemCatalogo(['id_externo' => 'EXT-2', 'sku' => null, 'nombre' => 'Sin SKU']);
        $fake->agregarItemCatalogo(['id_externo' => 'EXT-3', 'sku' => 'NO-EXISTE', 'nombre' => 'Sin match']);
        $fake->agregarItemCatalogo(['id_externo' => 'EXT-4', 'sku' => 'AMBIGUO', 'nombre' => 'Duplicado']);
        $fake->agregarItemCatalogo(['id_externo' => 'EXT-5', 'sku' => 'SKU-INACTIVO', 'nombre' => 'Inactivo']);

        $respuesta = $this->post(route('integraciones.tn.sync-catalogo'));
        $respuesta->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, ProductoCanal::count());
        $mapeo = ProductoCanal::first();
        $this->assertSame($productoMatcheado->id, $mapeo->producto_id);

        $eventos = IntegracionEvento::where('tipo', SincronizadorCatalogo::TIPO_EVENTO)->get()->keyBy('id_externo');

        $this->assertSame('procesado', $eventos['EXT-1']->estado);
        $this->assertSame('ignorado', $eventos['EXT-2']->estado);
        $this->assertStringContainsString('SKU', $eventos['EXT-2']->detalle);
        $this->assertSame('ignorado', $eventos['EXT-3']->estado);
        $this->assertStringContainsString('SKU', $eventos['EXT-3']->detalle);
        $this->assertSame('error', $eventos['EXT-4']->estado);
        $this->assertStringContainsString('duplicado', $eventos['EXT-4']->detalle);
        $this->assertSame('ignorado', $eventos['EXT-5']->estado);
        $this->assertStringContainsString('inactivo', $eventos['EXT-5']->detalle);
    }
}
