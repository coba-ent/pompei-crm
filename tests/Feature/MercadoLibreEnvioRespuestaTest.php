<?php

namespace Tests\Feature;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreMensaje;
use App\Models\Integraciones\MercadoLibreSugerencia;
use App\Models\Permiso;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 032, US2: auditoría de la respuesta enviada (FR-006), guard de doble
 * respuesta (FR-007) y manejo de error de la API de ML sin marcar éxito
 * falso (FR-008).
 */
class MercadoLibreEnvioRespuestaTest extends TestCase
{
    use RefreshDatabase;

    private MercadoLibreConversacion $conversacion;

    private MercadoLibreMensaje $mensaje;

    protected function setUp(): void
    {
        parent::setUp();

        $rol = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($rol->id);

        Permiso::updateOrCreate(['codigo' => 'mensajeria.ver'], ['descripcion' => 'Ver', 'modulo' => 'mensajeria']);
        Permiso::updateOrCreate(['codigo' => 'mensajeria.responder'], ['descripcion' => 'Responder', 'modulo' => 'mensajeria']);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        $this->conversacion = MercadoLibreConversacion::create([
            'tipo' => 'pregunta', 'comprador_ml_id' => '987', 'publicacion_id_ml' => 'MLA111',
            'estado' => 'pendiente', 'ultimo_mensaje_en' => now(),
        ]);
        $this->mensaje = MercadoLibreMensaje::create([
            'ml_conversacion_id' => $this->conversacion->id, 'ml_id' => '123456789',
            'origen' => 'comprador', 'texto' => '¿Tenés stock?', 'enviado_en' => now(),
        ]);
    }

    public function test_envio_exitoso_registra_auditoria_y_marca_respondida(): void
    {
        Http::fake(['api.mercadolibre.com/answers' => Http::response(['id' => 1, 'status' => 'ANSWERED'], 200)]);

        $respuesta = $this->postJson("/mensajeria/{$this->conversacion->id}/responder", ['texto' => 'Sí, tenemos stock.']);

        $respuesta->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('ml_respuestas_enviadas', [
            'ml_mensaje_id' => $this->mensaje->id, 'texto_enviado' => 'Sí, tenemos stock.', 'resultado' => 'exito',
        ]);
        $this->assertSame('respondida', $this->conversacion->fresh()->estado);
        $this->assertDatabaseHas('ml_mensajes', [
            'ml_conversacion_id' => $this->conversacion->id, 'origen' => 'negocio', 'texto' => 'Sí, tenemos stock.',
        ]);
    }

    public function test_segundo_intento_sobre_conversacion_ya_respondida_devuelve_422(): void
    {
        Http::fake(['api.mercadolibre.com/answers' => Http::response(['id' => 1, 'status' => 'ANSWERED'], 200)]);

        $this->postJson("/mensajeria/{$this->conversacion->id}/responder", ['texto' => 'Primera respuesta.'])->assertOk();
        $respuesta = $this->postJson("/mensajeria/{$this->conversacion->id}/responder", ['texto' => 'Segundo intento.']);

        $respuesta->assertStatus(422);
        $this->assertDatabaseCount('ml_respuestas_enviadas', 1);
    }

    public function test_fallo_de_la_api_de_ml_no_marca_como_respondida(): void
    {
        Http::fake(['api.mercadolibre.com/answers' => Http::response(['message' => 'contenido rechazado'], 400)]);

        $respuesta = $this->postJson("/mensajeria/{$this->conversacion->id}/responder", ['texto' => 'Texto rechazado.']);

        $respuesta->assertStatus(422);
        $this->assertDatabaseHas('ml_respuestas_enviadas', ['ml_mensaje_id' => $this->mensaje->id, 'resultado' => 'error']);
        $this->assertSame('pendiente', $this->conversacion->fresh()->estado);
    }

