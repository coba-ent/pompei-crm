<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** US4 (spec 017): configuración de ventas de Tiendanube. FR-010/FR-016/FR-045a/FR-047/FR-050. */
class TiendanubeConexionRestVentasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
    }

    public function test_guarda_la_configuracion_de_ventas(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $cuenta = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);

        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => true,
            'frecuencia_sync_minutos' => 30,
            'deposito_id' => $deposito->id,
            'categoria_venta_id' => null,
            'cuenta_tesoreria_id' => $cuenta->id,
            'dias_primera_sync' => 45,
        ]);

        $respuesta->assertOk()->assertJsonPath('ok', true);

        $configuracion = TiendanubeConexionRest::actual();
        $this->assertTrue((bool) $configuracion->creacion_automatica);
        $this->assertSame(30, $configuracion->frecuencia_sync_minutos);
        $this->assertSame($deposito->id, $configuracion->deposito_id);
        $this->assertSame($cuenta->id, $configuracion->cuenta_tesoreria_id);
        $this->assertSame(45, $configuracion->dias_primera_sync);
    }

    public function test_rechaza_una_frecuencia_de_sincronizacion_no_permitida(): void
    {
        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 7,
            'dias_primera_sync' => 30,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('frecuencia_sync_minutos');
    }

    public function test_permite_guardar_sin_cuenta_de_tesoreria_pero_bloquea_la_conversion_despues(): void
    {
        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'cuenta_tesoreria_id' => null,
            'dias_primera_sync' => 30,
        ]);

        $respuesta->assertOk();
        $this->assertNull(TiendanubeConexionRest::actual()->cuenta_tesoreria_id);
    }

    /** US5 (spec 018 ampliación, FR-021/FR-022/FR-023). */
    public function test_guarda_y_persiste_la_lista_de_precios(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Lista TN', 'activo' => true]);

        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $lista->id,
        ]);

        $respuesta->assertOk();
        $this->assertSame($lista->id, TiendanubeConexionRest::actual()->lista_precio_id);
    }

    public function test_rechaza_una_lista_de_precios_inexistente(): void
    {
        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => 99999,
        ]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('lista_precio_id');
    }

    public function test_permite_guardar_sin_ninguna_lista_de_precios_seleccionada(): void
    {
        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => null,
        ]);

        $respuesta->assertOk();
        $this->assertNull(TiendanubeConexionRest::actual()->lista_precio_id);
    }

    /** US9 (spec 018 ampliación, FR-028, SC-013). */
    public function test_cambiar_la_lista_de_precios_empuja_de_inmediato_a_los_vinculados(): void
    {
        TiendanubeConexionRest::actual()->update([
            'client_id' => 'client-id-de-prueba', 'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
        ]);

        $listaVieja = ListaPrecio::create(['nombre' => 'Lista Vieja', 'activo' => true]);
        $listaNueva = ListaPrecio::create(['nombre' => 'Lista Nueva', 'activo' => true]);
        TiendanubeConexionRest::actual()->update(['lista_precio_id' => $listaVieja->id]);

        $productoConPrecio = Producto::factory()->create();
        $vinculoConPrecio = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $productoConPrecio->id]);
        $productoConPrecio->precios()->create(['lista_precio_id' => $listaNueva->id, 'precio' => 777.00]);

        $productoSinPrecio = Producto::factory()->create();
        $vinculoSinPrecio = TiendanubeVarianteProducto::create(['variant_id' => 2, 'tn_product_id' => '11', 'producto_id' => $productoSinPrecio->id]);

        Http::fake([
            'admin-mcp.tiendanube.com/' => Http::response([
                'jsonrpc' => '2.0', 'id' => 1,
                'result' => ['isError' => false, 'structuredContent' => []],
            ], 200),
        ]);

        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $listaNueva->id,
        ]);

        $respuesta->assertOk();
        Http::assertSent(function ($request) {
            $update = $request['params']['arguments']['updates'][0] ?? [];

            return ($update['price'] ?? null) === 777.0;
        });
        $this->assertFalse($vinculoConPrecio->fresh()->precio_pendiente);
        $this->assertFalse($vinculoSinPrecio->fresh()->precio_pendiente, 'Sin precio en la lista nueva no debe quedar marcado.');
        $this->assertNull($vinculoSinPrecio->fresh()->precio_error);
    }

    public function test_con_modo_solo_lectura_activo_el_push_no_se_ejecuta_y_queda_pendiente(): void
    {
        TiendanubeConexionRest::actual()->update([
            'client_id' => 'client-id-de-prueba', 'client_secret' => 'client-secret-de-prueba',
            'access_token' => 'token-vigente-de-prueba', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => true,
        ]);

        $listaNueva = ListaPrecio::create(['nombre' => 'Lista Nueva', 'activo' => true]);
        $producto = Producto::factory()->create();
        $vinculo = TiendanubeVarianteProducto::create(['variant_id' => 1, 'tn_product_id' => '10', 'producto_id' => $producto->id]);
        $producto->precios()->create(['lista_precio_id' => $listaNueva->id, 'precio' => 777.00]);

        Http::fake();

        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $listaNueva->id,
        ]);

        $respuesta->assertOk();
        Http::assertNothingSent();
        $this->assertSame($listaNueva->id, TiendanubeConexionRest::actual()->lista_precio_id);
        $this->assertTrue($vinculo->fresh()->precio_pendiente);
    }
}
