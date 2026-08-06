<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * US3 (spec 050): comando `mercadolibre:sincronizar-tipos-publicacion` —
 * backfill, tolerancia a fallos de la API, e intervalo fijo de 24hs.
 */
class MercadoLibreSincronizarTiposPublicacionTest extends TestCase
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
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
            'modo_solo_lectura' => false,
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
    }

    private function crearVinculo(string $mlItemId): MercadoLibrePublicacionProducto
    {
        $producto = Producto::factory()->create();

        return MercadoLibrePublicacionProducto::create(['ml_item_id' => $mlItemId, 'producto_id' => $producto->id]);
    }

    /** T016a: el backfill completa listing_type_id de vínculos existentes. */
    public function test_backfill_completa_listing_type_id_de_vinculos_existentes(): void
    {
        $vinculo = $this->crearVinculo('MLA1');

        Http::fake([
            'api.mercadolibre.com/items*' => Http::response([
                ['code' => 200, 'body' => ['id' => 'MLA1', 'listing_type_id' => 'gold_pro']],
            ], 200),
        ]);

        $this->artisan('mercadolibre:sincronizar-tipos-publicacion --forzar')->assertSuccessful();

        $vinculo->refresh();
        $this->assertSame('gold_pro', $vinculo->listing_type_id);
        $this->assertNotNull($vinculo->listing_type_sincronizado_en);
        $this->assertNotNull(MercadoLibreConfiguracion::actual()->tipo_publicacion_ultima_sync_en);
    }

    /** T016b: si la API falla, se conserva el último valor conocido sin romper la corrida. */
    public function test_falla_de_la_api_conserva_el_ultimo_valor_conocido(): void
    {
        $vinculo = $this->crearVinculo('MLA1');
        $vinculo->update(['listing_type_id' => 'gold_special', 'listing_type_sincronizado_en' => now()->subDays(2)]);

        Http::fake(['api.mercadolibre.com/items*' => Http::response([], 500)]);

        $this->artisan('mercadolibre:sincronizar-tipos-publicacion --forzar')->assertSuccessful();

        $this->assertSame('gold_special', $vinculo->fresh()->listing_type_id);
    }

    /** T016c: sin --forzar, no vuelve a golpear la API si no pasaron 24hs desde la última corrida. */
    public function test_sin_forzar_no_repite_la_corrida_antes_de_24hs(): void
    {
        MercadoLibreConfiguracion::actual()->update(['tipo_publicacion_ultima_sync_en' => now()->subHours(2)]);
        $this->crearVinculo('MLA1');

        Http::fake();

        $this->artisan('mercadolibre:sincronizar-tipos-publicacion')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** T016d: con --forzar, ignora esa comparación e igual golpea la API. */
    public function test_con_forzar_ignora_el_intervalo(): void
    {
        MercadoLibreConfiguracion::actual()->update(['tipo_publicacion_ultima_sync_en' => now()->subHours(2)]);
        $this->crearVinculo('MLA1');

        Http::fake(['api.mercadolibre.com/items*' => Http::response([
            ['code' => 200, 'body' => ['id' => 'MLA1', 'listing_type_id' => 'gold_pro']],
        ], 200)]);

        $this->artisan('mercadolibre:sincronizar-tipos-publicacion --forzar')->assertSuccessful();

        Http::assertSentCount(1);
    }
}
