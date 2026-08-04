<?php

namespace Tests\Feature\Tiendanube;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeOrden;
use App\Models\Integraciones\TiendanubeOrdenItem;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 041, US2: mismo caso que
 * `Tests\Feature\MercadoLibre\OrdenesPendientesDatatableReevaluaTest`
 * mapeado al canal TiendaNube (T013).
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
        FuncionAvanzada::where('clave', 'tiendanube')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        TiendanubeConexionRest::actual()->update([
            'access_token' => 'token-vigente', 'estado' => EstadoConexion::Conectada,
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $cuentaTesoreria = CuentaTesoreria::create(['nombre' => 'Pago Nube', 'tipo' => 'banco', 'visible' => true]);
        TiendanubeConexionRest::actual()->update(['cuenta_tesoreria_id' => $cuentaTesoreria->id]);
    }

    public function test_abrir_el_listado_de_pendientes_corrige_una_orden_desincronizada(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $orden = TiendanubeOrden::create([
            'tn_order_id' => random_int(100000, 999999),
            'status' => 'closed', 'payment_status' => 'paid', 'estado_conversion' => 'requiere_atencion',
            'motivo' => 'variante_sin_vincular',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'tn_customer_id' => random_int(1, 999999), 'comprador_email' => 'comprador'.random_int(1, 999999).'@test.com',
            'comprador_nombre' => 'Comprador Test', 'billing_document_number' => null,
            'sincronizada_en' => now(),
        ]);
        TiendanubeOrdenItem::create([
            'tn_orden_id' => $orden->id, 'tn_product_id' => 10, 'variant_id' => 1, 'nombre_producto' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00,
        ]);

        // Vinculación por fuera del flujo normal, sin disparar el Observer (`withoutEvents`):
        // simula el caso reportado en producción donde la orden quedó `requiere_atencion`
        // desactualizada porque su vinculación no pasó por el mecanismo evento-driven.
        TiendanubeVarianteProducto::withoutEvents(function () use ($producto) {
            TiendanubeVarianteProducto::create(['variant_id' => 1, 'producto_id' => $producto->id]);
        });

        $this->assertSame('requiere_atencion', $orden->fresh()->estado_conversion->value);

        $this->getJson(route('ingresos.tiendanube.datatable'))->assertOk();

        $this->assertSame('lista', $orden->fresh()->estado_conversion->value);
    }
}
