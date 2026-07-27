<?php

namespace Tests\Feature\Integraciones;

use App\Models\Integracion;
use App\Services\Integraciones\CanalRequiereReconexionException;
use App\Services\Integraciones\MercadoLibre\ClienteMercadoLibreHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * D10 — Las impl `*Http` renuevan el token vencido antes de llamar y lo
 * persisten; si la renovación falla, el canal pasa a `requiere_reconexion`
 * sin borrar histórico/mapeos.
 */
class RenovacionTokenTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    private function integracionConTokenVencido(): Integracion
    {
        return Integracion::create([
            'canal' => 'mercadolibre',
            'credenciales' => [
                'access_token' => 'viejo-token',
                'refresh_token' => 'refresh-valido',
                'expires_at' => now()->subHour()->toDateTimeString(),
                'cuenta_id' => 'seller-1',
            ],
            'estado' => 'conectado',
            'activo' => true,
        ]);
    }

    public function test_renueva_el_token_vencido_y_lo_persiste(): void
    {
        $integracion = $this->integracionConTokenVencido();

        Http::fake([
            '*/oauth/token' => Http::response([
                'access_token' => 'token-nuevo',
                'refresh_token' => 'refresh-nuevo',
                'expires_in' => 21600,
            ], 200),
            '*/orders/search*' => Http::response(['results' => []], 200),
        ]);

        $cliente = new ClienteMercadoLibreHttp;
        iterator_to_array($cliente->traerOrdenes($integracion, null));

        $integracion->refresh();
        $this->assertSame('token-nuevo', $integracion->credenciales['access_token']);
        $this->assertSame('refresh-nuevo', $integracion->credenciales['refresh_token']);
        $this->assertSame('conectado', $integracion->estado);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/oauth/token');
        });
    }

    public function test_si_la_renovacion_falla_el_canal_queda_en_requiere_reconexion_sin_perder_datos(): void
    {
        $integracion = $this->integracionConTokenVencido();

        Http::fake([
            '*/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $cliente = new ClienteMercadoLibreHttp;

        $this->expectException(CanalRequiereReconexionException::class);

        try {
            iterator_to_array($cliente->traerOrdenes($integracion, null));
        } finally {
            $integracion->refresh();
            $this->assertSame('requiere_reconexion', $integracion->estado);
            // El histórico de credenciales previo no se borra (sólo cambia el estado).
            $this->assertNotNull($integracion->credenciales);
        }
    }
}
