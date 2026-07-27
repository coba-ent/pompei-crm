<?php

namespace Tests\Feature\Integraciones;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Integracion;
use App\Models\MlOrden;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Stock;
use App\Models\User;
use App\Models\Venta;
use App\Services\Integraciones\MercadoLibre\ClienteMercadoLibre;
use App\Services\Integraciones\MercadoLibre\ClienteMercadoLibreFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * US2 — Órdenes ML → ventas: conversión reutilizando el service Ventas
 * (stock afectado), idempotencia y bloqueo por ítem sin resolver (FR-011..014,
 * SC-003/SC-004).
 */
class ProcesarOrdenMlTest extends TestCase
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
            'canal' => 'mercadolibre',
            'credenciales' => ['access_token' => 'x', 'refresh_token' => 'y', 'expires_at' => now()->addHours(6), 'cuenta_id' => '1'],
            'estado' => 'conectado',
            'activo' => true,
        ]);
    }

    /** @return ClienteMercadoLibreFake */
    private function fakeMl()
    {
        return app(ClienteMercadoLibre::class);
    }

    private function importarYObtenerOrden(): MlOrden
    {
        $this->post(route('integraciones.ml.importar'))->assertOk();

        return MlOrden::where('integracion_id', $this->integracion->id)->firstOrFail();
    }

    public function test_procesar_orden_con_sku_matcheado_crea_venta_y_descuenta_stock(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'SKU-100']);
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        Stock::create(['producto_id' => $producto->id, 'variante_id' => null, 'deposito_id' => $deposito->id, 'cantidad' => 10]);

        $this->fakeMl()->agregarOrden([
            'id_externo' => 'ML-1',
            'fecha' => Carbon::now()->toDateTimeString(),
            'comprador' => ['nombre' => 'Juan Pérez', 'apodo_ml' => 'juanp', 'email' => 'juan@example.com'],
            'lineas' => [
                ['sku' => 'SKU-100', 'id_externo_item' => 'ITEM-1', 'descripcion' => 'Producto de prueba', 'cantidad' => 2, 'precio_unitario' => 500],
            ],
            'totales' => ['total' => 1000],
        ]);

        $orden = $this->importarYObtenerOrden();

        $form = $this->getJson(route('integraciones.ml.procesar.form', $orden));
        $form->assertOk();
        $this->assertSame('match', $form->json('lineas.0.estado_resolucion'));
        $this->assertSame($producto->id, $form->json('lineas.0.producto_id'));
        $this->assertNull($form->json('cliente_sugerido'));

        $respuesta = $this->postJson(route('integraciones.ml.procesar', $orden), [
            'cliente_id' => null,
            'lineas' => [['producto_id' => $producto->id, 'variante_id' => null]],
        ]);

        $respuesta->assertOk()->assertJson(['ok' => true]);

        $orden->refresh();
        $this->assertSame('procesada', $orden->estado);
        $this->assertNotNull($orden->venta_id);
        $this->assertSame(1, Venta::count());

        $cliente = Cliente::where('apodo_ml', 'juanp')->first();
        $this->assertNotNull($cliente);
        $this->assertSame($cliente->id, $orden->cliente_id);

        $stock = Stock::where('producto_id', $producto->id)->where('deposito_id', $deposito->id)->first();
        $this->assertEquals(8.0, (float) $stock->cantidad);
    }

    public function test_procesar_orden_con_cliente_existente_no_lo_duplica(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'SKU-200']);
        $clienteExistente = Cliente::factory()->create(['apodo_ml' => 'juanp']);

        $this->fakeMl()->agregarOrden([
            'id_externo' => 'ML-2',
            'fecha' => Carbon::now()->toDateTimeString(),
            'comprador' => ['nombre' => 'Juan Pérez', 'apodo_ml' => 'juanp'],
            'lineas' => [
                ['sku' => 'SKU-200', 'id_externo_item' => 'ITEM-2', 'descripcion' => 'Producto B', 'cantidad' => 1, 'precio_unitario' => 100],
            ],
        ]);

        $orden = $this->importarYObtenerOrden();

        $form = $this->getJson(route('integraciones.ml.procesar.form', $orden));
        $this->assertSame($clienteExistente->id, $form->json('cliente_sugerido.id'));

        $this->postJson(route('integraciones.ml.procesar', $orden), [
            'lineas' => [['producto_id' => $producto->id, 'variante_id' => null]],
        ])->assertOk();

        $this->assertSame(1, Cliente::where('apodo_ml', 'juanp')->count());
    }

    public function test_reimportar_y_reprocesar_no_duplica(): void
    {
        $producto = Producto::factory()->create(['codigo' => 'SKU-300']);

        $this->fakeMl()->agregarOrden([
            'id_externo' => 'ML-3',
            'fecha' => Carbon::now()->toDateTimeString(),
            'comprador' => ['nombre' => 'Cliente Canal'],
            'lineas' => [
                ['sku' => 'SKU-300', 'id_externo_item' => 'ITEM-3', 'descripcion' => 'Producto C', 'cantidad' => 1, 'precio_unitario' => 50],
            ],
        ]);

        $orden = $this->importarYObtenerOrden();
        $this->postJson(route('integraciones.ml.procesar', $orden), [
            'lineas' => [['producto_id' => $producto->id, 'variante_id' => null]],
        ])->assertOk();

        // Reimportar: la misma orden ML no debe duplicarse en staging.
        $this->post(route('integraciones.ml.importar'))->assertOk();
        $this->assertSame(1, MlOrden::where('integracion_id', $this->integracion->id)->count());

        // Reprocesar: la orden ya no está pendiente.
        $respuesta = $this->postJson(route('integraciones.ml.procesar', $orden), [
            'lineas' => [['producto_id' => $producto->id, 'variante_id' => null]],
        ]);
        $respuesta->assertStatus(422);
        $this->assertSame(1, Venta::count());
    }

    public function test_item_sin_resolver_bloquea_la_confirmacion(): void
    {
        $this->fakeMl()->agregarOrden([
            'id_externo' => 'ML-4',
            'fecha' => Carbon::now()->toDateTimeString(),
            'comprador' => ['nombre' => 'Cliente Canal'],
            'lineas' => [
                ['sku' => 'NO-EXISTE', 'id_externo_item' => 'ITEM-4', 'descripcion' => 'Producto D', 'cantidad' => 1, 'precio_unitario' => 50],
            ],
        ]);

        $orden = $this->importarYObtenerOrden();

        $form = $this->getJson(route('integraciones.ml.procesar.form', $orden));
        $this->assertSame('sin_match', $form->json('lineas.0.estado_resolucion'));

        $respuesta = $this->postJson(route('integraciones.ml.procesar', $orden), [
            'lineas' => [['producto_id' => null, 'variante_id' => null]],
        ]);

        $respuesta->assertStatus(422);
        $orden->refresh();
        $this->assertSame('pendiente', $orden->estado);
        $this->assertSame(0, Venta::count());
    }
}