    /**
     * Regresión del bug real detectado el 02/08/2026: el envío post-venta armaba la URL con
     * `conversacion->orden?->ml_order_id` (NULL si la orden no está sincronizada al CRM) en vez
     * de `pack_id_ml`, y `from`/`to` apuntaban los dos al comprador. Ambos bugs rompían el envío
     * real (404 de ML) sin que ningún test lo hubiera cubierto — esta conversación deliberadamente
     * NO tiene `ml_orden_id` para reproducir el escenario exacto.
     */
    public function test_envio_post_venta_usa_pack_id_ml_y_direccion_correcta_sin_orden_sincronizada(): void
    {
        $conversacionPostVenta = MercadoLibreConversacion::create([
            'tipo' => 'post_venta', 'comprador_ml_id' => '3565549380', 'pack_id_ml' => '2000014252564861',
            'estado' => 'pendiente', 'ultimo_mensaje_en' => now(),
        ]);
        $mensajePostVenta = MercadoLibreMensaje::create([
            'ml_conversacion_id' => $conversacionPostVenta->id, 'ml_id' => 'msg-abc',
            'origen' => 'comprador', 'texto' => '¿Cuándo llega mi pedido?', 'enviado_en' => now(),
        ]);

        Http::fake(['api.mercadolibre.com/messages/packs/2000014252564861/sellers/1*' => Http::response(['id' => 'msg-xyz'], 200)]);

        $respuesta = $this->postJson("/mensajeria/{$conversacionPostVenta->id}/responder", ['texto' => 'Llega la semana que viene.']);

        $respuesta->assertOk()->assertJson(['ok' => true]);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mercadolibre.com/messages/packs/2000014252564861/sellers/1?tag=post_sale'
                && $request['from']['user_id'] === '1'
                && $request['to']['user_id'] === '3565549380';
        });
        $this->assertDatabaseHas('ml_respuestas_enviadas', ['ml_mensaje_id' => $mensajePostVenta->id, 'resultado' => 'exito']);
    }

    /** Spec 033, US3 (FR-010): envío de una sugerencia tal cual, sin editar. */
    public function test_envio_con_sugerencia_sin_editar_audita_sugerencia_editada_false(): void
    {
        Http::fake(['api.mercadolibre.com/answers' => Http::response(['id' => 1, 'status' => 'ANSWERED'], 200)]);

        $sugerencia = MercadoLibreSugerencia::create([
            'ml_mensaje_id' => $this->mensaje->id, 'texto_sugerido' => 'Sí, tenemos stock.',
            'estado' => 'lista', 'generada_en' => now(),
        ]);

        $respuesta = $this->postJson("/mensajeria/{$this->conversacion->id}/responder", [
            'texto' => 'Sí, tenemos stock.', 'sugerencia_id' => $sugerencia->id,
        ]);

        $respuesta->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('ml_respuestas_enviadas', [
            'ml_mensaje_id' => $this->mensaje->id, 'ml_sugerencia_id' => $sugerencia->id, 'sugerencia_editada' => false,
        ]);
    }

    /** Spec 033, US3 (FR-010): envío de una sugerencia editada antes de confirmar. */
    public function test_envio_con_sugerencia_editada_audita_sugerencia_editada_true(): void
    {
        Http::fake(['api.mercadolibre.com/answers' => Http::response(['id' => 1, 'status' => 'ANSWERED'], 200)]);

        $sugerencia = MercadoLibreSugerencia::create([
            'ml_mensaje_id' => $this->mensaje->id, 'texto_sugerido' => 'Sí, tenemos stock.',
            'estado' => 'lista', 'generada_en' => now(),
        ]);

        $respuesta = $this->postJson("/mensajeria/{$this->conversacion->id}/responder", [
            'texto' => 'Sí, tenemos stock disponible en depósito central.', 'sugerencia_id' => $sugerencia->id,
        ]);

        $respuesta->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('ml_respuestas_enviadas', [
            'ml_mensaje_id' => $this->mensaje->id, 'ml_sugerencia_id' => $sugerencia->id, 'sugerencia_editada' => true,
        ]);
    }

    /**
     * Spec 033, US3: gap encontrado al revisar `enviar()` — el mensaje a responder se resuelve
     * internamente (último mensaje del comprador), no lo recibe el caller. Si la sugerencia
     * enviada pertenece a un mensaje distinto (llegó uno nuevo mientras tanto), no se la audita.
     */
    public function test_envio_con_sugerencia_de_otro_mensaje_no_la_audita(): void
    {
        Http::fake(['api.mercadolibre.com/answers' => Http::response(['id' => 1, 'status' => 'ANSWERED'], 200)]);

        $otroMensaje = MercadoLibreMensaje::create([
            'ml_conversacion_id' => $this->conversacion->id, 'ml_id' => 'otro-mensaje',
            'origen' => 'comprador', 'texto' => 'Otra pregunta distinta', 'enviado_en' => now()->addMinute(),
        ]);
        $sugerenciaDeOtroMensaje = MercadoLibreSugerencia::create([
            'ml_mensaje_id' => $this->mensaje->id, 'texto_sugerido' => 'Sí, tenemos stock.',
            'estado' => 'lista', 'generada_en' => now(),
        ]);

        // El último mensaje del comprador ahora es $otroMensaje — enviar() lo resuelve
        // internamente, por eso responde a ese, aunque la sugerencia sea de $this->mensaje.
        $respuesta = $this->postJson("/mensajeria/{$this->conversacion->id}/responder", [
            'texto' => 'Sí, tenemos stock.', 'sugerencia_id' => $sugerenciaDeOtroMensaje->id,
        ]);

        $respuesta->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('ml_respuestas_enviadas', [
            'ml_mensaje_id' => $otroMensaje->id, 'ml_sugerencia_id' => null, 'sugerencia_editada' => null,
        ]);
    }
}
