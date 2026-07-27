<?php

namespace Tests\Feature\Integraciones;

use App\Jobs\Integraciones\ImportarVentasTiendaNube;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Integracion;
use App\Models\IntegracionEvento;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Stock;
use App\Models\User;
use App\Models\Venta;
use App\Services\Integraciones\TiendaNube\ClienteTiendaNube;
use App\Services\Integraciones\TiendaNube\ClienteTiendaNubeFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** US4 — Importar ventas TiendaNube: materialización idempotente reutilizando el conversor (FR-021). */
class ImportarVentasTiendaNubeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    private Integracion $integracion;

    private Deposito $deposito;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);

        $lista = ListaPrecio::create(['nombre' => 'Lista de precios TiendaNube', 'activo' => true]);
        $this->deposito = Deposito::create(['nombre' => 'Depósito TiendaNube', 'activo' => true]);

        $this->integracion = Integracion::create([
            'canal' => 'tiendanube',
            'credenciales' => ['access_token' => 'x', 'refresh_token' => 'y', 'expires_at' => now()->addDays(30), 'cuenta_id' => '1'],
            'config' => ['lista_precio_id' => $lista->id, 'deposito_id' => $this->deposito->id],
            'estado' => 'conectado',
            'activo' => true,
        ]);

        $this->producto = Producto::factory()->create(['codigo' => 'SKU-VENTA-TN']);
        Stock::create(['producto_id' => $this->producto->id, 'variante_id' => null, 'deposito_id' => $this->deposito->id, 'cantidad' => 10]);
    }

    /** @return ClienteTiendaNubeFake */
    private function fakeTn()
    {
        return app(ClienteTiendaNube::class);
    }

    private function agregarVentaFake(string $idExterno, ?string $email = 'compradora@example.com'): void
    {
        $this->fakeTn()->agregarVenta([
            'id_externo' => $idExterno,
            'fecha' => Carbon::now()->toDateTimeString(),
            'comprador' => ['nombre' => 'Compradora TN', 'email' => $email],
            'lineas' => [
                ['sku' => 'SKU-VENTA-TN', 'id_externo_item' => 'ITEM-TN-1', 'descripcion' => 'Producto TN', 'cantidad' => 2, 'precio_unitario' => 100],
            ],
        ]);
    }

    public function test_importar_ventas_materializa_venta_con_cliente_nuevo_y_descuenta_stock_del_deposito_tn(): void
    {
        $this->agregarVentaFake('TN-VENTA-1');

        $respuesta = $this->post(route('integraciones.tn.importar-ventas'));
        $respuesta->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, Venta::count());
        $cliente = Cliente::where('email', 'compradora@example.com')->first();
        $this->assertNotNull($cliente);
        $this->assertSame($cliente->id, Venta::first()->cliente_id);

        $stock = Stock::where('producto_id', $this->producto->id)->where('deposito_id', $this->deposito->id)->first();
        $this->assertEquals(8.0, (float) $stock->cantidad);

        $evento = IntegracionEvento::where('tipo', ImportarVentasTiendaNube::TIPO_EVENTO)->where('id_externo', 'TN-VENTA-1')->first();
        $this->assertSame('procesado', $evento->estado);
    }

    public function test_importar_ventas_reutiliza_cliente_existente(): void
    {
        $clienteExistente = Cliente::factory()->create(['email' => 'compradora@example.com']);
        $this->agregarVentaFake('TN-VENTA-2');

        $this->post(route('integraciones.tn.importar-ventas'))->assertOk();

        $this->assertSame(1, Cliente::where('email', 'compradora@example.com')->count());
        $this->assertSame($clienteExistente->id, Venta::first()->cliente_id);
    }

    public function test_reimportar_no_duplica_la_venta(): void
    {
        $this->agregarVentaFake('TN-VENTA-3');

        $this->post(route('integraciones.tn.importar-ventas'))->assertOk();
        $this->assertSame(1, Venta::count());

        // Reimportar: la integración expone la misma venta (Fake no la quita de su lista);
        // el dedupe por id externo en integracion_eventos debe evitar una segunda venta.
        $this->integracion->update(['ultima_sync' => null]);
        $this->post(route('integraciones.tn.importar-ventas'))->assertOk();

        $this->assertSame(1, Venta::count());
        $this->assertSame(1, IntegracionEvento::where('tipo', ImportarVentasTiendaNube::TIPO_EVENTO)->where('id_externo', 'TN-VENTA-3')->count());
    }

    public function test_venta_con_item_sin_match_no_se_materializa_y_queda_registrada_con_error(): void
    {
        $this->fakeTn()->agregarVenta([
            'id_externo' => 'TN-VENTA-4',
            'fecha' => Carbon::now()->toDateTimeString(),
            'comprador' => ['nombre' => 'Otra Compradora'],
            'lineas' => [
                ['sku' => 'NO-EXISTE', 'id_externo_item' => 'ITEM-X', 'descripcion' => 'Sin match', 'cantidad' => 1, 'precio_unitario' => 50],
            ],
        ]);

        $this->post(route('integraciones.tn.importar-ventas'))->assertOk();

        $this->assertSame(0, Venta::count());
        $evento = IntegracionEvento::where('tipo', ImportarVentasTiendaNube::TIPO_EVENTO)->where('id_externo', 'TN-VENTA-4')->first();
        $this->assertSame('error', $evento->estado);
    }
}
