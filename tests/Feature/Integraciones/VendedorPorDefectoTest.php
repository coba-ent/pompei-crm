<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion as EstadoConexionMercadoLibre;
use App\Enums\Tiendanube\EstadoConexion as EstadoConexionTiendanube;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\TiendanubeConfiguracion;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Vendedor;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vendedor por defecto de Tiendanube/MercadoLibre (spec 020, FR-010/FR-011, SC-004):
 * asignación a la Venta automática cuando está configurado, vacío cuando no, e
 * independencia entre integraciones (cambiar el de una no afecta a la otra).
 */
class VendedorPorDefectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        TiendanubeConfiguracion::actual()->update([
            'access_token' => 'token-vigente', 'estado' => EstadoConexionTiendanube::Conectada,
            'cuenta_tesoreria_id' => CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true])->id,
        ]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexionMercadoLibre::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);
    }

    private function crearOrdenTiendanube(): TiendanubeOrden
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        TiendanubeVarianteProducto::firstOrCreate(['variant_id' => 1], ['producto_id' => $producto->id]);

        $orden = TiendanubeOrden::create([
            'tn_order_id' => random_int(100000, 999999),
            'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'tn_customer_id' => random_int(1, 999999), 'comprador_email' => 'comprador'.random_int(1, 999999).'@test.com',
            'comprador_nombre' => 'Comprador Test', 'sincronizada_en' => now(),
        ]);

        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => 1, 'nombre_producto' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        return $orden;
    }

    private function crearOrdenMercadoLibre(): MercadoLibreOrden
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::firstOrCreate(['ml_item_id' => 'MLA1'], ['producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'sincronizada_en' => now(),
        ]);

        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        return $orden;
    }

    public function test_tiendanube_asigna_el_vendedor_por_defecto_configurado(): void
    {
        $vendedor = Vendedor::create(['nombre' => 'Vendedor Tiendanube']);
        TiendanubeConfiguracion::actual()->update(['vendedor_id' => $vendedor->id]);

        $orden = $this->crearOrdenTiendanube();
        $resultado = app(\App\Services\Tiendanube\ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame($vendedor->id, $resultado['venta']->fresh()->vendedor_id);
    }

    public function test_tiendanube_sin_vendedor_por_defecto_crea_la_venta_sin_vendedor(): void
    {
        $orden = $this->crearOrdenTiendanube();
        $resultado = app(\App\Services\Tiendanube\ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertNull($resultado['venta']->fresh()->vendedor_id);
    }

    public function test_mercadolibre_asigna_el_vendedor_por_defecto_configurado(): void
    {
        $vendedor = Vendedor::create(['nombre' => 'Vendedor MercadoLibre']);
        MercadoLibreConfiguracion::actual()->update(['vendedor_id' => $vendedor->id]);

        $orden = $this->crearOrdenMercadoLibre();
        $resultado = app(\App\Services\MercadoLibre\ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame($vendedor->id, $resultado['venta']->fresh()->vendedor_id);
    }

    public function test_cambiar_el_default_de_una_integracion_no_afecta_a_la_otra(): void
    {
        $vendedorTn = Vendedor::create(['nombre' => 'Sólo Tiendanube']);
        TiendanubeConfiguracion::actual()->update(['vendedor_id' => $vendedorTn->id]);

        $ordenMl = $this->crearOrdenMercadoLibre();
        $resultadoMl = app(\App\Services\MercadoLibre\ConversorOrdenAVenta::class)->convertir($ordenMl, null, automatica: true);

        $this->assertTrue($resultadoMl['ok'], $resultadoMl['mensaje'] ?? '');
        $this->assertNull($resultadoMl['venta']->fresh()->vendedor_id);

        $ordenTn = $this->crearOrdenTiendanube();
        $resultadoTn = app(\App\Services\Tiendanube\ConversorOrdenAVenta::class)->convertir($ordenTn, null, automatica: true);

        $this->assertTrue($resultadoTn['ok'], $resultadoTn['mensaje'] ?? '');
        $this->assertSame($vendedorTn->id, $resultadoTn['venta']->fresh()->vendedor_id);
    }
}
