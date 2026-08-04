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
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 041, US2: red de seguridad on-view — `datatable()` de pendientes ML
 * corrige antes de listar una orden que quedó `requiere_atencion`
 * desincronizada porque su vinculación se creó por fuera del Observer
 * evento-driven (ej. insert directo).
 */
class OrdenesPendientesDatatableReevaluaTest extends TestCase
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

    public function test_abrir_el_listado_de_pendientes_corrige_una_orden_desincronizada(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $orden = MercadoLibreOrden::create([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'requiere_atencion',
            'motivo' => 'publicacion_sin_vincular',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'sincronizada_en' => now(),
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00,
        ]);

        // Vinculación por fuera del flujo normal, sin disparar el Observer (`withoutEvents`):
        // simula el caso reportado en producción donde la orden quedó `requiere_atencion`
        // desactualizada porque su vinculación no pasó por el mecanismo evento-driven.
        MercadoLibrePublicacionProducto::withoutEvents(function () use ($producto) {
            MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
        });

        $this->assertSame('requiere_atencion', $orden->fresh()->estado_conversion->value);

        $this->getJson(route('ingresos.mercadolibre.datatable'))->assertOk();

        $this->assertSame('lista', $orden->fresh()->estado_conversion->value);
    }
}
