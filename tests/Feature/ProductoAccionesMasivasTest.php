<?php

namespace Tests\Feature;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\TipoProducto;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductoAccionesMasivasTest extends TestCase
{
    use RefreshDatabase;

    public function test_precio_venta_ajusta_por_porcentaje_sobre_el_valor_actual_de_cada_producto(): void
    {
        $p1 = Producto::create(['nombre' => 'P1', 'tipo' => 'producto', 'precio_venta' => 100]);
        $p2 = Producto::create(['nombre' => 'P2', 'tipo' => 'producto', 'precio_venta' => 200]);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'porcentaje', 'redondear' => false,
            'campos' => ['precio_venta' => ['valor' => 10, 'signo' => 'aumentar']],
            'todos' => false, 'ids' => [$p1->id, $p2->id],
        ])->assertOk()->assertJson(['ok' => true, 'actualizados' => 2]);

        $this->assertDatabaseHas('productos', ['id' => $p1->id, 'precio_venta' => 110]);
        $this->assertDatabaseHas('productos', ['id' => $p2->id, 'precio_venta' => 220]);
    }

    public function test_precio_venta_ajusta_por_valor_fijo_disminuyendo_y_redondea(): void
    {
        $p1 = Producto::create(['nombre' => 'P1', 'tipo' => 'producto', 'precio_venta' => 100.40]);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'fijo', 'redondear' => true,
            'campos' => ['precio_venta' => ['valor' => 20.9, 'signo' => 'disminuir']],
            'todos' => false, 'ids' => [$p1->id],
        ])->assertOk()->assertJson(['ok' => true, 'actualizados' => 1]);

        // 100.40 - 20.9 = 79.5 -> redondeado al entero = 80.
        $this->assertDatabaseHas('productos', ['id' => $p1->id, 'precio_venta' => 80]);
    }

    public function test_precio_venta_tambien_ajusta_las_listas_de_precio_seleccionadas(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'Mayorista', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'P1', 'tipo' => 'producto', 'precio_venta' => 100]);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 50]);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'porcentaje', 'redondear' => false,
            'campos' => [
                'precio_venta' => ['valor' => 10, 'signo' => 'aumentar'],
                'lista_'.$lista->id => ['valor' => 10, 'signo' => 'aumentar'],
            ],
            'todos' => false, 'ids' => [$producto->id],
        ])->assertOk()->assertJson(['ok' => true, 'actualizados' => 1]);

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'precio_venta' => 110]);
        $this->assertDatabaseHas('precios_producto', ['producto_id' => $producto->id, 'lista_precio_id' => $lista->id, 'precio' => 55]);
    }

    public function test_precio_venta_no_baja_de_cero_y_rechaza_payload_sin_campos(): void
    {
        $producto = Producto::create(['nombre' => 'P1', 'tipo' => 'producto', 'precio_venta' => 10]);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'fijo', 'redondear' => false,
            'campos' => ['precio_venta' => ['valor' => 100, 'signo' => 'disminuir']],
            'todos' => false, 'ids' => [$producto->id],
        ])->assertOk();
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'precio_venta' => 0]);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'fijo', 'campos' => [],
            'todos' => false, 'ids' => [$producto->id],
        ])->assertStatus(422);
    }

    public function test_costo_ajusta_por_porcentaje_sobre_el_valor_actual(): void
    {
        $p1 = Producto::create(['nombre' => 'P1', 'tipo' => 'producto', 'costo' => 50]);
        $p2 = Producto::create(['nombre' => 'P2', 'tipo' => 'producto', 'costo' => 10]);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'costo', 'modo' => 'porcentaje', 'redondear' => false,
            'campos' => ['costo' => ['valor' => 20, 'signo' => 'aumentar']],
            'todos' => false, 'ids' => [$p1->id, $p2->id],
        ])->assertOk()->assertJson(['ok' => true, 'actualizados' => 2]);

        $this->assertDatabaseHas('productos', ['id' => $p1->id, 'costo' => 60]);
        $this->assertDatabaseHas('productos', ['id' => $p2->id, 'costo' => 12]);
    }

    public function test_eliminar_masivo_omite_los_que_tienen_operaciones_asociadas(): void
    {
        $conOperaciones = Producto::create(['nombre' => 'Con movimientos', 'tipo' => 'producto']);
        $sinOperaciones = Producto::create(['nombre' => 'Sin movimientos', 'tipo' => 'producto']);
        $deposito = Deposito::create(['nombre' => 'Principal']);

        $this->postJson(route('productos.stock.ajuste', $conOperaciones), [
            'deposito_id' => $deposito->id, 'operacion' => 'aumento', 'cantidad' => 5,
        ])->assertOk();

        $response = $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'eliminar', 'todos' => false, 'ids' => [$conOperaciones->id, $sinOperaciones->id],
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'eliminados' => 1]);
        $response->assertJsonPath('no_eliminados.0.id', $conOperaciones->id);
        $response->assertJsonPath('no_eliminados.0.motivo', 'tiene operaciones asociadas');

        $this->assertDatabaseHas('productos', ['id' => $conOperaciones->id]);
        $this->assertDatabaseMissing('productos', ['id' => $sinOperaciones->id]);
    }

    public function test_eliminar_masivo_solo_con_producto_protegido_no_elimina_nada(): void
    {
        $producto = Producto::create(['nombre' => 'Con movimientos', 'tipo' => 'producto']);
        $deposito = Deposito::create(['nombre' => 'Principal']);

        $this->postJson(route('productos.stock.ajuste', $producto), [
            'deposito_id' => $deposito->id, 'operacion' => 'aumento', 'cantidad' => 5,
        ])->assertOk();

        $response = $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'eliminar', 'todos' => false, 'ids' => [$producto->id],
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'eliminados' => 0]);
        $this->assertCount(1, $response->json('no_eliminados'));
        $this->assertDatabaseHas('productos', ['id' => $producto->id]);
    }

    public function test_modo_todos_resuelve_el_mismo_conjunto_que_los_filtros(): void
    {
        $activo1 = Producto::create(['nombre' => 'Activo 1', 'tipo' => 'producto', 'activo' => true, 'precio_venta' => 100]);
        $activo2 = Producto::create(['nombre' => 'Activo 2', 'tipo' => 'producto', 'activo' => true, 'precio_venta' => 100]);
        $inactivo = Producto::create(['nombre' => 'Inactivo', 'tipo' => 'producto', 'activo' => false, 'precio_venta' => 100]);

        $response = $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'fijo', 'redondear' => false,
            'campos' => ['precio_venta' => ['valor' => 5, 'signo' => 'aumentar']],
            'todos' => true, 'filtros' => ['estado' => 'activos'],
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'actualizados' => 2]);
        $this->assertDatabaseHas('productos', ['id' => $activo1->id, 'precio_venta' => 105]);
        $this->assertDatabaseHas('productos', ['id' => $activo2->id, 'precio_venta' => 105]);
        $this->assertDatabaseHas('productos', ['id' => $inactivo->id, 'precio_venta' => 100]);
    }

    public function test_iva_actualiza_venta_y_compra_de_forma_independiente(): void
    {
        $producto = Producto::create(['nombre' => 'P1', 'tipo' => 'producto', 'iva_venta_pct' => '21', 'iva_compra_pct' => '21']);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'iva', 'valor_venta' => '10.5', 'todos' => false, 'ids' => [$producto->id],
        ])->assertOk()->assertJson(['ok' => true, 'actualizados' => 1]);

        // Sólo IVA Venta cambió; IVA Compra quedó igual porque no se envió valor_compra.
        $this->assertDatabaseHas('productos', [
            'id' => $producto->id, 'iva_venta_pct' => '10.5', 'iva_compra_pct' => '21',
        ]);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'iva', 'valor_compra' => 'exento', 'todos' => false, 'ids' => [$producto->id],
        ])->assertOk();
        $this->assertDatabaseHas('productos', [
            'id' => $producto->id, 'iva_venta_pct' => '10.5', 'iva_compra_pct' => 'exento',
        ]);
    }

    public function test_iva_rechaza_valor_invalido_y_payload_sin_ningun_valor(): void
    {
        $producto = Producto::create(['nombre' => 'P1', 'tipo' => 'producto']);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'iva', 'valor_venta' => 'no-existe', 'todos' => false, 'ids' => [$producto->id],
        ])->assertStatus(422);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'iva', 'todos' => false, 'ids' => [$producto->id],
        ])->assertStatus(422);
    }

    public function test_tipo_producto_aplica_por_separado_segun_tipo_producto_o_servicio(): void
    {
        $tipoA = TipoProducto::create(['nombre' => 'Compra y Venta', 'activo' => true]);
        $tipoB = TipoProducto::create(['nombre' => 'Consignación', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Producto', 'tipo' => 'producto']);
        $servicio = Producto::create(['nombre' => 'Servicio', 'tipo' => 'servicio']);

        $response = $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'tipo_producto_id', 'valor_producto' => $tipoA->id, 'valor_servicio' => $tipoB->id,
            'todos' => false, 'ids' => [$producto->id, $servicio->id],
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'actualizados' => 2]);
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'tipo_producto_id' => $tipoA->id]);
        $this->assertDatabaseHas('productos', ['id' => $servicio->id, 'tipo_producto_id' => $tipoB->id]);
    }

    public function test_tipo_producto_con_solo_un_valor_deja_intacto_el_otro_tipo(): void
    {
        $tipoA = TipoProducto::create(['nombre' => 'Compra y Venta', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Producto', 'tipo' => 'producto']);
        $servicio = Producto::create(['nombre' => 'Servicio', 'tipo' => 'servicio', 'tipo_producto_id' => null]);

        $response = $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'tipo_producto_id', 'valor_producto' => $tipoA->id,
            'todos' => false, 'ids' => [$producto->id, $servicio->id],
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'actualizados' => 1]);
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'tipo_producto_id' => $tipoA->id]);
        $this->assertDatabaseHas('productos', ['id' => $servicio->id, 'tipo_producto_id' => null]);
    }

    public function test_sin_accion_elegida_devuelve_422(): void
    {
        $producto = Producto::create(['nombre' => 'P1', 'tipo' => 'producto']);

        $response = $this->postJson(route('productos.acciones-masivas'), [
            'todos' => false, 'ids' => [$producto->id],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('accion');
    }

    /**
     * La pantalla reintenta una tanda si se corta la conexión, y el corte puede llegar después
     * del COMMIT: con la misma `corrida`, el aumento no se aplica dos veces.
     */
    public function test_reintentar_una_tanda_de_la_misma_corrida_no_aplica_el_aumento_dos_veces(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'P1', 'tipo' => 'producto', 'precio_venta' => 100]);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 200]);

        $tanda = [
            'accion' => 'precio_venta', 'modo' => 'porcentaje', 'redondear' => false,
            'campos' => [
                'precio_venta' => ['valor' => 10, 'signo' => 'aumentar'],
                'lista_'.$lista->id => ['valor' => 10, 'signo' => 'aumentar'],
            ],
            'todos' => false, 'ids' => [$producto->id], 'corrida' => 'corrida-de-prueba',
        ];

        $this->postJson(route('productos.acciones-masivas'), $tanda)->assertOk()->assertJson(['actualizados' => 1, 'ya_aplicados' => 0]);
        $this->postJson(route('productos.acciones-masivas'), $tanda)->assertOk()->assertJson(['actualizados' => 0, 'ya_aplicados' => 1]);

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'precio_venta' => 110]);
        $this->assertDatabaseHas('precios_producto', ['producto_id' => $producto->id, 'lista_precio_id' => $lista->id, 'precio' => 220]);

        // Una corrida nueva (otra edición masiva) sí vuelve a aplicar.
        $this->postJson(route('productos.acciones-masivas'), ['corrida' => 'otra-corrida'] + $tanda)->assertOk();
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'precio_venta' => 121]);
    }

    public function test_porcentaje_sobre_un_producto_sin_precio_en_la_lista_no_crea_un_precio_cero(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        $sinPrecio = Producto::create(['nombre' => 'Sin precio', 'tipo' => 'producto']);

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'porcentaje',
            'campos' => ['lista_'.$lista->id => ['valor' => 10, 'signo' => 'aumentar']],
            'ids' => [$sinPrecio->id],
        ])->assertOk();

        $this->assertDatabaseMissing('precios_producto', ['producto_id' => $sinPrecio->id]);

        // Un valor fijo sí crea el precio, como antes.
        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'fijo',
            'campos' => ['lista_'.$lista->id => ['valor' => 500, 'signo' => 'aumentar']],
            'ids' => [$sinPrecio->id],
        ])->assertOk();

        $this->assertDatabaseHas('precios_producto', ['producto_id' => $sinPrecio->id, 'lista_precio_id' => $lista->id, 'precio' => 500]);
    }

    /**
     * Los envíos a Mercado Libre no salen dentro del pedido que guarda los precios (un PUT por
     * publicación colgaba la pantalla): el vínculo queda pendiente y se manda en la segunda fase.
     */
    public function test_el_envio_a_mercado_libre_sale_en_la_segunda_fase_y_no_al_guardar(): void
    {
        (new FuncionAvanzadaSeeder)->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012', 'client_secret' => 'clave-secreta-de-prueba-32chars', 'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Http::fake(['api.mercadolibre.com/items/*' => Http::response(['id' => 'MLA1'], 200)]);

        $listaMl = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        $otraLista = ListaPrecio::create(['nombre' => 'Mayorista', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $listaMl->id]);

        $producto = Producto::create(['nombre' => 'Producto ML', 'tipo' => 'producto']);
        $producto->precios()->create(['lista_precio_id' => $listaMl->id, 'precio' => 1000]);
        $producto->precios()->create(['lista_precio_id' => $otraLista->id, 'precio' => 800]);
        $vinculo = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA1', 'producto_id' => $producto->id,
            'listing_type_id' => MercadoLibrePublicacionProducto::TIPO_CLASICA,
        ]);
        Http::assertNothingSent();

        // Un cambio sólo en otra lista no toca a Mercado Libre.
        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'porcentaje',
            'campos' => ['lista_'.$otraLista->id => ['valor' => 10, 'signo' => 'aumentar']],
            'ids' => [$producto->id],
        ])->assertOk()->assertJsonPath('envios.ml', []);

        $respuesta = $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta', 'modo' => 'porcentaje',
            'campos' => ['lista_'.$listaMl->id => ['valor' => 10, 'signo' => 'aumentar']],
            'ids' => [$producto->id],
        ])->assertOk()->assertJsonPath('envios.ml', [$vinculo->id]);

        Http::assertNothingSent();
        $this->assertTrue($vinculo->fresh()->precio_pendiente);

        $this->postJson(route('productos.acciones-masivas.enviar-precios'), ['ml' => $respuesta->json('envios.ml')])
            ->assertOk()
            ->assertJson(['ml_procesados' => 1, 'enviados' => 1, 'no_enviados' => 0]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/items/MLA1') && $request['price'] === 1100.0);
        $this->assertFalse($vinculo->fresh()->precio_pendiente);
    }

    public function test_ids_resuelve_el_conjunto_filtrado_una_sola_vez(): void
    {
        $activo = Producto::create(['nombre' => 'Activo', 'tipo' => 'producto', 'activo' => true]);
        Producto::create(['nombre' => 'Inactivo', 'tipo' => 'producto', 'activo' => false]);

        $this->postJson(route('productos.acciones-masivas.ids'), ['filtros' => ['estado' => 'activos']])
            ->assertOk()
            ->assertExactJson(['ids' => [$activo->id]]);
    }

    public function test_reintentar_una_tanda_de_eliminar_ya_borrada_no_falla(): void
    {
        $producto = Producto::create(['nombre' => 'P1', 'tipo' => 'producto']);
        $tanda = ['accion' => 'eliminar', 'ids' => [$producto->id]];

        $this->postJson(route('productos.acciones-masivas'), $tanda)->assertOk()->assertJson(['eliminados' => 1]);
        $this->postJson(route('productos.acciones-masivas'), $tanda)->assertOk()->assertJson(['eliminados' => 0]);
    }
}
