<?php

namespace Tests\Feature\Integraciones;

use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\User;
use App\Services\MercadoLibre\VinculadorAutomatico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 021 (reemplazo), US1: vinculación automática de Mercado Libre por SKU
 * del vendedor visto en órdenes ya sincronizadas contra el `id` de `productos`
 * (research.md R2, data-model.md). FR-001..FR-008.
 */
class MercadoLibreVinculacionAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private int $ordenId = 9000;

    private function crearItem(string $mlItemId, ?string $skuVendedor, ?string $variationId = null, ?string $titulo = 'Publicación'): MercadoLibreOrdenItem
    {
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => (string) $this->ordenId++,
            'estado_ml' => 'paid', 'estado_orden' => 'pagada', 'estado_conversion' => 'lista',
            'fecha_creada' => now(), 'total' => 100, 'moneda' => 'ARS', 'comprador_ml_id' => '1',
            'sincronizada_en' => now(),
        ]);

        return MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => $mlItemId, 'ml_variation_id' => $variationId,
            'titulo' => $titulo, 'sku_vendedor' => $skuVendedor,
            'cantidad' => 1, 'precio_unitario' => 100, 'total_linea' => 100,
        ]);
    }

    public function test_match_exacto_de_id_crea_el_vinculo(): void
    {
        $producto = Producto::factory()->create(['id' => 9010]);
        $this->crearItem('MLA1', '9010');

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['total']);
        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(0, $resumen['fallidas']);
        $this->assertDatabaseHas('ml_publicacion_producto', [
            'ml_item_id' => 'MLA1', 'producto_id' => $producto->id,
        ]);
    }

    public function test_sku_sin_match_deja_pendiente_con_motivo(): void
    {
        $this->crearItem('MLA1', '999999');

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('MLA1', $resumen['detalle_fallidas'][0]['referencia']);
        $this->assertSame('producto_no_encontrado', $resumen['detalle_fallidas'][0]['motivo']);
        $this->assertDatabaseCount('ml_publicacion_producto', 0);
    }

    public function test_publicacion_sin_sku_vendedor_tiene_motivo_distinto_a_sin_match(): void
    {
        $this->crearItem('MLA1', null);

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['fallidas']);
        $this->assertSame('sin_sku', $resumen['detalle_fallidas'][0]['motivo']);
    }

    public function test_dos_publicaciones_con_el_mismo_sku_solo_la_primera_se_vincula(): void
    {
        Producto::factory()->create(['id' => 9010]);
        // La más reciente (id más alto) es la primera en procesarse.
        $this->crearItem('MLA-VIEJA', '9010');
        $this->crearItem('MLA-NUEVA', '9010');

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertSame(1, $resumen['fallidas']);
        $this->assertDatabaseHas('ml_publicacion_producto', ['ml_item_id' => 'MLA-NUEVA', 'producto_id' => 9010]);
        $this->assertSame('MLA-VIEJA', $resumen['detalle_fallidas'][0]['referencia']);
        $this->assertSame('ya_vinculado', $resumen['detalle_fallidas'][0]['motivo']);
        $this->assertSame('producto', $resumen['detalle_fallidas'][0]['detalle']);
    }

    public function test_publicacion_con_variante_queda_excluida(): void
    {
        Producto::factory()->create(['id' => 9010]);
        $this->crearItem('MLA-VAR', '9010', variationId: '555');

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(0, $resumen['total']);
        $this->assertDatabaseCount('ml_publicacion_producto', 0);
    }

    public function test_producto_inactivo_se_vincula_igual(): void
    {
        $producto = Producto::factory()->create(['id' => 9010, 'activo' => false]);
        $this->crearItem('MLA1', '9010');

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(1, $resumen['vinculadas']);
        $this->assertDatabaseHas('ml_publicacion_producto', ['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
    }

    public function test_reintentar_la_corrida_no_modifica_lo_ya_vinculado(): void
    {
        Producto::factory()->create(['id' => 9010]);
        $this->crearItem('MLA1', '9010');

        app(VinculadorAutomatico::class)->ejecutar(auth()->user());
        $vinculo = MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA1')->first();

        $resumen = app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $this->assertSame(0, $resumen['total']);
        $this->assertSame(0, $resumen['vinculadas']);
        $this->assertDatabaseCount('ml_publicacion_producto', 1);
        $this->assertDatabaseHas('ml_publicacion_producto', ['id' => $vinculo->id, 'ml_item_id' => 'MLA1']);
    }

    public function test_el_vinculo_creado_queda_con_stock_y_precio_pendiente_en_su_default(): void
    {
        Producto::factory()->create(['id' => 9010]);
        $this->crearItem('MLA1', '9010');

        app(VinculadorAutomatico::class)->ejecutar(auth()->user());

        $vinculo = MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA1')->firstOrFail();
        $this->assertFalse($vinculo->stock_pendiente);
        $this->assertFalse($vinculo->precio_pendiente);
    }
}
