<?php

namespace Tests\Feature;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Jobs\GenerarSugerenciaMercadoLibre;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreConversacion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreMensaje;
use App\Models\Integraciones\MercadoLibreSugerencia;
use App\Models\Permiso;
use App\Models\Rol;
use App\Services\MercadoLibre\Bot\GeneradorDeSugerencias;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 033, US2: generación automática con el switch activo (FR-004),
 * ausencia de generación automática con el switch apagado (FR-006 negativo),
 * generación bajo demanda (FR-006), y manejo de fallo del generador dentro
 * del Job (Edge Case, FR-011a).
 */
class MercadoLibreSugerenciaTest extends TestCase
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

    public function test_switch_activo_despacha_el_job_al_llegar_un_mensaje(): void
    {
        Bus::fake();
        FuncionAvanzada::where('clave', 'mercadolibre_bot')->update(['activa' => true]);

        Http::fake(['api.mercadolibre.com/questions/999' => Http::response([
            'id' => 999, 'item_id' => 'MLA111', 'from' => ['id' => 987],
            'text' => '¿Hacen envíos?', 'status' => 'UNANSWERED', 'date_created' => '2026-08-02T10:00:00.000Z',
        ], 200)]);

        $payload = [
            'resource' => '/questions/999', 'user_id' => 1, 'topic' => 'questions',
            'application_id' => 123456789012, 'attempts' => 1,
        ];

        $this->postJson('/webhooks/mercadolibre', $payload)->assertOk();

        Bus::assertDispatched(GenerarSugerenciaMercadoLibre::class);
    }

    public function test_switch_apagado_no_despacha_el_job_automaticamente(): void
    {
        Bus::fake();
        FuncionAvanzada::where('clave', 'mercadolibre_bot')->update(['activa' => false]);

        Http::fake(['api.mercadolibre.com/questions/999' => Http::response([
            'id' => 999, 'item_id' => 'MLA111', 'from' => ['id' => 987],
            'text' => '¿Hacen envíos?', 'status' => 'UNANSWERED', 'date_created' => '2026-08-02T10:00:00.000Z',
        ], 200)]);

        $payload = [
            'resource' => '/questions/999', 'user_id' => 1, 'topic' => 'questions',
            'application_id' => 123456789012, 'attempts' => 1,
        ];

        $this->postJson('/webhooks/mercadolibre', $payload)->assertOk();

        Bus::assertNotDispatched(GenerarSugerenciaMercadoLibre::class);
    }

    public function test_generacion_bajo_demanda_despacha_el_job(): void
    {
        Bus::fake();

        $respuesta = $this->postJson("/mensajeria/{$this->conversacion->id}/sugerencia");

        $respuesta->assertStatus(202)->assertJson(['ok' => true, 'estado' => 'generando']);
        Bus::assertDispatched(GenerarSugerenciaMercadoLibre::class, fn ($job) => $job->mensaje->is($this->mensaje));
    }

    public function test_job_genera_sugerencia_lista_con_generador_exitoso(): void
    {
        $this->app->bind(GeneradorDeSugerencias::class, fn () => new class implements GeneradorDeSugerencias
        {
            public function generar($conversacion, $mensaje, string $instrucciones): string
            {
                return 'Sí, tenemos stock disponible.';
            }
        });

        GenerarSugerenciaMercadoLibre::dispatchSync($this->mensaje);

        $this->assertDatabaseHas('ml_sugerencias', [
            'ml_mensaje_id' => $this->mensaje->id,
            'estado' => 'lista',
            'texto_sugerido' => 'Sí, tenemos stock disponible.',
        ]);
    }

    public function test_job_marca_error_si_el_generador_falla(): void
    {
        $this->app->bind(GeneradorDeSugerencias::class, fn () => new class implements GeneradorDeSugerencias
        {
            public function generar($conversacion, $mensaje, string $instrucciones): string
            {
                throw new \RuntimeException('Timeout del proveedor de IA.');
            }
        });

        GenerarSugerenciaMercadoLibre::dispatchSync($this->mensaje);

        $sugerencia = MercadoLibreSugerencia::where('ml_mensaje_id', $this->mensaje->id)->first();
        $this->assertSame('error', $sugerencia->estado);
        $this->assertStringContainsString('Timeout', $sugerencia->error_mensaje);
    }

    public function test_job_marca_error_si_la_respuesta_supera_350_caracteres(): void
    {
        $this->app->bind(GeneradorDeSugerencias::class, fn () => new class implements GeneradorDeSugerencias
        {
            public function generar($conversacion, $mensaje, string $instrucciones): string
            {
                return str_repeat('a', 351);
            }
        });

        GenerarSugerenciaMercadoLibre::dispatchSync($this->mensaje);

        $sugerencia = MercadoLibreSugerencia::where('ml_mensaje_id', $this->mensaje->id)->first();
        $this->assertSame('error', $sugerencia->estado);
    }
}
