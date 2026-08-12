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
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 063 — US3: reembolso parcial y mediación se distinguen de una cancelación firme.
 * FR-004, FR-004a. T017-T020.
 */
class CancelacionesMotivosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `Http::fake(Closure)` APILA los callbacks en vez de reemplazarlos (el primero que
     * responde algo distinto de null gana para siempre) — así que para simular una segunda
     * sincronización con datos distintos dentro del mismo test hay que mutar este estado en
     * vez de volver a llamar a `Http::fake()`. Se registra un único closure, una sola vez,
     * en `setUp()`.
     */
    private array $ordenesNormales = [];

    private array $ordenesCanceladas = [];

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

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                $ordenes = $this->ordenesCanceladas;

                return Http::response(['results' => $ordenes, 'paging' => ['total' => count($ordenes), 'offset' => 0, 'limit' => 50]], 200);
            }

            $ordenes = $this->ordenesNormales;

            return Http::response(['results' => $ordenes, 'paging' => ['total' => count($ordenes), 'offset' => 0, 'limit' => 50]], 200);
        });
    }

    private function crearOrdenConVenta(string $mlOrderId): MercadoLibreOrden
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

        return $orden->fresh();
    }

    private function ordenCruda(string $id, string $status, array $payments = []): array
    {
        return [
            'id' => $id, 'status' => $status,
            'date_created' => now()->toIso8601String(), 'date_closed' => now()->toIso8601String(),
            'total_amount' => 1210.0, 'currency_id' => 'ARS',
            'buyer' => ['id' => 999, 'nickname' => 'COMPRADOR'],
            'tags' => [$status === 'paid' ? 'paid' : 'cancelled'],
            'payments' => $payments,
            'order_items' => [[
                'item' => ['id' => 'MLA1', 'title' => 'Producto', 'variation_id' => null],
                'quantity' => 1, 'unit_price' => 1210.0,
            ]],
        ];
    }

    /**
     * `partially_refunded`/`in_mediation` no vienen en la búsqueda dedicada a canceladas de la
     * API real (sólo `cancelled`/`pending_cancel`), así que se simulan por la pasada "normal".
     */
    private function fakearComoOrdenesNormales(array $ordenes): void
    {
        $this->ordenesNormales = $ordenes;
    }

    /** FR-004/FR-004a: reembolso parcial es un motivo propio, distinto de cancelación, con el importe. */
    public function test_reembolso_parcial_genera_su_propio_motivo_con_el_importe(): void
    {
        $orden = $this->crearOrdenConVenta('8001');

        $this->fakearComoOrdenesNormales([
            $this->ordenCruda('8001', 'partially_refunded', [
                ['status' => 'refunded', 'transaction_amount_refunded' => 300.0],
            ]),
        ]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertSame('orden_reembolso_parcial', $orden->motivo->value);
        $this->assertStringContainsString('300', $orden->motivo_detalle);
    }

    /** FR-004a: si el marketplace no informa el importe reembolsado, el aviso se muestra igual. */
    public function test_reembolso_parcial_sin_importe_informado_deja_constancia_explicita(): void
    {
        $orden = $this->crearOrdenConVenta('8002');

        $this->fakearComoOrdenesNormales([$this->ordenCruda('8002', 'partially_refunded')]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame('orden_reembolso_parcial', $orden->motivo->value);
        $this->assertStringContainsString('no informado', $orden->motivo_detalle);
    }

    /** FR-004: la mediación se lee del pago, no del estado de la orden, y produce su propio motivo. */
    public function test_orden_pagada_con_pago_en_mediacion_genera_su_propio_motivo(): void
    {
        $orden = $this->crearOrdenConVenta('8003');

        $this->fakearComoOrdenesNormales([
            $this->ordenCruda('8003', 'paid', [['status' => 'in_mediation']]),
        ]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame('requiere_atencion', $orden->estado_conversion->value);
        $this->assertSame('orden_en_mediacion', $orden->motivo->value);
    }

    /** US3 escenario 3: la mediación que se resuelve como cancelación conserva la fecha de detección original. */
    public function test_mediacion_que_se_resuelve_como_cancelacion_conserva_la_fecha_de_deteccion(): void
    {
        $orden = $this->crearOrdenConVenta('8004');

        $this->fakearComoOrdenesNormales([
            $this->ordenCruda('8004', 'paid', [['status' => 'in_mediation']]),
        ]);
        app(SincronizadorOrdenes::class)->ejecutar();
        $orden->refresh();
        $this->assertSame('orden_en_mediacion', $orden->motivo->value);
        preg_match('/Detectado el (.+)\./', $orden->motivo_detalle, $m);
        $fechaOriginal = $m[1];

        $this->travel(30)->minutes();
        // La orden ahora pasa a estar cancelada en firme: la búsqueda dedicada a canceladas SÍ
        // la trae (a diferencia de la mediación/reembolso parcial, que sólo viajan por la pasada
        // normal) — se limpia también `$this->ordenesNormales` para no duplicarla en la otra pasada.
        $this->ordenesNormales = [];
        $this->ordenesCanceladas = [$this->ordenCruda('8004', 'cancelled')];
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame('orden_cancelada', $orden->motivo->value);
        $this->assertStringContainsString("Detectado el {$fechaOriginal}.", $orden->motivo_detalle);
    }

    /** US3 escenario 4: la mediación resuelta a favor del negocio cierra el aviso automáticamente. */
    public function test_mediacion_resuelta_a_favor_cierra_el_aviso_automaticamente(): void
    {
        $orden = $this->crearOrdenConVenta('8005');

        $this->fakearComoOrdenesNormales([
            $this->ordenCruda('8005', 'paid', [['status' => 'in_mediation']]),
        ]);
        app(SincronizadorOrdenes::class)->ejecutar();
        $this->assertSame('orden_en_mediacion', $orden->fresh()->motivo->value);

        $this->travel(1)->hours();
        $this->fakearComoOrdenesNormales([
            $this->ordenCruda('8005', 'paid', [['status' => 'approved']]),
        ]);
        app(SincronizadorOrdenes::class)->ejecutar();

        $orden->refresh();
        $this->assertSame('convertida', $orden->estado_conversion->value);
        $this->assertNull($orden->motivo);
    }
}
