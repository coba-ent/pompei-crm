<?php

namespace Tests\Feature\Integraciones;

use App\Models\Deposito;
use App\Models\Integracion;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\ProductoCanal;
use App\Models\Rol;
use App\Models\User;
use App\Services\Integraciones\ProvisionadorTiendaNube;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US1 — Conectar y gestionar un canal (FR-001..007, SC-001/SC-007/SC-008).
 * Usa las impl Fake de los clientes de canal (Principio IV, sin red).
 */
class ConectarCanalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);

        $this->actingAs($user);
    }

    private function iniciarYCompletarOAuth(string $canal, string $code = 'code-1'): \Illuminate\Testing\TestResponse
    {
        $respuestaConectar = $this->get(route('integraciones.conectar', $canal));
        $respuestaConectar->assertRedirect();

        $ubicacion = $respuestaConectar->headers->get('Location');
        parse_str((string) parse_url($ubicacion, PHP_URL_QUERY), $query);

        return $this->get(route('integraciones.callback', $canal).'?code='.$code.'&state='.$query['state']);
    }

    public function test_conectar_tiendanube_crea_fila_unica_provisiona_lista_y_deposito(): void
    {
        $respuesta = $this->iniciarYCompletarOAuth('tiendanube');

        $respuesta->assertRedirect(route('integraciones.index'));
        $this->assertSame(1, Integracion::where('canal', 'tiendanube')->count());

        $integracion = Integracion::where('canal', 'tiendanube')->first();
        $this->assertSame('conectado', $integracion->estado);
        $this->assertTrue($integracion->activo);

        $this->assertNotNull($integracion->config['lista_precio_id'] ?? null);
        $this->assertNotNull($integracion->config['deposito_id'] ?? null);
        $this->assertTrue(ListaPrecio::where('nombre', ProvisionadorTiendaNube::NOMBRE_LISTA)->exists());
        $this->assertTrue(Deposito::where('nombre', ProvisionadorTiendaNube::NOMBRE_DEPOSITO)->exists());
    }

    public function test_credenciales_nunca_aparecen_en_la_respuesta_ni_en_serializacion(): void
    {
        $this->iniciarYCompletarOAuth('tiendanube');

        $integracion = Integracion::where('canal', 'tiendanube')->first();
        $arreglo = $integracion->toArray();

        $this->assertArrayNotHasKey('credenciales', $arreglo);

        $respuestaIndex = $this->get(route('integraciones.index'));
        $respuestaIndex->assertOk();
        $respuestaIndex->assertDontSee('access_token');
        $respuestaIndex->assertDontSee('refresh_token');
    }

    public function test_reconectar_un_canal_ya_existente_no_duplica_la_fila(): void
    {
        $this->iniciarYCompletarOAuth('mercadolibre', 'code-1');
        $this->iniciarYCompletarOAuth('mercadolibre', 'code-2');

        $this->assertSame(1, Integracion::where('canal', 'mercadolibre')->count());
    }

    public function test_desconectar_conserva_mapeos_e_historico(): void
    {
        $this->iniciarYCompletarOAuth('tiendanube');
        $integracion = Integracion::where('canal', 'tiendanube')->first();

        $producto = Producto::factory()->create();
        ProductoCanal::create([
            'integracion_id' => $integracion->id,
            'producto_id' => $producto->id,
            'id_externo' => 'ext-1',
            'sku_externo' => $producto->codigo,
            'sincronizable' => true,
        ]);

        $respuesta = $this->post(route('integraciones.desconectar', 'tiendanube'));
        $respuesta->assertOk()->assertJson(['ok' => true]);

        $integracion->refresh();
        $this->assertFalse($integracion->activo);
        $this->assertSame('desconectado', $integracion->estado);
        $this->assertSame(1, ProductoCanal::where('integracion_id', $integracion->id)->count());
    }
}
