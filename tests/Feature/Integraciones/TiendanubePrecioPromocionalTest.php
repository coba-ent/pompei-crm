<?php

namespace Tests\Feature\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
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

/**
 * Spec 102: el precio de una Lista de Precios promocional configurable se
 * publica en Tiendanube como `promotional_price`, en el mismo PUT que ya
 * manda `price`. Cada test que verifica que algo *se envía* tiene al lado
 * uno que verifica que lo que *no debe enviarse* no se envía — Tiendanube no
 * valida nada, así que la única red es la que arma este sincronizador.
 */
class TiendanubePrecioPromocionalTest extends TestCase
{
    use RefreshDatabase;

    protected ListaPrecio $listaNormal;

    protected ListaPrecio $listaPromocional;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);

        $this->listaNormal = ListaPrecio::create(['nombre' => 'Lista Normal', 'activo' => true]);
        $this->listaPromocional = ListaPrecio::create(['nombre' => 'Lista Promo', 'activo' => true]);

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'atk', 'store_id' => '999', 'estado' => EstadoConexion::Conectada,
            'modo_solo_lectura' => false, 'lista_precio_id' => $this->listaNormal->id,
        ]);

        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);
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

    /** T002/FR-000a/SC-000: sin lista promocional configurada, el PUT no lleva el campo. */
    public function test_sin_lista_promocional_configurada_el_campo_no_viaja(): void
    {
        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 1000]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->data()['price'] === 1000.0
                && ! array_key_exists('promotional_price', $request->data());
        });
    }

    /** T006: con precio en las dos listas, el PUT lleva price y promotional_price, ambos como string. */
    public function test_con_precio_en_las_dos_listas_el_put_lleva_los_dos_campos(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 15000]);
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaPromocional->id, 'precio' => 10000]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request['price'] === 15000.0
                && ($request->data()['promotional_price'] ?? null) === '10000';
        });
    }

    /** T005: sin precio promocional en el CRM, el PUT no lleva la clave — ni null ni "". */
    public function test_sin_precio_promocional_en_el_crm_la_clave_no_viaja(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 15000]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request['price'] === 15000.0
                && ! array_key_exists('promotional_price', $request->data());
        });
    }

    /** T007: precio promocional en cero se trata como ausente. */
    public function test_precio_promocional_en_cero_no_se_envia(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 15000]);
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaPromocional->id, 'precio' => 0]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && ! array_key_exists('promotional_price', $request->data());
        });
    }

    /** T009/FR-004: promocional mayor al precio de lista se rechaza, no se envía. */
    public function test_promocional_mayor_al_precio_no_se_envia_y_queda_el_error(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 10000]);
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaPromocional->id, 'precio' => 15000]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT' && ! array_key_exists('promotional_price', $request->data());
        });
        $this->assertNotNull($vinculo->fresh()->precio_error);
    }

    /** T010: promocional igual al precio de lista tampoco se envía. */
    public function test_promocional_igual_al_precio_no_se_envia(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 10000]);
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaPromocional->id, 'precio' => 10000]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT' && ! array_key_exists('promotional_price', $request->data());
        });
        $this->assertNotNull($vinculo->fresh()->precio_error);
    }

    /** T011/FR-005: cuando el promocional se rechaza, el precio de lista se envía igual. */
    public function test_promocional_rechazado_el_precio_de_lista_se_envia_igual(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 10000]);
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaPromocional->id, 'precio' => 15000]);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT' && $request['price'] === 10000.0;
        });
    }

    /** T012/FR-006: el mensaje del error nombra los dos importes. */
    public function test_el_mensaje_de_error_nombra_los_dos_importes(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 10000]);
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaPromocional->id, 'precio' => 15000]);

        $error = $vinculo->fresh()->precio_error;
        $this->assertStringContainsString('15.000', $error);
        $this->assertStringContainsString('10.000', $error);
    }

    /** T015/FR-007: editar la lista promocional dispara el envío del vínculo. */
    public function test_editar_la_lista_promocional_dispara_el_envio(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 15000]);

        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);

        $vinculo->producto->precios()->updateOrCreate(
            ['lista_precio_id' => $this->listaPromocional->id],
            ['precio' => 10000]
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT' && ($request['promotional_price'] ?? null) === '10000';
        });
    }

    /** T004a/FR-009b: editar la lista promocional no cambia el precio de venta publicado. */
    public function test_editar_la_lista_promocional_no_cambia_el_precio_de_venta(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 15000]);

        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);

        $vinculo->producto->precios()->updateOrCreate(
            ['lista_precio_id' => $this->listaPromocional->id],
            ['precio' => 10000]
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request['price'] === 15000.0
                && ($request->data()['promotional_price'] ?? null) === '10000';
        });
    }

    /** T016/FR-009: editar la lista normal sigue disparando el envío y arrastra el promocional vigente. */
    public function test_editar_la_lista_normal_arrastra_el_promocional_vigente(): void
    {
        TiendanubeConexionRest::actual()->update(['lista_precio_promocional_id' => $this->listaPromocional->id]);

        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 15000]);
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaPromocional->id, 'precio' => 10000]);

        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);

        $vinculo->producto->precios()->updateOrCreate(
            ['lista_precio_id' => $this->listaNormal->id],
            ['precio' => 16000]
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request['price'] === 16000.0
                && ($request->data()['promotional_price'] ?? null) === '10000';
        });
    }

    /** T003/FR-000b: la lista promocional no puede ser la misma que la normal. */
    public function test_la_lista_promocional_no_puede_ser_la_misma_que_la_normal(): void
    {
        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $this->listaNormal->id,
            'lista_precio_promocional_id' => $this->listaNormal->id,
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonValidationErrors('lista_precio_promocional_id');
    }

    /** T017/FR-009a: cambiar la lista promocional configurada empuja de inmediato los precios de la lista nueva. */
    public function test_cambiar_la_lista_promocional_configurada_empuja_los_precios(): void
    {
        $vinculo = $this->crearVinculo();
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaNormal->id, 'precio' => 15000]);
        $vinculo->producto->precios()->create(['lista_precio_id' => $this->listaPromocional->id, 'precio' => 10000]);

        Http::fake(['api.tiendanube.com/v1/*/products/*/variants/*' => Http::response(['id' => 1], 200)]);

        $respuesta = $this->patchJson(route('configuracion.tiendanube.ventas.configurar'), [
            'creacion_automatica' => false,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'lista_precio_id' => $this->listaNormal->id,
            'lista_precio_promocional_id' => $this->listaPromocional->id,
        ]);

        $respuesta->assertOk();
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request['price'] === 15000.0
                && ($request->data()['promotional_price'] ?? null) === '10000';
        });
    }
}
