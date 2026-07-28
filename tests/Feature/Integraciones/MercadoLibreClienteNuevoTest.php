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
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\MercadoLibre\SincronizadorOrdenes;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aviso NO bloqueante de "Cliente nuevo" (decisión del usuario, 28/07/2026).
 *
 * La distinción que se prueba acá: una publicación sin vincular BLOQUEA la
 * conversión (afecta stock y plata), mientras que un comprador que todavía no
 * existe como Cliente sólo AVISA — la orden sigue siendo convertible y el
 * Cliente se da de alta solo.
 */
class MercadoLibreClienteNuevoTest extends TestCase
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
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value, 'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        CuentaTesoreria::create(['nombre' => 'Mercado Pago', 'tipo' => 'banco', 'visible' => true]);

        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);
        MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
    }

    private function ordenCruda(int $id, int $compradorId = 999, string $apodo = 'COMPRADOR'): array
    {
        return [
            'id' => $id, 'status' => 'paid',
            'date_created' => now()->toIso8601String(), 'date_closed' => now()->toIso8601String(),
            'total_amount' => 1210.0, 'currency_id' => 'ARS',
            'buyer' => ['id' => $compradorId, 'nickname' => $apodo],
            'tags' => ['paid'],
            'order_items' => [[
                'item' => ['id' => 'MLA1', 'title' => 'Producto', 'variation_id' => null],
                'quantity' => 1, 'unit_price' => 1210.0,
            ]],
        ];
    }

    private function sincronizar(array $ordenes): void
    {
        Http::fake(function ($request) use ($ordenes) {
            if (str_contains($request->url(), 'order.status=cancelled')) {
                return Http::response(['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]], 200);
            }

            return Http::response(['results' => $ordenes, 'paging' => ['total' => count($ordenes), 'offset' => 0, 'limit' => 50]], 200);
        });

        app(SincronizadorOrdenes::class)->ejecutar();
    }

    public function test_comprador_desconocido_marca_cliente_nuevo_pero_NO_bloquea_la_conversion(): void
    {
        $this->sincronizar([$this->ordenCruda(2001)]);

        $orden = MercadoLibreOrden::where('ml_order_id', '2001')->firstOrFail();

        $this->assertTrue($orden->cliente_nuevo, 'Debe avisar que el comprador no existe como Cliente.');
        $this->assertSame(EstadoConversion::Lista, $orden->estado_conversion, 'El aviso NO debe bloquear la conversión.');
        $this->assertNull($orden->motivo, 'No es un motivo de bloqueo, es sólo un aviso.');
    }

    public function test_comprador_ya_existente_no_marca_el_aviso(): void
    {
        Cliente::factory()->create(['ml_user_id' => 999]);

        $this->sincronizar([$this->ordenCruda(2002)]);

        $orden = MercadoLibreOrden::where('ml_order_id', '2002')->firstOrFail();

        $this->assertFalse($orden->cliente_nuevo);
        $this->assertSame(EstadoConversion::Lista, $orden->estado_conversion);
    }

    public function test_comprador_emparejado_por_apodo_tampoco_marca_el_aviso(): void
    {
        Cliente::factory()->create(['ml_user_id' => null, 'apodo_ml' => 'COMPRADOR']);

        $this->sincronizar([$this->ordenCruda(2003)]);

        $orden = MercadoLibreOrden::where('ml_order_id', '2003')->firstOrFail();

        $this->assertFalse($orden->cliente_nuevo, 'Si engancha por apodo, el Cliente ya existe.');
    }

    /**
     * La ambigüedad sí bloquea (FR-038) y no debe confundirse con el aviso:
     * son dos cosas distintas y excluyentes.
     */
    public function test_cliente_ambiguo_bloquea_y_no_se_marca_como_cliente_nuevo(): void
    {
        Cliente::factory()->create(['ml_user_id' => null, 'apodo_ml' => 'COMPRADOR']);
        Cliente::factory()->create(['ml_user_id' => null, 'apodo_ml' => 'COMPRADOR']);

        $this->sincronizar([$this->ordenCruda(2004)]);

        $orden = MercadoLibreOrden::where('ml_order_id', '2004')->firstOrFail();

        $this->assertFalse($orden->cliente_nuevo);
        $this->assertSame(EstadoConversion::RequiereAtencion, $orden->estado_conversion);
        $this->assertSame('cliente_ambiguo', $orden->motivo->value);
    }

    /**
     * El contraste que pidió el usuario: el producto sin vincular SÍ bloquea,
     * aunque el comprador sea nuevo. El bloqueo manda sobre el aviso.
     */
    public function test_publicacion_sin_vincular_bloquea_aunque_el_cliente_sea_nuevo(): void
    {
        $orden = $this->ordenCruda(2005);
        $orden['order_items'][0]['item']['id'] = 'MLA-NO-VINCULADA';

        $this->sincronizar([$orden]);

        $guardada = MercadoLibreOrden::where('ml_order_id', '2005')->firstOrFail();

        $this->assertTrue($guardada->cliente_nuevo, 'El aviso se marca igual.');
        $this->assertSame(EstadoConversion::RequiereAtencion, $guardada->estado_conversion);
        $this->assertSame('publicacion_sin_vincular', $guardada->motivo->value);
    }
}
