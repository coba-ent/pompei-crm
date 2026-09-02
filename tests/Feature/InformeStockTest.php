<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Deposito;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Stock;
use App\Models\Venta;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActuaComoUsuarioConPermisos;
use Tests\TestCase;

/**
 * US3 — Informe de Stock: cálculo de "Stock Saldo" (research.md §2), KPIs de
 * valorización (misma fórmula que ProductoController::estadisticas()) y el
 * límite del filtro "Operación" a los tipos que el sistema genera hoy (FR-013).
 *
 * spec 051: orden por fecha+hora real y columna Detalle con datos de Venta.
 */
class InformeStockTest extends TestCase
{
    use ActuaComoUsuarioConPermisos;
    use RefreshDatabase;

    /**
     * spec 090: el Informe de Stock pasó a exigir el permiso `informes.stock`. Antes no exigía
     * ninguno —de hecho ese era el bug: sus rutas habían quedado fuera del middleware—, así que
     * estos tests se apoyaban en el usuario sin roles que autentica el `TestCase` base.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsUsuarioConPermisos(['informes.stock']);
    }

    public function test_stock_saldo_no_cambia_al_aplicar_filtros_que_excluyen_movimientos_anteriores(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central']);
        $producto = Producto::factory()->create();

        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'entrada', 'cantidad' => 10, 'fecha' => '2026-06-01',
        ]);
        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => 5, 'fecha' => '2026-06-10',
        ]);

        // El saldo se calcula restando hacia atrás desde el stock real, así que la foto de `stocks`
        // tiene que existir: es el punto de partida del cálculo, no un detalle del escenario.
        Stock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id, 'cantidad' => 15,
        ]);

        // Sin filtros: la fila del ajuste (06-10) es la última, así que muestra el stock actual (15).
        $sinFiltro = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
            ->assertOk()->json();
        $filaSinFiltro = collect($sinFiltro['data'])->firstWhere('tipo', 'ajuste');
        $this->assertEquals(15, $filaSinFiltro['stock_saldo']);

        // Con un fecha_desde que excluye el movimiento de "entrada" (06-01) de la
        // tabla mostrada, el saldo de la fila de ajuste que sí se muestra NO cambia:
        // el cálculo se hace sobre el histórico completo, el filtro sólo oculta filas.
        $conFiltro = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'fecha_desde' => '2026-06-05',
        ]))->assertOk()->json();

        $this->assertCount(1, $conFiltro['data']);
        $this->assertEquals(15, $conFiltro['data'][0]['stock_saldo']);
    }

    public function test_kpis_coinciden_con_la_valorizacion_de_stocks_y_productos(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central']);
        $p1 = Producto::factory()->create(['costo' => 100, 'precio_venta' => 200]);
        $p2 = Producto::factory()->create(['costo' => 50, 'precio_venta' => 80]);
        Stock::create(['producto_id' => $p1->id, 'deposito_id' => $deposito->id, 'cantidad' => 10]);
        Stock::create(['producto_id' => $p2->id, 'deposito_id' => $deposito->id, 'cantidad' => 5]);

        $resp = $this->getJson(route('informes.stock.stats'))->assertOk()->json();

        $this->assertEquals(15, $resp['unidades_en_stock']);
        $this->assertEquals(10 * 100 + 5 * 50, $resp['costo_total']);
        $this->assertEquals(10 * 200 + 5 * 80, $resp['valor_venta_total']);
    }

    public function test_filtro_operacion_reconoce_entrada_salida_ajuste_y_transferencia(): void
    {
        // FR-013 se amplió en la spec 012 (research.md §R1): las Ventas ahora generan
        // movimientos "entrada"/"salida", y el filtro del Informe de Stock los expone
        // (quickstart.md §Escenario 7), además de los ya existentes ajuste/transferencia.
        $deposito = Deposito::create(['nombre' => 'D']);
        $producto = Producto::factory()->create();

        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'entrada', 'cantidad' => 10, 'fecha' => '2026-06-01',
        ]);
        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => 2, 'fecha' => '2026-06-02',
        ]);

        $resp = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'operacion' => 'entrada',
        ]))->assertOk()->json();
        $this->assertCount(1, $resp['data']);
        $this->assertSame('entrada', $resp['data'][0]['tipo']);

        // "ajuste" sigue reconocida y filtrando correctamente.
        $resp2 = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'operacion' => 'ajuste',
        ]))->assertOk()->json();
        $this->assertCount(1, $resp2['data']);
        $this->assertSame('ajuste', $resp2['data'][0]['tipo']);

        // Un valor realmente no reconocido se sigue ignorando (histórico completo).
        $resp3 = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'operacion' => 'no_existe',
        ]))->assertOk()->json();
        $this->assertCount(2, $resp3['data']);
    }

    public function test_filtro_producto_id_acota_la_tabla(): void
    {
        $deposito = Deposito::create(['nombre' => 'D']);
        $productoA = Producto::factory()->create();
        $productoB = Producto::factory()->create();

        MovimientoStock::create([
            'producto_id' => $productoA->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => 3, 'fecha' => '2026-06-01',
        ]);
        MovimientoStock::create([
            'producto_id' => $productoB->id, 'deposito_id' => $deposito->id,
            'tipo' => 'ajuste', 'cantidad' => 4, 'fecha' => '2026-06-01',
        ]);

        $resp = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'producto_id' => $productoA->id,
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $this->assertSame($productoA->id, $resp['data'][0]['producto_id']);
    }

    private function deposito(): Deposito
    {
        return Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    public function test_movimientos_del_mismo_dia_se_ordenan_por_hora_real(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $stock = app(StockService::class);

        $stock->ajustar($producto, null, $deposito, 5, 'Tarde', fecha: '2026-08-06 18:00:00');
        $stock->ajustar($producto, null, $deposito, 3, 'Mañana', fecha: '2026-08-06 08:00:00');

        // Por defecto el informe muestra lo último primero, así que 'Tarde' (18:00) va arriba de
        // 'Mañana' (08:00) — pese a haberse insertado antes: manda la hora real, no el id.
        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $descripciones = collect($respuesta->json('data'))->pluck('descripcion')->all();

        $this->assertSame(['Tarde', 'Mañana'], $descripciones);

        // Y pidiendo el orden ascendente se invierte, para que la pantalla pueda leer el histórico
        // en orden cronológico.
        $ascendente = $this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'order' => [['column' => 1, 'dir' => 'asc']],
        ]))->assertOk();

        $this->assertSame(['Mañana', 'Tarde'], collect($ascendente->json('data'))->pluck('descripcion')->all());
    }

    public function test_saldo_corrido_correcto_con_varios_movimientos_mismo_dia_distinta_hora(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $stock = app(StockService::class);

        $stock->ajustar($producto, null, $deposito, 5, 'Tarde', fecha: '2026-08-06 18:00:00');
        $stock->ajustar($producto, null, $deposito, 3, 'Mañana', fecha: '2026-08-06 08:00:00');

        // El saldo se calcula siempre cronológicamente (Mañana=3, Tarde=8); lo que cambia con el
        // orden de la tabla es en qué fila se muestra cada uno, no su valor.
        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $saldos = collect($respuesta->json('data'))->pluck('stock_saldo')->all();

        $this->assertEquals([8.0, 3.0], $saldos);
    }

    public function test_ajuste_sin_fecha_explicita_persiste_con_hora_real(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $this->travelTo(now()->setTime(15, 30, 0));

        app(StockService::class)->ajustar($producto, null, $deposito, 1, 'Ajuste sin fecha');

        $movimiento = MovimientoStock::where('descripcion', 'Ajuste sin fecha')->firstOrFail();

        $this->assertSame('15:30:00', $movimiento->fecha->format('H:i:s'));
    }

    public function test_alta_de_stock_por_compra_conserva_hora_00_00_00(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        $this->travelTo(now()->setTime(15, 30, 0));

        app(StockService::class)->registrarEntrada($producto, null, $deposito, 1, null, null, '2026-08-06');

        $movimiento = MovimientoStock::latest('id')->firstOrFail();

        $this->assertSame('00:00:00', $movimiento->fecha->format('H:i:s'));
    }

    public function test_filtros_de_fecha_existentes_siguen_funcionando_con_fecha_datetime(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $stock = app(StockService::class);

        $stock->ajustar($producto, null, $deposito, 5, 'Dentro del rango', fecha: '2026-08-06 10:00:00');
        $stock->ajustar($producto, null, $deposito, 2, 'Fuera del rango', fecha: '2026-08-10 10:00:00');

        $respuesta = $this->getJson(route('informes.stock.data', [
            'fecha_desde' => '2026-08-06',
            'fecha_hasta' => '2026-08-06',
        ]))->assertOk();

        $descripciones = collect($respuesta->json('data'))->pluck('descripcion')->all();

        $this->assertSame(['Dentro del rango'], $descripciones);
    }

    private function crearVenta(Cliente $cliente, string $nroComprobante = '0001-00000001'): Venta
    {
        return Venta::create([
            'cliente_id' => $cliente->id,
            'tipo_comprobante' => 'B',
            'nro_comprobante' => $nroComprobante,
            'fecha_emision' => '2026-08-06',
            'total' => 100,
        ]);
    }

    public function test_detalle_de_venta_con_cliente_incluye_comprobante_y_cliente(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $cliente = Cliente::factory()->create(['nombre' => 'Juan Pérez']);
        $venta = $this->crearVenta($cliente);

        app(StockService::class)->registrarSalida($producto, null, $deposito, 1, $venta);

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $detalle = collect($respuesta->json('data'))->pluck('detalle')->first();

        $this->assertSame('B 0001-00000001 - Juan Pérez', $detalle);
    }

    /**
     * FR-005 exige que, cuando la venta de origen no tiene cliente, el Detalle
     * omita esa parte sin error. `ventas.cliente_id` es NOT NULL en el modelo
     * de datos actual (toda venta requiere cliente), así que se ejercita la
     * misma rama de la CASE SQL (`clientes.nombre IS NULL`) por la vía
     * alcanzable dentro de esa restricción: una venta cuyo `origen_id` ya no
     * resuelve a una fila accesible (venta borrada a nivel de datos / dato
     * legado), degradando el Detalle sin romper (edge case del spec.md).
     */
    public function test_detalle_degrada_sin_error_si_la_venta_de_origen_no_es_accesible(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->registrarSalida($producto, null, $deposito, 1, new Venta(['id' => 999999]));

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $detalle = collect($respuesta->json('data'))->pluck('detalle')->first();

        $this->assertNull($detalle);
    }

