<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 041, US1 (MVP): vincular/editar/eliminar una vinculación ML destraba
 * (o vuelve a trabar) sus órdenes pendientes sin acción adicional del
 * usuario, vía `MercadoLibrePublicacionProductoObserver`.
 */
class VinculacionReevaluaOrdenesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => 'conectada', 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);
    }

    private function crearOrdenConItem(string $mlItemId, array $overridesOrden = []): MercadoLibreOrden
    {
        $orden = MercadoLibreOrden::create(array_replace([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'requiere_atencion',
            'motivo' => 'publicacion_sin_vincular',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'sincronizada_en' => now(),
        ], $overridesOrden));

        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => $mlItemId, 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00,
        ]);

        return $orden;
    }

    public function test_crear_la_vinculacion_deja_lista_la_orden_pendiente(): void
    {
        $orden = $this->crearOrdenConItem('MLA1');
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->assertSame('lista', $orden->fresh()->estado_conversion->value);
    }

    public function test_crear_la_vinculacion_con_creacion_automatica_convierte_la_orden(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => true]);

        $orden = $this->crearOrdenConItem('MLA1');
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->assertSame('convertida', $orden->fresh()->estado_conversion->value);
        $this->assertNotNull($orden->fresh()->venta_id);
    }

    public function test_editar_una_vinculacion_existente_reevalua_la_orden(): void
    {
        $productoViejo = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => false]);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $productoViejo->id]);
        $orden = $this->crearOrdenConItem('MLA1', ['motivo' => 'producto_inexistente']);

        $productoNuevo = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $vinculo->update(['producto_id' => $productoNuevo->id]);

        $this->assertSame('lista', $orden->fresh()->estado_conversion->value);
    }

    public function test_eliminar_una_vinculacion_vuelve_a_requiere_atencion_una_orden_que_estaba_lista(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
        $orden = $this->crearOrdenConItem('MLA1', ['estado_conversion' => 'lista', 'motivo' => null]);

        $vinculo->delete();

        $orden->refresh();
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertSame('publicacion_sin_vincular', $orden->motivo->value);
    }

    public function test_una_orden_con_venta_id_seteado_no_se_toca(): void
    {
        $venta = Venta::factory()->create();
        $orden = $this->crearOrdenConItem('MLA1', [
            'venta_id' => $venta->id, 'estado_conversion' => 'convertida', 'motivo' => null,
        ]);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->assertSame('convertida', $orden->fresh()->estado_conversion->value);
        $this->assertSame($venta->id, $orden->fresh()->venta_id);
    }

    public function test_una_orden_de_otro_item_no_relacionado_no_se_toca(): void
    {
        $orden = $this->crearOrdenConItem('MLA2');

        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->assertSame('requiere_atencion', $orden->fresh()->estado_conversion->value);
        $this->assertSame('publicacion_sin_vincular', $orden->fresh()->motivo->value);
    }
}
