<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\ImportacionCorrida;
use App\Models\ImportacionFilaSnapshot;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\User;
use App\Services\Import\InformeCambiosImportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec 093, US1 — informe de qué cambió desde una corrida de import.
 *
 * El informe habla de precios y stock: uno que reporta un cambio que no ocurrió es peor que no
 * tenerlo, porque hace desconfiar de todo lo demás. Por eso los snapshots de estos tests usan el
 * **formato real de producción**, no uno inventado.
 */
class InformeCambiosImportacionTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): InformeCambiosImportacion
    {
        return new InformeCambiosImportacion;
    }

    private function corrida(array $atributos = []): ImportacionCorrida
    {
        return ImportacionCorrida::create(array_merge([
            'entidad' => 'productos',
            'archivo_original' => 'productos_20260828_151041.xlsx',
            'confirmado_en' => now()->subDay(),
            'deshacer_disponible_hasta' => now()->subDay()->addHours(48),
            'filas_creadas' => 0,
            'filas_actualizadas' => 0,
            'filas_fallidas' => 0,
        ], $atributos));
    }

    /**
     * Snapshot con el formato REAL de producción: `precios_anteriores` y `stock_anterior` son
     * arrays de objetos.
     */
    private function snapshot(ImportacionCorrida $corrida, Producto $producto, array $precios, array $stock, array $extra = []): ImportacionFilaSnapshot
    {
        return ImportacionFilaSnapshot::create(array_merge([
            'importacion_corrida_id' => $corrida->id,
            'producto_id' => $producto->id,
            'modo' => 'actualizacion',
            'existia' => true,
            'estado_anterior' => [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'codigo' => $producto->codigo,
                'costo' => $producto->costo,
            ],
            'precios_anteriores' => $precios,
            'stock_anterior' => $stock,
            'numero_fila' => 2,
            'estado_undo' => 'pendiente',
        ], $extra));
    }

    private function producto(string $nombre, string $codigo, $costo = 1000): Producto
    {
        return Producto::create([
            'nombre' => $nombre, 'codigo' => $codigo, 'tipo' => 'producto',
            'precio_venta' => 2000, 'costo' => $costo, 'activo' => true,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // T001 — la corrida real 5, el caso que motivó la spec
    // ---------------------------------------------------------------------------------------

    /**
     * Reproduce en chico la corrida 5 real: muchas filas, **ningún** campo ni precio cambiado, y
     * un puñado con cambio de stock — con el −181 de EMBALAJE JPD primero en la lista.
     */
    public function test_corrida_real_5_reporta_solo_los_cambios_de_stock_con_el_mayor_primero(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        $deposito = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 4]);

        // Tres productos que NO cambiaron nada: mismo costo, mismo precio, mismo stock.
        foreach ([['A', 'COD-A'], ['B', 'COD-B'], ['C', 'COD-C']] as [$nombre, $codigo]) {
            $p = $this->producto($nombre, $codigo, 41000);
            $p->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 167206.75]);
            $p->stocks()->create(['deposito_id' => $deposito->id, 'cantidad' => 5]);

            $this->snapshot($corrida, $p,
                [['lista_precio_id' => $lista->id, 'precio' => '167206.75']],
                [['deposito_id' => $deposito->id, 'cantidad' => '5.000']],
            );
        }

        // EMBALAJE JPD: el stock bajó de 263 a 82 (−181).
        $embalaje = $this->producto('EMBALAJE JPD', '41527 EMB', 41000);
        $embalaje->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 167206.75]);
        $embalaje->stocks()->create(['deposito_id' => $deposito->id, 'cantidad' => 82]);
        $this->snapshot($corrida, $embalaje,
            [['lista_precio_id' => $lista->id, 'precio' => '167206.75']],
            [['deposito_id' => $deposito->id, 'cantidad' => '263.000']],
        );

        // Otro con un cambio más chico, para verificar el orden por magnitud.
        $otro = $this->producto('OTRO', 'COD-OTRO', 41000);
        $otro->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 167206.75]);
        $otro->stocks()->create(['deposito_id' => $deposito->id, 'cantidad' => 3]);
        $this->snapshot($corrida, $otro,
            [['lista_precio_id' => $lista->id, 'precio' => '167206.75']],
            [['deposito_id' => $deposito->id, 'cantidad' => '5.000']],
        );

        $informe = $this->servicio()->generar($corrida->fresh());

        $this->assertTrue($informe['informe_disponible']);
        // Ningún campo y ningún precio cambiaron — es exactamente lo que el primer intento
        // reportó mal.
        $this->assertSame([], $informe['campos']);
        $this->assertSame([], $informe['precios']);

        $this->assertSame(2, $informe['resumen']['productos_con_algun_cambio']);
        $this->assertSame(3, $informe['resumen']['productos_sin_cambios']);

        // FR-004: el −181 primero.
        $this->assertCount(2, $informe['stock']);
        $this->assertSame('EMBALAJE JPD', $informe['stock'][0]['nombre']);
        $this->assertSame(-181.0, $informe['stock'][0]['diferencia']);
        $this->assertSame(263.0, $informe['stock'][0]['antes']);
        $this->assertSame(82.0, $informe['stock'][0]['ahora']);
        $this->assertSame('Local', $informe['stock'][0]['deposito']);
        $this->assertSame(-2.0, $informe['stock'][1]['diferencia']);
    }

    // ---------------------------------------------------------------------------------------
    // T002 — el formato del JSON
    // ---------------------------------------------------------------------------------------

    /**
     * ⚠️ Este es EL test de la feature. `precios_anteriores` es un array de objetos, no un mapa
     * `id => precio`. Un lector que lo trate como mapa toma el índice del array (0) por id de
     * lista y reporta cambios inexistentes — que es como el primer intento informó "192 productos
     * cambiaron en las 11 listas" cuando la respuesta correcta era ninguno.
     */
    public function test_precios_anteriores_se_lee_como_array_de_objetos_y_no_como_mapa(): void
    {
        $listaA = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $listaB = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('Peinador', '27136 PT5070-1M BL');
        $producto->precios()->create(['lista_precio_id' => $listaA->id, 'precio' => 213506.29]);
        $producto->precios()->create(['lista_precio_id' => $listaB->id, 'precio' => 300000.00]);

        // Los ids de lista NO coinciden con los índices del array: si alguien lo lee como mapa,
        // el precio de la lista B se compara contra el de la lista A y aparece un cambio falso.
        $this->snapshot($corrida, $producto, [
            ['lista_precio_id' => $listaA->id, 'precio' => '213506.29'],
            ['lista_precio_id' => $listaB->id, 'precio' => '300000.00'],
        ], []);

        $informe = $this->servicio()->generar($corrida->fresh());

        $this->assertSame([], $informe['precios'], 'Ningún precio cambió: leerlo como mapa reporta cambios inexistentes.');
        $this->assertSame(0, $informe['resumen']['productos_con_algun_cambio']);
    }

    /** Mismo contrato para `stock_anterior`: array de objetos con `deposito_id`. */
    public function test_stock_anterior_se_lee_como_array_de_objetos_y_no_como_mapa(): void
    {
        $depositoA = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $depositoB = Deposito::create(['nombre' => 'Depósito 2', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('Con dos depósitos', 'COD-2D');
        $producto->stocks()->create(['deposito_id' => $depositoA->id, 'cantidad' => 10]);
        $producto->stocks()->create(['deposito_id' => $depositoB->id, 'cantidad' => 40]);

        $this->snapshot($corrida, $producto, [], [
            ['deposito_id' => $depositoA->id, 'cantidad' => '10.000'],
            ['deposito_id' => $depositoB->id, 'cantidad' => '40.000'],
        ]);

        $informe = $this->servicio()->generar($corrida->fresh());

        $this->assertSame([], $informe['stock']);
    }

    /** Un cambio de precio real sí se reporta, por lista y con la variación. */
    public function test_cambio_de_precio_se_reporta_por_lista(): void
    {
        $listaA = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $listaB = ListaPrecio::create(['nombre' => 'ML', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('Bacha', '27218 BCU5070 BL');
        $producto->precios()->create(['lista_precio_id' => $listaA->id, 'precio' => 100000.00]);
        $producto->precios()->create(['lista_precio_id' => $listaB->id, 'precio' => 172000.00]);

        $this->snapshot($corrida, $producto, [
            ['lista_precio_id' => $listaA->id, 'precio' => '100000.00'],
            ['lista_precio_id' => $listaB->id, 'precio' => '167206.75'],
        ], []);

        $informe = $this->servicio()->generar($corrida->fresh());

        // FR-002: por lista, no agregado. Sólo cambió ML.
        $this->assertCount(1, $informe['precios']);
        $this->assertSame($listaB->id, $informe['precios'][0]['lista_precio_id']);
        $this->assertSame('ML', $informe['precios'][0]['lista']);
        $this->assertSame(1, $informe['precios'][0]['productos']);
        $this->assertSame('27218 BCU5070 BL', $informe['precios'][0]['ejemplo']['codigo']);
        $this->assertSame(167206.75, $informe['precios'][0]['ejemplo']['antes']);
        $this->assertSame(172000.0, $informe['precios'][0]['ejemplo']['ahora']);
        $this->assertSame(2.87, $informe['precios'][0]['ejemplo']['variacion_pct']);
    }

    /** FR-001: un campo cambiado se cuenta y se ejemplifica. */
    public function test_cambio_de_campo_se_reporta_con_ejemplo(): void
    {
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('Bidet', '27198 BTR6363 BL', 43500);
        $this->snapshot($corrida, $producto, [], [], [
            'estado_anterior' => [
                'id' => $producto->id, 'nombre' => 'Bidet', 'codigo' => '27198 BTR6363 BL', 'costo' => '41000.00',
            ],
        ]);

        $informe = $this->servicio()->generar($corrida->fresh());

        $this->assertCount(1, $informe['campos']);
        $this->assertSame('costo', $informe['campos'][0]['campo']);
        $this->assertSame(1, $informe['campos'][0]['productos']);
        $this->assertSame('41000.00', $informe['campos'][0]['ejemplo']['antes']);
        $this->assertSame('43500.00', $informe['campos'][0]['ejemplo']['ahora']);
    }

    // ---------------------------------------------------------------------------------------
    // T003 — sin filas de snapshot
    // ---------------------------------------------------------------------------------------

    /** FR-007: "sin detalle disponible" NO es "sin cambios". */
    public function test_corrida_sin_filas_de_snapshot_informa_sin_detalle_y_no_cero_cambios(): void
    {
        $corrida = $this->corrida(['filas_actualizadas' => 300]);

        $informe = $this->servicio()->generar($corrida);

        $this->assertFalse($informe['informe_disponible']);
        $this->assertArrayHasKey('motivo', $informe);
        $this->assertArrayNotHasKey('resumen', $informe);
        $this->assertArrayNotHasKey('stock', $informe);
    }

    // ---------------------------------------------------------------------------------------
    // T004 — actividad posterior, producto eliminado, corrida deshecha
    // ---------------------------------------------------------------------------------------

    /** FR-006: reusa los `limite_*` que el snapshot ya guarda para el deshacer. */
    public function test_producto_con_actividad_posterior_queda_marcado(): void
    {
        $deposito = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('Con venta posterior', 'COD-VP');
        $producto->stocks()->create(['deposito_id' => $deposito->id, 'cantidad' => 3]);

        // El snapshot capturó el último movimiento con id 10; hay uno con id 11 → posterior.
        $this->snapshot($corrida, $producto, [], [['deposito_id' => $deposito->id, 'cantidad' => '10.000']], [
            'limite_movimiento_stock_id' => 10,
        ]);

        DB::table('movimientos_stock')->insert([
            'id' => 11, 'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => -7, 'fecha' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $informe = $this->servicio()->generar($corrida->fresh());

        $this->assertSame(1, $informe['resumen']['con_actividad_posterior']);
        $this->assertTrue($informe['stock'][0]['actividad_posterior']);
    }

    /** FR-008: un producto borrado después no rompe el informe y se identifica. */
    public function test_producto_eliminado_despues_se_identifica_sin_romper(): void
    {
        $deposito = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('Se borró', 'COD-BORRADO');
        $this->snapshot($corrida, $producto, [], [['deposito_id' => $deposito->id, 'cantidad' => '20.000']]);
        $producto->delete();

        $informe = $this->servicio()->generar($corrida->fresh());

        $this->assertSame(1, $informe['resumen']['productos_eliminados']);
        $this->assertTrue($informe['stock'][0]['producto_eliminado']);
        // Su stock de hoy es cero: el producto ya no existe.
        $this->assertSame(-20.0, $informe['stock'][0]['diferencia']);
    }

    /** FR-009: una corrida deshecha se señala, con su fecha. */
    public function test_corrida_deshecha_se_senala_con_su_fecha(): void
    {
        $corrida = $this->corrida(['filas_actualizadas' => 1, 'deshecho_en' => now()->subHours(2)]);
        $this->snapshot($corrida, $this->producto('X', 'COD-X'), [], []);

        $informe = $this->servicio()->generar($corrida->fresh());

        $this->assertNotNull($informe['corrida']['deshecha_en']);
    }

    /** FR-005: la advertencia de método viaja en la respuesta, no en la vista. */
    public function test_la_advertencia_de_metodo_viaja_en_la_respuesta(): void
    {
        $corrida = $this->corrida(['filas_actualizadas' => 1]);
        $this->snapshot($corrida, $this->producto('X', 'COD-X'), [], []);

        $informe = $this->servicio()->generar($corrida->fresh());

        $this->assertStringContainsString('Un cambio posterior', $informe['advertencia_metodo']);
    }

    // ---------------------------------------------------------------------------------------
    // T005 — lista de precios y depósito eliminados
    // ---------------------------------------------------------------------------------------

    /** Se nombran por id y el informe no rompe. */
    public function test_lista_y_deposito_eliminados_se_nombran_por_id_sin_romper(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'A borrar', 'activo' => true]);
        $deposito = Deposito::create(['nombre' => 'A borrar', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('Producto', 'COD-P');
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 200]);
        $producto->stocks()->create(['deposito_id' => $deposito->id, 'cantidad' => 1]);

        $this->snapshot($corrida, $producto,
            [['lista_precio_id' => $lista->id, 'precio' => '100.00']],
            [['deposito_id' => $deposito->id, 'cantidad' => '9.000']],
        );

        $listaId = $lista->id;
        $depositoId = $deposito->id;

        // Al borrar la lista y el depósito se van también sus filas hijas — el informe queda
        // comparando contra un id que ya no tiene nombre, que es justo lo que se está probando.
        $producto->precios()->delete();
        $producto->stocks()->delete();
        $lista->delete();
        $deposito->delete();

        $informe = $this->servicio()->generar($corrida->fresh());

        // El depósito ya no existe: se lo nombra por id en vez de romper el informe entero.
        $this->assertSame("Depósito #{$depositoId}", $informe['stock'][0]['deposito']);
        $this->assertSame(-9.0, $informe['stock'][0]['diferencia']);

        // La lista tampoco existe. `precios_producto.lista_precio_id` tiene cascadeOnDelete, así
        // que su precio actual se fue con ella: el informe NO inventa un cambio contra un precio
        // que ya no está — que es la mitad importante de esta garantía.
        $this->assertSame([], $informe['precios']);
        $this->assertIsInt($listaId);
    }

    // ---------------------------------------------------------------------------------------
    // FR-011 — sólo lectura
    // ---------------------------------------------------------------------------------------

    public function test_el_informe_no_modifica_nada(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $deposito = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('Producto', 'COD-P', 500);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 999]);
        $producto->stocks()->create(['deposito_id' => $deposito->id, 'cantidad' => 7]);

        $this->snapshot($corrida, $producto,
            [['lista_precio_id' => $lista->id, 'precio' => '100.00']],
            [['deposito_id' => $deposito->id, 'cantidad' => '1.000']],
        );

        $this->servicio()->generar($corrida->fresh());

        $this->assertSame('500.00', (string) $producto->fresh()->costo);
        $this->assertSame('999.00', (string) $producto->precios()->first()->precio);
        $this->assertSame('7.000', (string) $producto->stocks()->first()->cantidad);
    }

    // ---------------------------------------------------------------------------------------
    // Endpoint
    // ---------------------------------------------------------------------------------------

    public function test_el_endpoint_devuelve_el_informe(): void
    {
        $deposito = Deposito::create(['nombre' => 'Local', 'activo' => true]);
        $corrida = $this->corrida(['filas_actualizadas' => 1]);

        $producto = $this->producto('EMBALAJE JPD', '41527 EMB');
        $producto->stocks()->create(['deposito_id' => $deposito->id, 'cantidad' => 82]);
        $this->snapshot($corrida, $producto, [], [['deposito_id' => $deposito->id, 'cantidad' => '263.000']]);

        $this->actingAs(User::factory()->create())
            ->getJson("/importar-datos/productos/historial/{$corrida->id}/informe")
            ->assertOk()
            ->assertJsonPath('informe_disponible', true)
            ->assertJsonPath('stock.0.diferencia', -181)
            ->assertJsonStructure(['advertencia_metodo', 'resumen', 'campos', 'precios', 'stock']);
    }

    public function test_el_endpoint_de_una_corrida_inexistente_da_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/importar-datos/productos/historial/99999/informe')
            ->assertNotFound();
    }

    /** El historial expone `informe_disponible` para poder deshabilitar el ícono (FR-007). */
    public function test_el_historial_expone_si_hay_informe_disponible(): void
    {
        $sinDetalle = $this->corrida();
        $conDetalle = $this->corrida(['filas_actualizadas' => 1]);
        $this->snapshot($conDetalle, $this->producto('X', 'COD-X'), [], []);

        $respuesta = $this->actingAs(User::factory()->create())
            ->getJson('/importar-datos/productos/historial/datos?draw=1&start=0&length=10')
            ->assertOk()
            ->json('data');

        $porId = collect($respuesta)->keyBy('id');
        $this->assertFalse($porId[$sinDetalle->id]['informe_disponible']);
        $this->assertTrue($porId[$conDetalle->id]['informe_disponible']);
    }
}
