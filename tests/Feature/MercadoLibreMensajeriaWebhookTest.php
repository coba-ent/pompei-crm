<?php

namespace Tests\Feature;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreMensaje;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 032, US1: idempotencia del webhook ante reintentos (FR-004) y
 * asociación a publicación/orden existentes. Sin sesión (el webhook es
 * server-to-server), por eso desactiva la autenticación por defecto del
 * TestCase.
 */
class MercadoLibreMensajeriaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    protected function setUp(): void
    {
        parent::setUp();

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
        ]);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
    }

    public function test_notificacion_de_pregunta_crea_conversacion_y_mensaje(): void
    {
        $producto = Producto::factory()->create();
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA111', 'producto_id' => $producto->id]);

        Http::fake([
            'api.mercadolibre.com/questions/123456789' => Http::response([
                'id' => 123456789,
                'item_id' => 'MLA111',
                'from' => ['id' => 987],
                'text' => '¿Tenés stock?',
                'status' => 'UNANSWERED',
                'date_created' => '2026-08-02T10:00:00.000Z',
            ], 200),
        ]);

        $payload = [
            'resource' => '/questions/123456789', 'user_id' => 1, 'topic' => 'questions',
            'application_id' => 123456789012, 'attempts' => 1,
        ];

        $respuesta = $this->postJson('/webhooks/mercadolibre', $payload);

        $respuesta->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('ml_conversaciones', 1);
        $this->assertDatabaseHas('ml_mensajes', ['ml_id' => '123456789', 'texto' => '¿Tenés stock?']);

        $conversacion = MercadoLibreConversacion::first();
        $this->assertSame($producto->id, $conversacion->publicacionProducto->producto_id);
    }

    public function test_reintento_de_la_misma_notificacion_no_duplica(): void
    {
        Http::fake([
            'api.mercadolibre.com/questions/123456789' => Http::response([
                'id' => 123456789, 'item_id' => 'MLA111', 'from' => ['id' => 987],
                'text' => '¿Tenés stock?', 'status' => 'UNANSWERED', 'date_created' => '2026-08-02T10:00:00.000Z',
            ], 200),
        ]);

        $payload = [
            'resource' => '/questions/123456789', 'user_id' => 1, 'topic' => 'questions',
            'application_id' => 123456789012, 'attempts' => 1,
        ];

        $this->postJson('/webhooks/mercadolibre', $payload)->assertOk();
        $this->postJson('/webhooks/mercadolibre', $payload)->assertOk();

        $this->assertDatabaseCount('ml_conversaciones', 1);
        $this->assertDatabaseCount('ml_mensajes', 1);
    }

    public function test_mensaje_post_venta_se_asocia_a_la_orden_por_pack_id(): void
    {
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => '555000111', 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'total' => 100,
            'moneda' => 'ARS', 'comprador_ml_id' => '987', 'sincronizada_en' => now(),
        ]);

        // Shape real confirmado 02/08/2026 (developers.mercadolibre.com.ar/es_ar/mensajeria-post-venta):
        // el webhook manda el message_id opaco en `resource`, se resuelve con GET /messages/{id}.
        Http::fake([
            'api.mercadolibre.com/messages/msg-1*' => Http::response([
                'messages' => [[
                    'id' => 'msg-1',
                    'from' => ['user_id' => 987],
                    'to' => ['user_id' => 1],
                    'text' => 'Hola, ¿cuándo llega mi pedido?',
                    'message_date' => ['created' => '2026-08-02T11:00:00.000Z'],
                    'message_resources' => [
                        ['id' => '555000111', 'name' => 'packs'],
                        ['id' => '1', 'name' => 'sellers'],
                    ],
                ]],
            ], 200),
        ]);

        $payload = [
            'resource' => 'msg-1', 'user_id' => 1, 'topic' => 'messages',
            'application_id' => 123456789012, 'attempts' => 1,
        ];

        $this->postJson('/webhooks/mercadolibre', $payload)->assertOk();

        $conversacion = MercadoLibreConversacion::where('tipo', 'post_venta')->first();
        $this->assertNotNull($conversacion);
        $this->assertSame($orden->id, $conversacion->ml_orden_id);
        $this->assertDatabaseHas('ml_mensajes', ['ml_id' => 'msg-1', 'origen' => 'comprador']);
    }

    public function test_application_id_no_coincide_devuelve_401(): void
    {
        $payload = [
            'resource' => '/questions/1', 'user_id' => 1, 'topic' => 'questions',
            'application_id' => 999999999999, 'attempts' => 1,
        ];

        $this->postJson('/webhooks/mercadolibre', $payload)->assertStatus(401);
        $this->assertDatabaseCount('ml_conversaciones', 0);
    }
}
