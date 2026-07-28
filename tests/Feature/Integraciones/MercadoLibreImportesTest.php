<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
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
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T063 — el total de la Venta coincide EXACTAMENTE con el monto de la orden
 * (FR-030, FR-030a, FR-030b, SC-003), incluidos IVA 21%, 10,5%, Exento y
 * casos con redondeo.
 */
class MercadoLibreImportesTest extends TestCase
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
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);
    }

    /** @return array{orden: MercadoLibreOrden, producto: Producto} */
    private function crearOrdenPagada(string $ivaPct, float $total, float $cantidad = 1, ?string $condicionIva = 'Consumidor Final'): array
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => $ivaPct, 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => $total, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999), 'comprador_apodo' => 'COMPRADOR',
            'comprador_condicion_iva' => $condicionIva,
            'sincronizada_en' => now(),
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => $cantidad, 'precio_unitario' => round($total / $cantidad, 2), 'total_linea' => $total,
            'producto_id' => $producto->id,
        ]);

        return ['orden' => $orden, 'producto' => $producto];
    }

    public function test_total_coincide_exactamente_con_iva_21(): void
    {
        ['orden' => $orden] = $this->crearOrdenPagada('21', 1210.00);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame(1210.00, (float) $resultado['venta']->total);
    }

    public function test_total_coincide_exactamente_con_iva_10_5(): void
    {
        ['orden' => $orden] = $this->crearOrdenPagada('10.5', 552.50);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame(552.50, (float) $resultado['venta']->total);
    }

    public function test_total_coincide_exactamente_con_exento(): void
    {
        ['orden' => $orden] = $this->crearOrdenPagada('exento', 900.00);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame(900.00, (float) $resultado['venta']->total);
    }

    public function test_total_coincide_exactamente_con_cantidades_que_generan_redondeo(): void
    {
        // 3 unidades a 333.33 = 999.99, un caso típico de redondeo con IVA 21%.
        ['orden' => $orden] = $this->crearOrdenPagada('21', 999.99, cantidad: 3);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');
        $this->assertSame(999.99, (float) $resultado['venta']->total);
    }
}
