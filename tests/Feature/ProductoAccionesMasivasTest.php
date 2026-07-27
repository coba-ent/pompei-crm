<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\TipoProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