    public function test_reintegro_por_eliminacion_de_venta_conserva_el_detalle_de_origen(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $cliente = Cliente::factory()->create(['nombre' => 'Juan Pérez']);
        $venta = $this->crearVenta($cliente);

        $stock = app(StockService::class);
        $stock->registrarSalida($producto, null, $deposito, 1, $venta);
        $stock->registrarEntrada($producto, null, $deposito, 1, $venta);
        $venta->delete();

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $detalles = collect($respuesta->json('data'))->pluck('detalle')->all();

        $this->assertSame(['B 0001-00000001 - Juan Pérez', 'B 0001-00000001 - Juan Pérez'], $detalles);
    }

    public function test_movimientos_de_compra_ajuste_y_transferencia_usan_descripcion_como_detalle(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->ajustar($producto, null, $deposito, 5, 'Ajuste manual de prueba');

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $fila = collect($respuesta->json('data'))->first();

        // Desde el 02/09/2026 los ajustes llevan el sentido adelante; la descripción propia se
        // conserva intacta detrás.
        $this->assertSame('Aumento — Ajuste manual de prueba', $fila['detalle']);
        $this->assertStringContainsString($fila['descripcion'], $fila['detalle']);
    }

    public function test_la_columna_documento_enlaza_la_venta_que_origino_el_movimiento(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $venta = $this->crearVenta(Cliente::factory()->create());

        app(StockService::class)->registrarSalida($producto, null, $deposito, 1, $venta);

        $fila = collect($this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
            ->assertOk()->json('data'))->first();

        $this->assertSame($venta->id, $fila['documento']['id']);
        $this->assertSame('Venta', $fila['documento']['tipo']);
        $this->assertStringContainsString('/ventas/'.$venta->id, $fila['documento']['url']);
    }

