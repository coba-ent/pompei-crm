<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\FuncionAvanzada;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use App\Services\Stock\StockService;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * US1 (spec 013, FR-001/FR-005) y US2 (FR-002): qué movimientos marcan un
 * vínculo como pendiente de sincronizar hacia Mercado Libre, y cuáles no.
 */
class MovimientoStockObserverTest extends TestCase
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

        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $respuesta = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => '2026-07-28',
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

    public function test_venta_manual_sobre_producto_vinculado_marca_pendiente(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo->fresh()->stock_pendiente);
    }

    public function test_movimiento_en_otro_deposito_no_marca_pendiente(): void
    {
        $depositoDefault = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $depositoMl = Deposito::create(['nombre' => 'Depósito ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['deposito_id' => $depositoMl->id]);

        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        // Venta manual usa siempre el depósito por defecto del CRM (primero activo por id),
        // que acá es $depositoDefault — distinto del configurado para Mercado Libre.
        $this->crearVentaConProducto($producto, 2);

        $this->assertFalse($vinculo->fresh()->stock_pendiente);
    }

    public function test_producto_sin_vinculo_no_marca_nada(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $this->crearVentaConProducto($producto, 1);

        $this->assertDatabaseCount('ml_publicacion_producto', 0);
    }

    public function test_ajuste_manual_de_stock_tambien_marca_pendiente(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        app(StockService::class)->ajustar($producto, null, $deposito, 5, 'ajuste de prueba');

        $this->assertTrue($vinculo->fresh()->stock_pendiente);
    }

    /** Spec 036 US2 (FR-005): un producto con 2 publicaciones vinculadas marca AMBAS pendientes. */
    public function test_producto_con_dos_publicaciones_vinculadas_marca_ambas_pendientes(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo1 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
        $vinculo2 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA2', 'producto_id' => $producto->id]);

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo1->fresh()->stock_pendiente);
        $this->assertTrue($vinculo2->fresh()->stock_pendiente);
    }

    /** Spec 036 US2 (FR-009): desvincular una publicación no afecta a las demás vinculadas al mismo producto. */
    public function test_desvincular_una_publicacion_no_afecta_a_las_demas(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo1 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
        $vinculo2 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA2', 'producto_id' => $producto->id]);
        $vinculo1->delete();

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo2->fresh()->stock_pendiente);
        $this->assertDatabaseMissing('ml_publicacion_producto', ['id' => $vinculo1->id]);
    }

    /** ---- US2: exclusión de bucle (FR-002) ---- */

    private function convertirOrdenMercadoLibre(Producto $producto, float $cantidad = 1): Venta
    {
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
        CuentaTesoreria::firstOrCreate(['nombre' => 'Mercado Pago'], ['tipo' => 'banco', 'visible' => true]);
        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);

        MercadoLibrePublicacionProducto::firstOrCreate(
            ['ml_item_id' => 'MLA1'],
            ['producto_id' => $producto->id]
        );

        $precioUnitario = 605.00;
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => (string) random_int(1000000, 9999999), 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => $precioUnitario * $cantidad, 'moneda' => 'ARS', 'comprador_ml_id' => '1', 'comprador_apodo' => 'COMPRADOR',
            'comprador_condicion_iva' => 'Consumidor Final', 'sincronizada_en' => now(),
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => $cantidad, 'precio_unitario' => $precioUnitario, 'total_linea' => $precioUnitario * $cantidad,
            'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden->fresh(), auth()->id(), automatica: false);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        return $resultado['venta'];
    }

    public function test_convertir_orden_de_mercadolibre_no_marca_pendiente_el_vinculo(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $this->convertirOrdenMercadoLibre($producto, 2);

        $vinculo = MercadoLibrePublicacionProducto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertFalse($vinculo->stock_pendiente);
    }

    public function test_venta_manual_sobre_mismo_producto_si_marca_pendiente_tras_una_orden_ml(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $this->convertirOrdenMercadoLibre($producto, 2);

        $vinculo = MercadoLibrePublicacionProducto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertFalse($vinculo->fresh()->stock_pendiente, 'La orden de Mercado Libre no debe marcar pendiente.');

        $this->crearVentaConProducto($producto, 1);

        $this->assertTrue($vinculo->fresh()->stock_pendiente, 'La Venta manual sí debe marcar pendiente.');
    }
}
