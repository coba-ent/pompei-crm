<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * spec 065 · US5 — a qué depósito se imputa la Venta de una orden de Mercado Libre.
 *
 * Regla de oro de estos tests (FR-022): **ninguna** condición de esta feature puede
 * impedir que una orden se convierta en Venta.
 */
class MercadoLibreVentaFullDepositoTest extends TestCase
{
    use RefreshDatabase;

    private Deposito $general;

    private Deposito $depositoFull;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        (new CondicionIvaSeeder())->run();

        $this->general = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->depositoFull = Deposito::create(['nombre' => 'Mercado Libre Full', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
            'modo_solo_lectura' => false,
            'deposito_id' => $this->general->id,
            'deposito_full_id' => $this->depositoFull->id,
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);
    }

    /**
     * @param  array<int, string>  $logisticasPorLinea  `''` = vinculada pero sin clasificar
     */
    private function crearOrden(array $logisticasPorLinea): MercadoLibreOrden
    {
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => 1210.00 * count($logisticasPorLinea), 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999),
            'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'sincronizada_en' => now(),
        ]);

        foreach ($logisticasPorLinea as $indice => $logistica) {
            $itemId = 'MLA'.random_int(1, 999999).$indice;
            $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

            MercadoLibrePublicacionProducto::create([
                'ml_item_id' => $itemId, 'producto_id' => $producto->id,
                'logistic_type' => $logistica === '' ? null : $logistica,
            ]);

            MercadoLibreOrdenItem::create([
                'ml_orden_id' => $orden->id, 'ml_item_id' => $itemId, 'titulo' => 'Producto',
                'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00,
                'producto_id' => $producto->id,
            ]);
        }

        return $orden->fresh('items');
    }

    private function convertir(MercadoLibreOrden $orden): \App\Models\Venta
    {
        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, auth()->id());

        $this->assertTrue($resultado['ok'], $resultado['mensaje'] ?? 'La conversión falló.');

        return $orden->fresh()->venta;
    }

    /** T029 · FR-020: una orden íntegramente Full se imputa al depósito Full. */
    public function test_orden_integramente_full_va_al_deposito_full(): void
    {
        $venta = $this->convertir($this->crearOrden(['fulfillment', 'fulfillment']));

        $this->assertSame($this->depositoFull->id, $venta->deposito_id);
    }

    /** T029 · FR-021: una orden de logística propia va al general, exactamente como hoy. */
    public function test_orden_de_logistica_propia_va_al_deposito_general(): void
    {
        $venta = $this->convertir($this->crearOrden(['xd_drop_off']));

        $this->assertSame($this->general->id, $venta->deposito_id);
    }

    /**
     * T029 · FR-020a: una orden MIXTA va al general. Una Venta tiene un solo depósito, y
     * descontar de Full mercadería que salió del domicilio del vendedor sería peor que la
     * imprecisión de imputar todo al general, donde el stock físico sí existe.
     */
    public function test_orden_mixta_va_al_deposito_general(): void
    {
        $venta = $this->convertir($this->crearOrden(['fulfillment', 'xd_drop_off']));

        $this->assertSame($this->general->id, $venta->deposito_id);
    }

    /** T029 · FR-022: sin depósito Full configurado va al general y la Venta se crea igual. */
    public function test_sin_deposito_full_configurado_la_venta_se_crea_igual(): void
    {
        MercadoLibreConfiguracion::actual()->update(['deposito_full_id' => null]);

        $venta = $this->convertir($this->crearOrden(['fulfillment']));

        $this->assertNotNull($venta);
        $this->assertSame($this->general->id, $venta->deposito_id);
    }

    /**
     * T029 · FR-005: una publicación todavía sin clasificar cuenta como no-Full, así que
     * arrastra la orden entera al depósito general. El sistema nunca asume Full ante la duda.
     *
     * (Una publicación directamente **sin vincular** no llega hasta acá: la conversión la
     * rechaza antes por una regla previa a esta feature.)
     */
    public function test_orden_con_una_linea_sin_clasificar_va_al_general(): void
    {
        $venta = $this->convertir($this->crearOrden(['fulfillment', '']));

        $this->assertSame($this->general->id, $venta->deposito_id);
    }

    /**
     * T030 · FR-020b: el depósito imputado a la Venta y el que usa el descuento de
     * existencias son el mismo. Si divergieran, la Venta diría "salió de Full" mientras el
     * stock se descuenta del depósito físico general — el peor de los dos mundos.
     */
    public function test_el_deposito_de_la_venta_y_el_del_movimiento_coinciden(): void
    {
        $venta = $this->convertir($this->crearOrden(['fulfillment']));

        $movimientos = MovimientoStock::where('deposito_id', '!=', null)->get();

        $this->assertNotEmpty($movimientos);
        $this->assertSame($this->depositoFull->id, $venta->deposito_id);
        foreach ($movimientos as $movimiento) {
            $this->assertSame($venta->deposito_id, (int) $movimiento->deposito_id);
        }
    }

    /** T030: contraprueba — con logística propia, ambos siguen apuntando al general. */
    public function test_con_logistica_propia_venta_y_movimiento_siguen_en_el_general(): void
    {
        $venta = $this->convertir($this->crearOrden(['xd_drop_off']));

        $this->assertSame($this->general->id, $venta->deposito_id);
        $this->assertSame(
            $this->general->id,
            (int) MovimientoStock::query()->latest('id')->first()->deposito_id
        );
    }

    /** T033 · FR-023: queda registrado por qué la Venta descontó de un depósito y no del otro. */
    public function test_registra_el_criterio_de_imputacion_para_auditoria(): void
    {
        $orden = $this->crearOrden(['fulfillment']);

        $this->convertir($orden);

        $registro = MercadoLibreOperacionLog::where('operacion', 'imputar_deposito_venta')->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertStringContainsString($orden->ml_order_id, $registro->payload_bloqueado);
        $this->assertStringContainsString('íntegramente Full', $registro->payload_bloqueado);
    }
}
