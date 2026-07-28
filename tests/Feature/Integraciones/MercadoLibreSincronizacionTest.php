<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOperacionLog;
use App\Models\Rol;
use App\Models\User;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US1 (spec 012): sincronización de órdenes. FR-013 (sin duplicar), FR-015
 * (retomar sin perder), SC-004/SC-014. T040: los tres cortes de FR-017/FR-018
 * y el acceso denegado sin permiso (FR-003).
 */
class MercadoLibreSincronizacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);

        MercadoLibreCuenta::create([
            'ml_user_id' => 1,
            'nickname' => 'CUENTA',
            'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vigente',
            'refresh_token' => 'rtk-vigente',
            'token_expira_en' => now()->addHours(3),
            'vinculada_en' => now(),
        ]);
    }

    private function ordenCruda(int $id, string $status = 'paid'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'date_created' => now()->toIso8601String(),
            'date_closed' => now()->toIso8601String(),
            'total_amount' => 500.0,
            'currency_id' => 'ARS',
            'buyer' => ['id' => 999, 'nickname' => 'COMPRADOR'],
            'tags' => [$status === 'paid' ? 'paid' : 'cancelled'],
            'order_items' => [[
                'item' => ['id' => 'MLA111', 'title' => 'Producto X', 'variation_id' => null],
                'quantity' => 1,
                'unit_price' => 500.0,
            ]],
        ];
    }

    private function fakearBusqueda(array $normales, array $canceladas): void
    {
        Http::fake(function ($request) use ($normales, $canceladas) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                return Http::response(['results' => $canceladas, 'paging' => ['total' => count($canceladas), 'offset' => 0, 'limit' => 50]], 200);
            }

            return Http::response(['results' => $normales, 'paging' => ['total' => count($normales), 'offset' => 0, 'limit' => 50]], 200);
        });
    }

    public function test_la_pantalla_del_listado_renderiza(): void
    {
        $this->get(route('ingresos.mercadolibre.index'))->assertOk();
    }

    public function test_sincronizacion_trae_ordenes_nuevas(): void
    {
        $this->fakearBusqueda([$this->ordenCruda(1001), $this->ordenCruda(1002)], []);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(2, $resultado['nuevas']);
        $this->assertDatabaseCount('ml_ordenes', 2);
    }

    public function test_resincronizar_no_duplica(): void
    {
        $this->fakearBusqueda([$this->ordenCruda(1001)], []);

        app(SincronizadorOrdenes::class)->ejecutar();
        $segunda = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($segunda['ok']);
        $this->assertDatabaseCount('ml_ordenes', 1);
    }

    public function test_corrida_interrumpida_se_retoma_sin_perder_ni_duplicar(): void
    {
        // La pasada de canceladas falla en el primer intento (interrupción) y responde bien en el segundo.
        // Un único Http::fake() con estado, porque llamadas sucesivas a Http::fake() no se reemplazan
        // entre sí de forma confiable cuando el primer callback matchea cualquier URL.
        $intentoCancelada = 0;

        Http::fake(function ($request) use (&$intentoCancelada) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                $intentoCancelada++;

                if ($intentoCancelada === 1) {
                    return Http::response(['message' => 'error de validación'], 400);
                }

                return Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200);
            }

            return Http::response(['results' => [$this->ordenCruda(1001)], 'paging' => ['total' => 1, 'offset' => 0, 'limit' => 50]], 200);
        });

        $primera = app(SincronizadorOrdenes::class)->ejecutar();
        $this->assertFalse($primera['ok']);

        // No se persistió el avance: la corrida no completó ambas pasadas (research.md §R4).
        $this->assertNull(MercadoLibreConfiguracion::actual()->ultima_sync_en);

        $segunda = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertTrue($segunda['ok'], json_encode($segunda));
        $this->assertDatabaseCount('ml_ordenes', 1);
        $this->assertNotNull(MercadoLibreConfiguracion::actual()->ultima_sync_en);
    }

    public function test_bloquea_si_la_funcion_esta_desactivada(): void
    {
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => false]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertDatabaseHas('ml_operaciones_log', ['operacion' => 'sincronizar_ordenes', 'resultado' => 'bloqueada']);
    }

    public function test_bloquea_si_el_modo_solo_lectura_esta_activo(): void
    {
        MercadoLibreConfiguracion::actual()->update(['modo_solo_lectura' => true]);

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('sólo lectura', $resultado['mensaje']);
    }

    public function test_bloquea_si_no_hay_cuenta_conectada(): void
    {
        MercadoLibreCuenta::query()->delete();

        $resultado = app(SincronizadorOrdenes::class)->ejecutar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('conectar', $resultado['mensaje']);
    }

    /** T087 (FR-034 de la spec 011, SC-007): ningún dato sensible llega al historial ni al payload. */
    public function test_ningun_dato_sensible_llega_al_historial_ni_al_payload(): void
    {
        $this->fakearBusqueda([$this->ordenCruda(1006)], []);

        app(SincronizadorOrdenes::class)->ejecutar();

        $orden = \App\Models\Integraciones\MercadoLibreOrden::where('ml_order_id', '1006')->firstOrFail();
        $payload = json_encode($orden->payload);
        $this->assertStringNotContainsString('atk-vigente', $payload);
        $this->assertStringNotContainsString('access_token', $payload);

        $log = \DB::table('ml_operaciones_log')->orderByDesc('id')->first();
        $this->assertStringNotContainsString('atk-vigente', json_encode($log));
    }

    public function test_acceso_denegado_sin_permiso_ventas_ver(): void
    {
        $sinPermiso = User::factory()->create();
        $this->actingAs($sinPermiso);

        $this->getJson(route('ingresos.mercadolibre.datatable'))->assertForbidden();
    }
}
