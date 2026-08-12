<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Stock;
use App\Models\Venta;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 063 — US1: la cancelación de una orden ya facturada se hace visible.
 * FR-001 a FR-007. El caso más importante: que la detección NO modifique
 * importes, cobros ni stock (FR-003).
 */
class CancelacionPosteriorTest extends TestCase
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
    }

    private function crearOrdenConVenta(string $mlOrderId = '9001'): array
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create([
            'ml_order_id' => $mlOrderId, 'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.0, 'moneda' => 'ARS',
            'comprador_ml_id' => '999', 'comprador_apodo' => 'COMPRADOR', 'comprador_condicion_iva' => 'Consumidor Final',
            'sincronizada_en' => now(),
        ]);
        $orden->items()->create([
            'ml_item_id' => 'MLA1', 'titulo' => 'Producto', 'cantidad' => 1,
            'precio_unitario' => 1210.0, 'total_linea' => 1210.0, 'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? '');

        return [$orden->fresh(), $resultado['venta'], $producto];
    }

    private function ordenCruda(string $id, string $status = 'cancelled', array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'status' => $status,
            'date_created' => now()->toIso8601String(), 'date_closed' => now()->toIso8601String(),
            'total_amount' => 1210.0, 'currency_id' => 'ARS',
            'buyer' => ['id' => 999, 'nickname' => 'COMPRADOR'],
            'tags' => [$status === 'paid' ? 'paid' : 'cancelled'],
            'order_items' => [[
                'item' => ['id' => 'MLA1', 'title' => 'Producto', 'variation_id' => null],
                'quantity' => 1, 'unit_price' => 1210.0,
            ]],
        ], $extra);
    }

    private function fakearSoloCanceladas(array $canceladas): void
    {
        Http::fake(function ($request) use ($canceladas) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                return Http::response(['results' => $canceladas, 'paging' => ['total' => count($canceladas), 'offset' => 0, 'limit' => 50]], 200);
            }

            return Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200);
        });
    }

    /** FR-001/FR-002: se marca con el motivo correcto y la fecha de detección. */
    public function test_orden_cancelada_despues_de_convertida_se_marca_como_requiere_atencion(): void
    {
        [$orden] = $this->crearOrdenConVenta('9001');

        $this->fakearSoloCanceladas([$this->ordenCruda('9001')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertSame('orden_cancelada', $orden->motivo->value);
        $this->assertStringContainsString('Detectado el', $orden->motivo_detalle);
        $this->assertStringContainsString('cancelled', $orden->motivo_detalle);
    }

    /** FR-003: la marca NO modifica total, cobro ni stock — es sólo un aviso. */
    public function test_marcar_el_aviso_no_modifica_total_cobro_ni_stock(): void
    {
        [$orden, $venta, $producto] = $this->crearOrdenConVenta('9002');

        $totalAntes = (float) $venta->fresh()->total;
        $cobradoAntes = $venta->fresh()->cobrado();
        $stockAntes = (float) Stock::where('producto_id', $producto->id)->value('cantidad');

        $this->fakearSoloCanceladas([$this->ordenCruda('9002')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $ventaDespues = $venta->fresh();
        $this->assertSame($totalAntes, (float) $ventaDespues->total);
        $this->assertSame($cobradoAntes, $ventaDespues->cobrado());
        $this->assertSame($stockAntes, (float) Stock::where('producto_id', $producto->id)->value('cantidad'));
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'deleted_at' => null]);
    }

    /** FR-007: una orden cancelada que nunca se convirtió no genera aviso. */
    public function test_orden_cancelada_sin_venta_no_genera_aviso(): void
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->fakearSoloCanceladas([$this->ordenCruda('9003')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden = MercadoLibreOrden::where('ml_order_id', '9003')->firstOrFail();
        $this->assertNull($orden->venta_id);
        $this->assertSame('cancelada', $orden->estado_conversion->value);
        $this->assertNull($orden->motivo);
    }

    /** FR-005: re-sincronizar no duplica el aviso ni mueve la fecha de detección original. */
    public function test_resincronizar_no_duplica_ni_mueve_la_fecha_de_deteccion(): void
    {
        [$orden] = $this->crearOrdenConVenta('9004');

        $this->fakearSoloCanceladas([$this->ordenCruda('9004')]);
        app(SincronizadorOrdenes::class)->ejecutar();
        $detalleOriginal = $orden->fresh()->motivo_detalle;

        $this->travel(2)->hours();
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame($detalleOriginal, $orden->motivo_detalle);
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertDatabaseCount('ml_ordenes', 1);
    }

    /** FR-008a: una Venta marcada sigue pudiendo editarse y cobrarse — el aviso informa, no restringe. */
    public function test_venta_marcada_sigue_pudiendo_cobrarse_y_editarse(): void
    {
        [$orden, $venta] = $this->crearOrdenConVenta('9005');

        $this->fakearSoloCanceladas([$this->ordenCruda('9005')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertSame('requiere_atencion', $orden->fresh()->estado_conversion->value);

        // El aviso informa, no restringe (FR-008a): la Venta se ve y se opera igual que
        // cualquier otra. No hay ninguna comprobación nueva de estado_conversion/aviso en
        // VentaController — se verifica que la pantalla y el endpoint de cobranzas siguen
        // respondiendo con normalidad (la validación de saldo es la misma de siempre, ML ya
        // había registrado el cobro total al convertir).
        $this->get(route('ventas.show', $venta))->assertOk();

        $response = $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => CuentaTesoreria::first()->id,
            'monto' => 0.01,
            'fecha' => now()->toDateString(),
        ]);

        // 422 = validación normal de saldo (ya está cobrada al 100% por la conversión), no un
        // bloqueo agregado por esta feature — confirma que no hay ningún check nuevo del aviso.
        $this->assertSame(422, $response->status());
        $this->assertStringNotContainsString('atención', $response->json('mensaje') ?? '');
    }

    /** T016/FR-010a: el aviso se cierra solo cuando la Venta queda compensada por una nota de crédito. */
    public function test_el_aviso_se_cierra_solo_al_emitir_nota_de_credito_que_compensa_la_venta(): void
    {
        [$orden, $venta] = $this->crearOrdenConVenta('9006');

        $this->fakearSoloCanceladas([$this->ordenCruda('9006')]);
        app(SincronizadorOrdenes::class)->ejecutar();
        $this->assertSame('requiere_atencion', $orden->fresh()->estado_conversion->value);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito', 'afecta_stock' => false, 'descripcion' => 'Cancelación ML',
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => (float) $venta->fresh()->total,
        ])->assertCreated();

        $this->travel(2)->hours();
        app(SincronizadorOrdenes::class)->ejecutar();

        // La orden sigue cancelada en Mercado Libre (EvaluadorConvertibilidad manda a
        // `cancelada`), pero el aviso pendiente sobre la Venta se cierra: el motivo se limpia
        // porque `DetectorCancelaciones` ya no debe re-marcarla (FR-010a — está compensada).
        $orden->refresh();
        $this->assertSame('cancelada', $orden->estado_conversion->value);
        $this->assertNull($orden->motivo);
    }

    /** T016/FR-010a: el aviso se cierra solo cuando la Venta fue eliminada. */
    public function test_el_aviso_se_cierra_solo_al_eliminar_la_venta(): void
    {
        [$orden, $venta] = $this->crearOrdenConVenta('9007');

        $this->fakearSoloCanceladas([$this->ordenCruda('9007')]);
        app(SincronizadorOrdenes::class)->ejecutar();
        $this->assertSame('requiere_atencion', $orden->fresh()->estado_conversion->value);

        $venta->delete();

        $this->travel(2)->hours();
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame('cancelada', $orden->estado_conversion->value);
        $this->assertNull($orden->motivo);
    }

    /** T016/FR-010: descartar el aviso a mano deja la Venta vigente y limpia el motivo. */
    public function test_descartar_el_aviso_deja_la_venta_vigente(): void
    {
        [$orden, $venta] = $this->crearOrdenConVenta('9008');

        $this->fakearSoloCanceladas([$this->ordenCruda('9008')]);
        app(SincronizadorOrdenes::class)->ejecutar();
        $this->assertSame('requiere_atencion', $orden->fresh()->estado_conversion->value);

        $response = $this->postJson(route('ingresos.mercadolibre.descartarAviso', $orden));
        $response->assertOk();

        $orden->refresh();
        $this->assertSame('convertida', $orden->estado_conversion->value);
        $this->assertNull($orden->motivo);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'deleted_at' => null]);
    }
}
