<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\ListaPrecio;
use App\Models\PrecioProducto;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regresión del incidente del 25/08/2026.
 *
 * Una importación masiva cambió la lista de precios general y `PrecioProductoObserver` le empujó
 * ese precio a **todas** las publicaciones del producto, incluidas las Premium (`gold_pro`), que
 * cotizan por la lista Premium. Resultado: 18 publicaciones quedaron publicadas un 31% por debajo
 * de su precio real durante 30 horas. No llegó a venderse ninguna, pero por rotación baja, no por
 * ninguna barrera del sistema.
 *
 * Lo que fija este test es que **cada lista sólo llega a las publicaciones que cotizan por ella**.
 * El caso peligroso es el primero: es silencioso —Mercado Libre acepta el precio más bajo sin
 * chistar— y sólo se descubre comparando contra la API.
 */
class PrecioProductoObserverPremiumTest extends TestCase
{
    use RefreshDatabase;

    private ListaPrecio $general;

    private ListaPrecio $premium;

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
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk-vigente', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);

        $this->general = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        $this->premium = ListaPrecio::create(['nombre' => 'ML Premium', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'lista_precio_id' => $this->general->id,
            'lista_precio_id_premium' => $this->premium->id,
        ]);

        Http::fake(['api.mercadolibre.com/*' => Http::response(['id' => 'MLA1'], 200)]);
    }

    /** Precios en las dos listas + una publicación de cada tipo sobre el mismo producto. */
    private function productoConDosPublicaciones(float $general, float $premium): Producto
    {
        $producto = Producto::factory()->create();

        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->general->id, 'precio' => $general]);
        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->premium->id, 'precio' => $premium]);

        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-CLASICA', 'producto_id' => $producto->id, 'listing_type_id' => 'gold_special',
        ]);
        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-PREMIUM', 'producto_id' => $producto->id, 'listing_type_id' => 'gold_pro',
        ]);

        return $producto;
    }

    /** Precio que se le mandó a una publicación, o `null` si no se le mandó nada. */
    private function precioEnviadoA(string $itemId): ?float
    {
        $precio = null;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), "/items/{$itemId}") && $request->method() === 'PUT') {
                $precio = $request->data()['price'] ?? null;
            }
        }

        return $precio === null ? null : (float) $precio;
    }

    public function test_cambiar_la_lista_general_no_le_toca_el_precio_a_una_publicacion_premium(): void
    {
        $producto = $this->productoConDosPublicaciones(general: 100_000, premium: 145_350);

        PrecioProducto::where('producto_id', $producto->id)
            ->where('lista_precio_id', $this->general->id)
            ->first()
            ->update(['precio' => 110_000]);

        $this->assertSame(110_000.0, $this->precioEnviadoA('MLA-CLASICA'),
            'La publicación Clásica tiene que recibir el precio nuevo de la lista general.');

        $this->assertNull($this->precioEnviadoA('MLA-PREMIUM'),
            'La publicación Premium NO puede recibir el precio de la lista general: es el bug del 25/08, '.
            'que la dejaría publicada un 31% por debajo de su precio real.');
    }

    public function test_cambiar_la_lista_premium_no_le_toca_el_precio_a_una_publicacion_clasica(): void
    {
        $producto = $this->productoConDosPublicaciones(general: 100_000, premium: 145_350);

        PrecioProducto::where('producto_id', $producto->id)
            ->where('lista_precio_id', $this->premium->id)
            ->first()
            ->update(['precio' => 160_000]);

        $this->assertSame(160_000.0, $this->precioEnviadoA('MLA-PREMIUM'));
        $this->assertNull($this->precioEnviadoA('MLA-CLASICA'));
    }

    /**
     * Sin precio en la lista Premium, `resolverListaPrecio()` cae a la general por diseño (spec 050,
     * FR-008). Es la misma consecuencia económica que el bug —una Premium publicada al precio
     * Clásico— pero acá es deliberado y la alternativa (no publicar precio) es peor. Queda fijado
     * para que el día que se decida cambiarlo sea una decisión y no un descuido.
     */
    public function test_una_premium_sin_precio_en_su_lista_cae_a_la_general_a_proposito(): void
    {
        $producto = Producto::factory()->create();

        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->general->id, 'precio' => 100_000]);

        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-PREMIUM', 'producto_id' => $producto->id, 'listing_type_id' => 'gold_pro',
        ]);

        PrecioProducto::where('producto_id', $producto->id)->first()->update(['precio' => 110_000]);

        $this->assertSame(110_000.0, $this->precioEnviadoA('MLA-PREMIUM'));
    }

    /**
     * **Esta aserción está invertida respecto de su versión original, y el cambio es deliberado.**
     *
     * Hasta la spec 084 este test afirmaba lo contrario: que un vínculo sin `listing_type_id`
     * recibía el precio de la lista general. Documentaba el comportamiento vigente y de paso el
     * agujero: si el vínculo resultaba ser Premium, quedaba publicado un 31% barato.
     *
     * La spec 084 (FR-029) cierra esa ventana — sin tipo conocido no se publica precio, se deja
     * pendiente— y por eso ahora se afirma lo opuesto. Si alguien lo vuelve a invertir "porque
     * antes andaba así", está reabriendo el agujero.
     */
    public function test_un_vinculo_sin_tipo_no_recibe_precio_y_queda_pendiente(): void
    {
        $producto = Producto::factory()->create();

        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->general->id, 'precio' => 100_000]);
        PrecioProducto::create(['producto_id' => $producto->id, 'lista_precio_id' => $this->premium->id, 'precio' => 145_350]);

        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-SIN-TIPO', 'producto_id' => $producto->id, 'listing_type_id' => null,
        ]);

        PrecioProducto::where('producto_id', $producto->id)
            ->where('lista_precio_id', $this->general->id)
            ->first()
            ->update(['precio' => 110_000]);

        $this->assertNull($this->precioEnviadoA('MLA-SIN-TIPO'),
            'Sin saber si es Premium o Clásica no se puede saber qué precio le corresponde.');

        $this->assertTrue(
            \App\Models\Integraciones\MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA-SIN-TIPO')
                ->value('precio_pendiente'),
            'Queda pendiente para cuando se conozca el tipo, no se pierde el cambio.',
        );
    }
}