    public function test_un_movimiento_sin_documento_de_origen_no_trae_enlace(): void
    {
        // Ajustes manuales y sincronizaciones con ML/Tiendanube no nacen de ningún documento: la
        // celda queda vacía en vez de inventar un id.
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->ajustar($producto, null, $deposito, 5, 'Ajuste manual');

        $fila = collect($this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
            ->assertOk()->json('data'))->first();

        $this->assertNull($fila['documento']);
    }

    /**
     * Los movimientos que genera el CRM guardan `descripcion` en NULL: ni StockDeVenta ni el alta
     * de Compra le pasan un texto a StockService. Para las ventas nunca se notó porque el CASE ya
     * las reconstruía; las compras quedaban con la columna Detalle vacía (172 movimientos al
     * 01/09/2026). Se resuelve reconstruyendo en el informe, sin rellenar datos.
     */
    public function test_detalle_de_compra_muestra_comprobante_y_proveedor(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $proveedor = Proveedor::factory()->create(['nombre' => 'JPD AMOBLAMIENTO']);
        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => 'A',
            'nro_comprobante' => '0000-00001475',
        ]);

        app(StockService::class)->registrarEntrada($producto, null, $deposito, 3, $compra);

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame(
            'A 0000-00001475 - JPD AMOBLAMIENTO',
            collect($respuesta->json('data'))->pluck('detalle')->first()
        );
    }

    /**
     * Hay 2 compras en producción sin número de comprobante (tipo "S"). Concatenar a secas dejaba
     * un `"S "` que en pantalla parece una celda vacía; el proveedor solo ya identifica el
     * movimiento, y el separador " - " no debe quedar colgando adelante.
     */
    public function test_detalle_de_compra_sin_comprobante_muestra_solo_el_proveedor(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $proveedor = Proveedor::factory()->create(['nombre' => 'GOOD LOOKING']);
        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => 'S',
            'nro_comprobante' => null,
        ]);

        app(StockService::class)->registrarEntrada($producto, null, $deposito, 1, $compra);

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame(
            'GOOD LOOKING',
            collect($respuesta->json('data'))->pluck('detalle')->first()
        );
    }

    /** Un ajuste sin descripción propia muestra el texto neutro en vez de una celda en blanco. */
    public function test_un_ajuste_sin_descripcion_no_deja_la_celda_vacia(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->ajustar($producto, null, $deposito, 5);

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame('Aumento — Ajuste sin detalle', collect($respuesta->json('data'))->pluck('detalle')->first());
    }

    /** Un ajuste que sí trae descripción la conserva: el fallback no la pisa. */
    public function test_un_ajuste_con_descripcion_propia_la_conserva(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->ajustar($producto, null, $deposito, 5, 'Ajuste por conteo real');

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame('Aumento — Ajuste por conteo real', collect($respuesta->json('data'))->pluck('detalle')->first());
    }

    /**
     * EL CASO QUE MOTIVÓ EL CAMBIO (01/09/2026).
     *
     * El saldo se calcula restando hacia atrás desde el stock real, no acumulando hacia adelante
     * desde cero. La diferencia importa cuando faltan movimientos viejos: la TECLA UNIVERSAL en
     * producción mostraba -8 teniendo 21 unidades en depósito, porque su historia arranca en 2024
     * y el producto ya venía con unidades encima.
     *
     * Acá se reproduce el patrón: un producto con 21 en depósito cuyos movimientos conocidos suman
     * mucho menos. La última fila tiene que mostrar 21, no la suma.
     */
    public function test_el_saldo_parte_del_stock_real_aunque_falten_movimientos_viejos(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central']);
        $producto = Producto::factory()->create();

        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'entrada', 'cantidad' => 3, 'fecha' => '2026-06-01',
        ]);
        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id,
            'tipo' => 'salida', 'cantidad' => -1, 'fecha' => '2026-06-10',
        ]);

        // Los movimientos suman 2, pero en depósito hay 21: al producto le falta historia previa.
        Stock::create([
            'producto_id' => $producto->id, 'deposito_id' => $deposito->id, 'cantidad' => 21,
        ]);

        $filas = collect($this->getJson(route('informes.stock.data', [
            'draw' => 1, 'start' => 0, 'length' => 10, 'order' => [['column' => 1, 'dir' => 'asc']],
        ]))->assertOk()->json('data'));

        $ultima = $filas->firstWhere('tipo', 'salida');
        $anterior = $filas->firstWhere('tipo', 'entrada');

        $this->assertEquals(21, $ultima['stock_saldo'], 'La última fila muestra el stock real de hoy.');
        $this->assertEquals(22, $anterior['stock_saldo'], 'La anterior, el stock antes de esa salida.');
    }

    /** Cada depósito lleva su propio saldo: no se mezclan al restar hacia atrás. */
    public function test_el_saldo_es_independiente_por_deposito(): void
    {
        $local = Deposito::create(['nombre' => 'Local']);
        $full = Deposito::create(['nombre' => 'Full']);
        $producto = Producto::factory()->create();

        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $local->id,
            'tipo' => 'salida', 'cantidad' => -1, 'fecha' => '2026-06-01',
        ]);
        MovimientoStock::create([
            'producto_id' => $producto->id, 'deposito_id' => $full->id,
            'tipo' => 'salida', 'cantidad' => -1, 'fecha' => '2026-06-02',
        ]);

        Stock::create(['producto_id' => $producto->id, 'deposito_id' => $local->id, 'cantidad' => 5]);
        Stock::create(['producto_id' => $producto->id, 'deposito_id' => $full->id, 'cantidad' => 40]);

        $filas = collect($this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
            ->assertOk()->json('data'));

        $this->assertEquals(5, $filas->firstWhere('deposito', 'Local')['stock_saldo']);
        $this->assertEquals(40, $filas->firstWhere('deposito', 'Full')['stock_saldo']);
    }

    /**
     * Pedido del 02/09/2026: en un ajuste, la columna Detalle tiene que decir si sumó o restó.
     *
     * Los movimientos históricos ya lo traían del export de Contagram ("Aumento — por Juan"), pero
     * los 156 que genera el CRM mostraban sólo su texto libre, sin indicar el sentido.
     */
    public function test_un_ajuste_positivo_dice_aumento(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->ajustar($producto, null, $deposito, 5, 'Reversión de Nota de Crédito #876');

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame(
            'Aumento — Reversión de Nota de Crédito #876',
            collect($respuesta->json('data'))->pluck('detalle')->first()
        );
    }

    public function test_un_ajuste_negativo_dice_disminucion(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->ajustar($producto, null, $deposito, -3, 'Ajuste por conteo real');

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame(
            'Disminución — Ajuste por conteo real',
            collect($respuesta->json('data'))->pluck('detalle')->first()
        );
    }

    /** Un ajuste sin texto propio queda con el sentido y el fallback, nunca en blanco. */
    public function test_un_ajuste_sin_texto_igual_dice_el_sentido(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->ajustar($producto, null, $deposito, 2);

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame(
            'Aumento — Ajuste sin detalle',
            collect($respuesta->json('data'))->pluck('detalle')->first()
        );
    }

    /**
     * Los 3.728 ajustes históricos ya vienen del export con el sentido adelante ("Aumento por
     * Importación — por Juan"). Anteponérselo de nuevo daría "Aumento — Aumento por Importación".
     */
    public function test_no_duplica_el_sentido_cuando_el_texto_ya_lo_trae(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);

        app(StockService::class)->ajustar($producto, null, $deposito, 7, 'Aumento por Importación — por Juan Ignacio Conlon');

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame(
            'Aumento por Importación — por Juan Ignacio Conlon',
            collect($respuesta->json('data'))->pluck('detalle')->first()
        );
    }

    /**
     * El sentido se antepone SÓLO a los ajustes: en una venta o una compra ya se lee del documento
     * y de la propia columna Cantidad, y ahí sería ruido.
     */
    public function test_una_venta_no_lleva_el_prefijo_de_sentido(): void
    {
        $deposito = $this->deposito();
        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $cliente = Cliente::factory()->create(['nombre' => 'Juan Pérez']);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'tipo_comprobante' => 'B', 'nro_comprobante' => '0001-00000001',
        ]);

        app(StockService::class)->registrarSalida($producto, null, $deposito, 1, $venta);

        $respuesta = $this->getJson(route('informes.stock.data', ['draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();

        $this->assertSame(
            'B 0001-00000001 - Juan Pérez',
            collect($respuesta->json('data'))->pluck('detalle')->first()
        );
    }
}
