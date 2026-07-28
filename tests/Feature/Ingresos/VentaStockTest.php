<?php

namespace Tests\Feature\Ingresos;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use App\Models\FuncionAvanzada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regresión del cierre de brecha de stock de Ventas (spec 012, research.md §R1):
 * antes de esta spec las Ventas del CRM no generaban ningún movimiento de
 * stock. Cubre alta, edición, borrado, Servicios e ítems libres — quickstart.md
 * §Escenario 7.
 */
class VentaStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVentaConProducto(Producto $producto, float $cantidad = 3): Venta
    {
        $cliente = Cliente::factory()->create();

        $respuesta = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-27',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => 100,
                'iva_pct' => $producto->iva_venta_pct,
            ]],
        ]);

        $respuesta->assertCreated();

        return Venta::findOrFail($respuesta->json('venta.id'));
    }

    public function test_venta_manual_descuenta_stock_del_deposito_por_defecto(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $venta = $this->crearVentaConProducto($producto, 3);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'cantidad' => -3,
        ]);
        $this->assertDatabaseHas('movimientos_stock', [
            'producto_id' => $producto->id,
            'deposito_id' => $deposito->id,
            'tipo' => 'salida',
            'cantidad' => -3,
            'origen_type' => Venta::class,
            'origen_id' => $venta->id,
        ]);
    }

    public function test_editar_venta_reintegra_lo_anterior_y_aplica_lo_nuevo(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $venta = $this->crearVentaConProducto($producto, 3);

        $respuesta = $this->putJson(route('ventas.update', $venta), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $venta->cliente_id,
            'fecha_emision' => '2026-07-27',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => 5,
                'precio_unitario' => 100,
                'iva_pct' => $producto->iva_venta_pct,
            ]],
        ]);

        $respuesta->assertOk();

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'cantidad' => -5,
        ]);
    }

    public function test_eliminar_venta_reintegra_el_stock(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $venta = $this->crearVentaConProducto($producto, 3);

        $this->deleteJson(route('ventas.destroy', $venta))->assertOk();

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'cantidad' => 0,
        ]);
    }

    public function test_item_servicio_no_genera_movimiento_de_stock(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $servicio = Producto::factory()->create(['tipo' => 'servicio']);

        $this->crearVentaConProducto($servicio, 2);

        $this->assertDatabaseCount('movimientos_stock', 0);
        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_item_libre_sin_producto_no_genera_movimiento_de_stock(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $cliente = Cliente::factory()->create();

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => '2026-07-27',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => null,
                'descripcion' => 'Ítem libre sin producto',
                'cantidad' => 1,
                'precio_unitario' => 500,
            ]],
        ])->assertCreated();

        $this->assertDatabaseCount('movimientos_stock', 0);
    }

    /**
     * Regresión de spec 013 (T006/T007): el refactor de resolverDeposito() hacia
     * MercadoLibreConfiguracion::depositoEfectivo() no debe cambiar qué depósito
     * usa una Venta originada en Mercado Libre.
     */
    private function convertirOrdenMercadoLibre(Producto $producto): Venta
    {
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        if (! auth()->user()->roles()->where('roles.id', $admin->id)->exists()) {
            auth()->user()->roles()->attach($admin->id);
        }

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);
        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);

        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create([
            'ml_order_id' => '5000001', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => 1210.00, 'moneda' => 'ARS', 'comprador_ml_id' => '1', 'comprador_apodo' => 'COMPRADOR',
            'comprador_condicion_iva' => 'Consumidor Final', 'sincronizada_en' => now(),
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => 2, 'precio_unitario' => 605.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden->fresh(), auth()->id(), automatica: false);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        return $resultado['venta'];
    }

    public function test_venta_de_mercadolibre_descuenta_stock_del_deposito_configurado(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $depositoMl = Deposito::create(['nombre' => 'Depósito ML', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update(['deposito_id' => $depositoMl->id]);

        $this->convertirOrdenMercadoLibre($producto);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $depositoMl->id,
            'cantidad' => -2,
        ]);
    }

    public function test_venta_de_mercadolibre_usa_deposito_por_defecto_si_no_hay_configurado(): void
    {
        $depositoDefault = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $this->convertirOrdenMercadoLibre($producto);

        $this->assertDatabaseHas('stocks', [
            'producto_id' => $producto->id,
            'deposito_id' => $depositoDefault->id,
            'cantidad' => -2,
        ]);
    }
}
