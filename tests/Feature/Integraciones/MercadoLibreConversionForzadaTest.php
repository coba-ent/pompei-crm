<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Enums\MercadoLibre\EstadoConversion;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
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
 * Spec 066: cuatro estados excepcionales (mediación, cancelada, reembolso parcial,
 * alerta de fraude) dejan de convertirse solos y sólo se pueden forzar a mano con
 * confirmación explícita validada en el servidor.
 */
class MercadoLibreConversionForzadaTest extends TestCase
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
            'modo_solo_lectura' => false,
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        // OJO: nada de fake genérico acá. Http::fake() APILA callbacks y el primero que
        // matchea gana para siempre — un fallback amplio registrado en setUp() le ganaría a
        // cualquier fake más específico que un test necesite registrar después (T019). Ninguno
        // de estos tests llama a la API real: ConversorOrdenAVenta no hace HTTP, y el único que
        // sincroniza (T019) registra su propio fake completo.
    }

    private function crearOrden(array $overrides = []): MercadoLibreOrden
    {
        $itemId = 'MLA'.random_int(1, 999999);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::firstOrCreate(['ml_item_id' => $itemId], ['producto_id' => $producto->id]);

        $orden = MercadoLibreOrden::create(array_replace([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'comprador_condicion_iva' => 'Consumidor Final',
            'sincronizada_en' => now(),
        ], $overrides));

        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => $itemId, 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00, 'producto_id' => $producto->id,
        ]);

        return $orden;
    }

    // -------------------------------------------------------------------
    // US1 — el evaluador frena, el cron y el lote no convierten solos
    // -------------------------------------------------------------------

    /** T007 */
    public function test_orden_pagada_en_mediacion_queda_requiere_atencion_y_el_cron_no_la_convierte(): void
    {
        MercadoLibreConfiguracion::actual()->update(['creacion_automatica' => true]);
        $orden = $this->crearOrden(['en_mediacion' => true]);

        // EvaluadorConvertibilidad es el único lugar que deriva el estado (lo que usan tanto
        // el cron como el lote): si acá da RequiereAtencion, ninguno de los dos convierte.
        [$estado, $motivo] = app(\App\Services\MercadoLibre\EvaluadorConvertibilidad::class)
            ->evaluar($orden->fresh(['items']));
        $this->assertSame(EstadoConversion::RequiereAtencion, $estado);
        $this->assertSame('orden_en_mediacion', $motivo->value);

        // Confirma además que el conversor (camino del cron/creación automática) rechaza y no crea Venta.
        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $this->assertFalse($resultado['ok']);
        $this->assertDatabaseCount('ventas', 0);
    }

    /** T008 */
    public function test_orden_con_reembolso_parcial_queda_requiere_atencion_y_no_se_convierte_sola(): void
    {
        $orden = $this->crearOrden(['estado_orden' => 'reembolso_parcial']);

        [$estado, $motivo] = app(\App\Services\MercadoLibre\EvaluadorConvertibilidad::class)
            ->evaluar($orden->fresh(['items']));
        $this->assertSame(EstadoConversion::RequiereAtencion, $estado);
        $this->assertSame('orden_reembolso_parcial', $motivo->value);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden, null, automatica: true);
        $this->assertFalse($resultado['ok']);
        $this->assertDatabaseCount('ventas', 0);
    }

    /** T009 */
    public function test_lote_no_convierte_ninguna_excepcional_y_las_informa_bajo_excluidas(): void
    {
        $this->crearOrden();
        $this->crearOrden(['en_mediacion' => true]);
        $this->crearOrden(['estado_orden' => 'reembolso_parcial']);
        $this->crearOrden(['tiene_alerta_fraude' => true]);
        // Cancelada no cae en el lote: nunca queda "Lista", el batch filtra por ese estado.

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));

        $respuesta->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('convertidas', 1)
            ->assertJsonPath('fallidas', 0)
            ->assertJsonPath('excluidas', 3);

        $motivos = collect($respuesta->json('detalle_excluidas'))->pluck('motivo')->all();
        $this->assertContains('orden_en_mediacion', $motivos);
        $this->assertContains('orden_reembolso_parcial', $motivos);
        $this->assertContains('alerta_fraude', $motivos);
        $this->assertDatabaseCount('ventas', 1);
    }

    /** T010 — SC-006: regresión, una orden normal se sigue convirtiendo igual que antes. */
    public function test_orden_normal_se_sigue_convirtiendo_por_cron_y_por_lote_igual_que_antes(): void
    {
        $ordenAutomatica = $this->crearOrden();
        $resultadoAutomatico = app(ConversorOrdenAVenta::class)->convertir($ordenAutomatica, null, automatica: true);
        $this->assertTrue($resultadoAutomatico['ok']);

        $ordenLote = $this->crearOrden();
        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));
        $respuesta->assertOk()->assertJsonPath('convertidas', 1)->assertJsonPath('excluidas', 0);

        $this->assertDatabaseCount('ventas', 2);
    }

    /** T011 — FR-007: una orden que deja de estar en mediación vuelve a Lista sola. */
    public function test_orden_que_deja_de_estar_en_mediacion_vuelve_a_lista(): void
    {
        $orden = $this->crearOrden(['en_mediacion' => true, 'estado_conversion' => 'requiere_atencion', 'motivo' => 'orden_en_mediacion']);

        [$estado] = app(\App\Services\MercadoLibre\EvaluadorConvertibilidad::class)->evaluar($orden->fresh(['items']));
        $this->assertSame(EstadoConversion::RequiereAtencion, $estado);

        $orden->update(['en_mediacion' => false]);
        [$estadoNuevo] = app(\App\Services\MercadoLibre\EvaluadorConvertibilidad::class)->evaluar($orden->fresh(['items']));

        $this->assertSame(EstadoConversion::Lista, $estadoNuevo);
    }

    // -------------------------------------------------------------------
    // US2 — forzar la conversión a mano
    // -------------------------------------------------------------------

    /** T014 — la barrera más importante: sin forzar_conversion, 409 y ninguna Venta. */
    public function test_post_de_orden_excepcional_sin_forzar_conversion_devuelve_409_y_no_crea_venta(): void
    {
        $orden = $this->crearOrden(['estado_orden' => 'cancelada']);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.convertirGuardar', $orden), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $respuesta->assertStatus(409)->assertJsonPath('requiere_confirmacion', true)->assertJsonPath('motivo', 'orden_cancelada');
        $this->assertDatabaseCount('ventas', 0);
        $this->assertNull($orden->fresh()->venta_id);
    }

    /** T015 */
    public function test_con_forzar_conversion_true_la_venta_se_crea_con_todo_correcto_y_queda_auditada(): void
    {
        $orden = $this->crearOrden(['estado_orden' => 'cancelada']);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.convertirGuardar', $orden), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'forzar_conversion' => 1,
        ]);

        $respuesta->assertCreated()->assertJsonPath('ok', true)->assertJsonPath('forzada', true);
        $orden->refresh();
        $this->assertNotNull($orden->venta_id);
        $this->assertSame('orden_cancelada', $orden->forzada_motivo);
        $this->assertSame(auth()->id(), $orden->forzada_por_id);
        $this->assertNotNull($orden->forzada_en);

        $venta = $orden->venta;
        $this->assertNotNull($venta->cliente_id);
        $this->assertDatabaseCount('cobros', 1);
        $this->assertDatabaseHas('cobros', ['venta_id' => $venta->id, 'monto' => 1210.00]);
        $this->assertDatabaseCount('movimientos_tesoreria', 1);
        $stock = \App\Models\Stock::where('producto_id', $orden->items->first()->producto_id)->value('cantidad');
        $this->assertNotNull($stock);
    }

    /** T016 — regresión FR-021: la Venta forzada nace sin comprobante fiscal emitido. */
    public function test_venta_forzada_se_crea_sin_comprobante_fiscal_emitido(): void
    {
        $orden = $this->crearOrden(['estado_orden' => 'cancelada']);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.convertirGuardar', $orden), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'forzar_conversion' => 1,
        ]);

        $respuesta->assertJsonPath('comprobante_emitido', false);
        $venta = $orden->fresh()->venta;
        $this->assertNull($venta->cae);
    }

    /** T017 — forzar no saltea los problemas de datos. */
    public function test_forzar_no_saltea_problemas_de_datos(): void
    {
        $itemId = 'MLA'.random_int(1, 999999);
        // Publicación SIN vincular a propósito.
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => (string) random_int(100000, 999999),
            'estado_ml' => 'cancelled', 'estado_orden' => 'cancelada', 'estado_conversion' => 'cancelada',
            'fecha_creada' => now(), 'fecha_cerrada' => now(), 'total' => 1210.00, 'moneda' => 'ARS',
            'comprador_ml_id' => (string) random_int(1, 999999), 'comprador_apodo' => 'COMPRADOR'.random_int(1, 999999),
            'comprador_condicion_iva' => 'Consumidor Final',
            'sincronizada_en' => now(),
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => $itemId, 'titulo' => 'Producto',
            'cantidad' => 1, 'precio_unitario' => 1210.00, 'total_linea' => 1210.00,
        ]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.convertirGuardar', $orden), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'forzar_conversion' => 1,
        ]);

        $respuesta->assertStatus(409);
        $this->assertDatabaseCount('ventas', 0);
    }

    /** T018 — pendiente de pago, función desactivada y sólo lectura siguen rechazando aun forzando. */
    public function test_orden_pendiente_de_pago_sigue_rechazandose_aun_forzando(): void
    {
        $orden = $this->crearOrden(['estado_orden' => 'pendiente', 'estado_conversion' => 'pendiente_pago']);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.convertirGuardar', $orden), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'forzar_conversion' => 1,
        ]);

        $respuesta->assertStatus(409);
        $this->assertDatabaseCount('ventas', 0);
    }

    /**
     * FR-014: el corte global de modo sólo lectura sigue aplicando aun forzando. El corte de
     * "función avanzada desactivada" hoy sólo se enforce en el camino de lote
     * (verificarCortesBatch) — la conversión de UNA orden no lo chequea, ni antes de esta
     * spec ni después: no es algo que esta feature deba agregar.
     */
    public function test_modo_solo_lectura_bloquea_aun_forzando(): void
    {
        $orden = $this->crearOrden(['estado_orden' => 'cancelada']);
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.transformarTodasEnVenta'));
        $respuesta->assertStatus(409);
        $this->assertDatabaseCount('ventas', 0);
    }

    /** T019 — FR-018/FR-019: tras forzar, no se repite el aviso del mismo motivo; con otro, sí. */
    public function test_tras_forzar_una_cancelada_el_detector_no_repite_el_aviso_pero_avisa_si_cambia_el_motivo(): void
    {
        $orden = $this->crearOrden(['estado_orden' => 'cancelada']);

        $respuesta = $this->postJson(route('ingresos.mercadolibre.convertirGuardar', $orden), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'forzar_conversion' => 1,
        ]);
        $respuesta->assertCreated();
        $orden->refresh();
        $this->assertSame('orden_cancelada', $orden->forzada_motivo);
        $this->assertSame(EstadoConversion::Convertida, $orden->estado_conversion);

        // Http::fake(Closure) APILA los callbacks (el primero que matchea gana) — no se puede
        // volver a llamar a Http::fake() para simular una segunda sincronización con datos
        // distintos; hay que mutar el estado que lee un único closure (ver CancelacionesMotivosTest).
        $payments = [];
        $ordenCruda = function () use ($orden, &$payments) {
            return [
                'id' => $orden->ml_order_id, 'status' => 'cancelled',
                'date_created' => now()->toIso8601String(), 'date_closed' => now()->toIso8601String(),
                'total_amount' => 1210.0, 'currency_id' => 'ARS',
                'buyer' => ['id' => (int) $orden->comprador_ml_id, 'nickname' => $orden->comprador_apodo],
                'tags' => ['cancelled'],
                'payments' => $payments,
                'order_items' => [[
                    'item' => ['id' => $orden->items->first()->ml_item_id, 'title' => 'Producto', 'variation_id' => null],
                    'quantity' => 1, 'unit_price' => 1210.0,
                ]],
            ];
        };

        Http::fake(function ($request) use ($ordenCruda) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                return Http::response(['results' => [$ordenCruda()], 'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50]], 200);
            }

            return Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200);
        });

        // Re-sincronizar la MISMA orden cancelada no debe reabrir el aviso.
        app(\App\Services\MercadoLibre\SincronizadorOrdenes::class)->ejecutar();
        $orden->refresh();
        $this->assertSame(EstadoConversion::Convertida, $orden->estado_conversion, 'no debe repetir el aviso del mismo motivo forzado');

        // Ahora entra en mediación: motivo distinto al forzado, sí debe avisar.
        $payments = [['status' => 'in_mediation']];

        app(\App\Services\MercadoLibre\SincronizadorOrdenes::class)->ejecutar();
        $orden->refresh();
        $this->assertSame(EstadoConversion::RequiereAtencion, $orden->estado_conversion, 'motivo distinto al forzado sí debe avisar');
        $this->assertSame('orden_en_mediacion', $orden->motivo->value);
    }

    /** T020 — los cuatro motivos excepcionales se pueden forzar. */
    public function test_los_cuatro_motivos_excepcionales_se_pueden_forzar(): void
    {
        $casos = [
            ['estado_orden' => 'cancelada'],
            ['estado_orden' => 'reembolso_parcial'],
            ['en_mediacion' => true],
            ['tiene_alerta_fraude' => true],
        ];

        $motivosEsperados = ['orden_cancelada', 'orden_reembolso_parcial', 'orden_en_mediacion', 'alerta_fraude'];

        foreach ($casos as $i => $overrides) {
            $orden = $this->crearOrden($overrides);

            $respuesta = $this->postJson(route('ingresos.mercadolibre.convertirGuardar', $orden), [
                'submit_token' => (string) \Illuminate\Support\Str::uuid(),
                'forzar_conversion' => 1,
            ]);

            $respuesta->assertCreated();
            $this->assertSame($motivosEsperados[$i], $orden->fresh()->forzada_motivo);
        }
    }

    /** T023a — FR-022: la derivación de A/B/C/E da el mismo resultado forzando que en una conversión normal. */
    public function test_derivacion_de_tipo_de_comprobante_es_igual_forzando_que_normal(): void
    {
        $ordenNormal = $this->crearOrden(['comprador_condicion_iva' => 'Consumidor Final']);
        $resultadoNormal = app(ConversorOrdenAVenta::class)->convertir($ordenNormal, auth()->id(), automatica: false);
        $this->assertTrue($resultadoNormal['ok']);

        $ordenForzada = $this->crearOrden(['comprador_condicion_iva' => 'Consumidor Final', 'estado_orden' => 'cancelada']);
        $resultadoForzado = app(ConversorOrdenAVenta::class)->convertir($ordenForzada, auth()->id(), automatica: false, forzada: true);
        $this->assertTrue($resultadoForzado['ok']);

        $this->assertSame(
            $resultadoNormal['venta']->tipo_comprobante,
            $resultadoForzado['venta']->tipo_comprobante,
        );
    }

    /** Confirma que la operación forzada queda registrada en la bitácora (FR-011). */
    public function test_conversion_forzada_registra_la_operacion_en_la_bitacora(): void
    {
        $orden = $this->crearOrden(['estado_orden' => 'cancelada']);

        app(ConversorOrdenAVenta::class)->convertir($orden, auth()->id(), automatica: false, forzada: true);

        $this->assertDatabaseHas('ml_operaciones_log', [
            'operacion' => 'convertir_orden_forzada',
            'usuario_id' => auth()->id(),
            'resultado' => 'ok',
        ]);
    }
}
