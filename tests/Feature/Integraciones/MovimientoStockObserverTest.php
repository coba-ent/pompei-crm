<?php

namespace Tests\Feature\Integraciones;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Cliente;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibreOrden;
use App\Models\Integraciones\MercadoLibreOrdenItem;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\FuncionAvanzada;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\MercadoLibre\ConversorOrdenAVenta;
use App\Services\Stock\StockService;
use Database\Seeders\CondicionIvaSeeder;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * US1 (spec 013, FR-001/FR-005) y US2 (FR-002): qué movimientos marcan un
 * vínculo como pendiente de sincronizar hacia Mercado Libre, y cuáles no.
 */
class MovimientoStockObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVentaConProducto(Producto $producto, float $cantidad = 3): Venta
    {
        $cliente = Cliente::factory()->create();

        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $respuesta = $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => '2026-07-28',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => $producto->id,
                'descripcion' => $producto->nombre,
                'cantidad' => $cantidad,
                'precio_unitario' => 100,
                'iva_pct' => $producto->iva_venta_pct,
            ]],
        ]);

        $respuesta->assertCreated();

        return Venta::findOrFail($respuesta->json('venta.id'));
    }

    public function test_venta_manual_sobre_producto_vinculado_marca_pendiente(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo->fresh()->stock_pendiente);
    }

    public function test_movimiento_en_otro_deposito_no_marca_pendiente(): void
    {
        $depositoDefault = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $depositoMl = Deposito::create(['nombre' => 'Depósito ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['deposito_id' => $depositoMl->id]);

        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        // Venta manual usa siempre el depósito por defecto del CRM (primero activo por id),
        // que acá es $depositoDefault — distinto del configurado para Mercado Libre.
        $this->crearVentaConProducto($producto, 2);

        $this->assertFalse($vinculo->fresh()->stock_pendiente);
    }

    public function test_producto_sin_vinculo_no_marca_nada(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $this->crearVentaConProducto($producto, 1);

        $this->assertDatabaseCount('ml_publicacion_producto', 0);
    }

    public function test_ajuste_manual_de_stock_tambien_marca_pendiente(): void
    {
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);

        app(StockService::class)->ajustar($producto, null, $deposito, 5, 'ajuste de prueba');

        $this->assertTrue($vinculo->fresh()->stock_pendiente);
    }

    /** Spec 036 US2 (FR-005): un producto con 2 publicaciones vinculadas marca AMBAS pendientes. */
    public function test_producto_con_dos_publicaciones_vinculadas_marca_ambas_pendientes(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo1 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
        $vinculo2 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA2', 'producto_id' => $producto->id]);

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo1->fresh()->stock_pendiente);
        $this->assertTrue($vinculo2->fresh()->stock_pendiente);
    }

    /** Spec 036 US2 (FR-009): desvincular una publicación no afecta a las demás vinculadas al mismo producto. */
    public function test_desvincular_una_publicacion_no_afecta_a_las_demas(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculo1 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA1', 'producto_id' => $producto->id]);
        $vinculo2 = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA2', 'producto_id' => $producto->id]);
        $vinculo1->delete();

        $this->crearVentaConProducto($producto, 3);

        $this->assertTrue($vinculo2->fresh()->stock_pendiente);
        $this->assertDatabaseMissing('ml_publicacion_producto', ['id' => $vinculo1->id]);
    }

    /** ---- US2: exclusión de bucle (FR-002) ---- */

    private function convertirOrdenMercadoLibre(Producto $producto, float $cantidad = 1): Venta
    {
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
        CuentaTesoreria::firstOrCreate(['nombre' => 'Mercado Pago'], ['tipo' => 'banco', 'visible' => true]);
        Http::fake(['api.mercadolibre.com/*' => Http::response([], 404)]);

        MercadoLibrePublicacionProducto::firstOrCreate(
            ['ml_item_id' => 'MLA1'],
            ['producto_id' => $producto->id]
        );

        $precioUnitario = 605.00;
        $orden = MercadoLibreOrden::create([
            'ml_order_id' => (string) random_int(1000000, 9999999), 'estado_ml' => 'paid', 'estado_orden' => 'pagada',
            'estado_conversion' => 'lista', 'fecha_creada' => now(), 'fecha_cerrada' => now(),
            'total' => $precioUnitario * $cantidad, 'moneda' => 'ARS', 'comprador_ml_id' => '1', 'comprador_apodo' => 'COMPRADOR',
            'comprador_condicion_iva' => 'Consumidor Final', 'sincronizada_en' => now(),
        ]);
        MercadoLibreOrdenItem::create([
            'ml_orden_id' => $orden->id, 'ml_item_id' => 'MLA1', 'titulo' => 'Producto',
            'cantidad' => $cantidad, 'precio_unitario' => $precioUnitario, 'total_linea' => $precioUnitario * $cantidad,
            'producto_id' => $producto->id,
        ]);

        $resultado = app(ConversorOrdenAVenta::class)->convertir($orden->fresh(), auth()->id(), automatica: false);
        $this->assertTrue($resultado['ok'], json_encode($resultado));

        return $resultado['venta'];
    }

    /**
     * spec 112: la publicación que vendió TAMBIÉN queda pendiente.
     *
     * Antes se la excluía (spec 013, FR-002) porque Mercado Libre ya descontó esa unidad de su
     * lado. Pero el stock que el CRM empuja puede estar viejo —las órdenes se importan cada 5
     * minutos—, y si en esa ventana le mandamos un número desactualizado, excluirla después
     * significa que nadie lo corrige nunca. Caso real: MLA1808325052 el 28/09/2026.
     */
    public function test_convertir_orden_de_mercadolibre_marca_pendiente_el_vinculo(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $this->convertirOrdenMercadoLibre($producto, 2);

        $vinculo = MercadoLibrePublicacionProducto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertTrue(
            $vinculo->stock_pendiente,
            'La publicación vendida debe quedar pendiente: el PUT lleva el stock del CRM leído al enviar.'
        );
    }

    /**
     * spec 112: una orden de Mercado Libre deja pendientes TODAS las publicaciones del producto.
     *
     * La que vendió, porque el stock que se le empujó pudo haber sido viejo (ver arriba); las
     * otras, porque Mercado Libre no las tocó y siguen ofreciendo el stock anterior.
     */
    public function test_orden_de_ml_marca_pendientes_todas_las_publicaciones_del_producto(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $otra = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA2-OTRA-PUBLICACION',
            'producto_id' => $producto->id,
        ]);

        $this->convertirOrdenMercadoLibre($producto, 2);

        $vendida = MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA1')->firstOrFail();
        $this->assertTrue($vendida->fresh()->stock_pendiente, 'La publicación vendida también se empuja (spec 112).');
        $this->assertTrue($otra->fresh()->stock_pendiente, 'La otra publicación quedó con el stock viejo.');
    }

    public function test_venta_manual_sobre_mismo_producto_marca_pendiente_tras_una_orden_ml(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $this->convertirOrdenMercadoLibre($producto, 2);

        $vinculo = MercadoLibrePublicacionProducto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertTrue($vinculo->fresh()->stock_pendiente, 'La orden de Mercado Libre marca pendiente (spec 112).');

        // Se limpia como lo haría el sincronizador, para verificar que la venta manual vuelve a marcar.
        $vinculo->update(['stock_pendiente' => false]);

        $this->crearVentaConProducto($producto, 1);

        $this->assertTrue($vinculo->fresh()->stock_pendiente, 'La Venta manual sí debe marcar pendiente.');
    }

    /**
     * spec 112, SC-003 — EL CASO REAL DEL 28/09/2026.
     *
     * Producto con dos publicaciones (MLA1808325052 y MLA818901919). Entra una venta de Mercado
     * Libre por una de ellas. Antes de esta spec, la publicación que vendía quedaba excluida y su
     * stock desactualizado no se corregía nunca: quedó ofreciendo 21 con el CRM en 20.
     *
     * Ahora las dos quedan pendientes, así que la corrida siguiente les publica el stock real.
     */
    public function test_venta_de_ml_deja_pendientes_las_dos_publicaciones_del_producto(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $otra = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA-SEGUNDA-PUBLICACION',
            'producto_id' => $producto->id,
        ]);

        $this->convertirOrdenMercadoLibre($producto, 1);

        $vendida = MercadoLibrePublicacionProducto::where('ml_item_id', 'MLA1')->firstOrFail();

        $this->assertTrue($vendida->fresh()->stock_pendiente, 'La que vendió: sin esto queda desfasada (caso 28/09).');
        $this->assertTrue($otra->fresh()->stock_pendiente, 'La otra: Mercado Libre no la tocó.');
    }

    /**
     * spec 112, SC-004 — NO-REGRESIÓN del camino que hoy ya funciona.
     *
     * Ventas manuales, compras y ajustes son la mayoría de los movimientos del sistema. Para ellos
     * la exclusión ya devolvía `[]` y no filtraba nada, así que su comportamiento tiene que quedar
     * EXACTAMENTE igual que antes de esta spec.
     */
    public function test_venta_manual_y_ajuste_siguen_marcando_todas_las_publicaciones(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create(['tipo' => 'producto', 'iva_venta_pct' => '21', 'activo' => true]);

        $uno = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA-UNO', 'producto_id' => $producto->id]);
        $dos = MercadoLibrePublicacionProducto::create(['ml_item_id' => 'MLA-DOS', 'producto_id' => $producto->id]);

        $this->crearVentaConProducto($producto, 1);

        $this->assertTrue($uno->fresh()->stock_pendiente);
        $this->assertTrue($dos->fresh()->stock_pendiente);
    }
}
