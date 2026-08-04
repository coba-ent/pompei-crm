<?php

namespace Tests\Unit\Services\MercadoLibre;

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
use App\Services\MercadoLibre\ReevaluadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 041, Foundational: `ReevaluadorOrdenes` (ML) debe reproducir el mismo
 * comportamiento que hoy vive inline en `SincronizadorOrdenes` — no-op sobre
 * órdenes convertidas (FR-005), transición correcta de estado/motivo (FR-011)
 * y creación automática (o su fallo controlado) al quedar `lista` (FR-004).
 *
 * `MercadoLibrePublicacionProducto::create()` se crea siempre con
 * `withoutEvents()`: este archivo prueba `reevaluarUna()`/
 * `reevaluarAfectadasPorPublicacion()` de forma directa y aislada — el
 * comportamiento del `MercadoLibrePublicacionProductoObserver` (que también
 * llama a estos mismos métodos) se prueba aparte en
 * `tests/Feature/MercadoLibre/VinculacionReevaluaOrdenesTest`. Sin
 * `withoutEvents()`, el propio Observer dispararía una reevaluación (y
 * eventualmente una conversión) como efecto colateral antes de que el test
 * invoque el método bajo prueba explícitamente.
 */
class ReevaluadorOrdenesTest extends TestCase
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

    private function crearOrdenConItem(string $mlItemId, array $overridesOrden = [], array $overridesItem = []): MercadoLibreOrden
    {
        $orden = MercadoLibreOrden::create(array_replace([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'requiere_atencion',
            'motivo' => 'publicacion_sin_vincular',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'sincronizada_en' => now(),
        ], $overridesOrden));

        MercadoLibreOrdenItem::create(array_replace([
            'ml_orden_id' => $orden->id, 'ml_item_id' => $mlItemId, 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00,
        ], $overridesItem));

        return $orden->fresh(['items']);
    }

    private function ventaId(): int
    {
        return Venta::factory()->create()->id;
    }

    private function crearVinculo(string $mlItemId, int $productoId): MercadoLibrePublicacionProducto
    {
        return MercadoLibrePublicacionProducto::withoutEvents(
            fn () => MercadoLibrePublicacionProducto::create(['ml_item_id' => $mlItemId, 'producto_id' => $productoId])
        );
    }

    public function test_no_op_si_la_orden_ya_tiene_venta_id(): void
    {
        $orden = $this->crearOrdenConItem('MLA1', ['venta_id' => $this->ventaId(), 'estado_conversion' => 'convertida', 'motivo' => null]);
        $estadoPrevio = $orden->estado_conversion->value;

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $this->assertSame($estadoPrevio, $orden->fresh()->estado_conversion->value);
    }

    public function test_pasa_a_lista_y_limpia_motivo_cuando_se_vincula_la_publicacion(): void
    {
        $orden = $this->crearOrdenConItem('MLA1');
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo('MLA1', $producto->id);

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $orden->refresh();
        $this->assertSame('lista', $orden->estado_conversion->value);
        $this->assertNull($orden->motivo);
        $this->assertNull($orden->motivo_detalle);
    }

    public function test_dispara_creacion_automatica_cuando_queda_lista_y_la_config_lo_permite(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => true]);

        $orden = $this->crearOrdenConItem('MLA1');
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo('MLA1', $producto->id);

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $orden->refresh();
        $this->assertSame('convertida', $orden->estado_conversion->value, $orden->motivo_detalle ?? 'sin detalle');
        $this->assertNotNull($orden->venta_id);
    }

    public function test_no_dispara_creacion_automatica_si_la_config_esta_apagada(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => false]);

        $orden = $this->crearOrdenConItem('MLA1');
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo('MLA1', $producto->id);

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $orden->refresh();
        $this->assertSame('lista', $orden->estado_conversion->value);
        $this->assertNull($orden->venta_id);
    }

    public function test_error_en_conversion_deja_la_orden_requiere_atencion_con_detalle_sin_relanzar(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => true]);

        // Sin depósito para que ConversorOrdenAVenta falle de forma controlada: se
        // fuerza el error borrando el único depósito creado en setUp.
        Deposito::query()->delete();

        $orden = $this->crearOrdenConItem('MLA1');
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo('MLA1', $producto->id);

        app(ReevaluadorOrdenes::class)->reevaluarUna($orden);

        $orden->refresh();
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertSame('error_conversion', $orden->motivo->value);
        $this->assertNotNull($orden->motivo_detalle);
        $this->assertNull($orden->venta_id);
    }

    public function test_reevaluar_afectadas_por_publicacion_solo_trae_ordenes_del_item_correcto_no_convertidas(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        $this->crearVinculo('MLA1', $producto->id);

        $afectada = $this->crearOrdenConItem('MLA1');
        $otroItem = $this->crearOrdenConItem('MLA2');
        $yaConvertida = $this->crearOrdenConItem('MLA1', ['venta_id' => $this->ventaId(), 'estado_conversion' => 'convertida', 'motivo' => null]);
        $cancelada = $this->crearOrdenConItem('MLA1', ['estado_conversion' => 'cancelada', 'estado_orden' => 'cancelada', 'motivo' => null]);
        $pendientePago = $this->crearOrdenConItem('MLA1', ['estado_conversion' => 'pendiente_pago', 'estado_orden' => 'pendiente', 'motivo' => null]);

        $cantidad = app(ReevaluadorOrdenes::class)->reevaluarAfectadasPorPublicacion('MLA1');

        $this->assertSame(1, $cantidad);
        $this->assertSame('lista', $afectada->fresh()->estado_conversion->value);
        $this->assertSame('requiere_atencion', $otroItem->fresh()->estado_conversion->value);
        $this->assertSame('convertida', $yaConvertida->fresh()->estado_conversion->value);
        $this->assertSame('cancelada', $cancelada->fresh()->estado_conversion->value);
        $this->assertSame('pendiente_pago', $pendientePago->fresh()->estado_conversion->value);
    }
}
