<?php

namespace Tests\Feature;

use App\Enums\MercadoLibre\EstadoConexion;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\ListaPrecio;
use App\Models\LogAuditoria;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\AuditoriaService;
use App\Services\Import\ImportadorFilas;
use App\Services\Stock\StockService;
use App\Support\OrigenCambioPrecio;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * US1 de la spec 074: todo cambio de precio por lista queda auditado con su valor anterior,
 * su valor nuevo y el origen que lo produjo (FR-006 a FR-014, criterios CV-1 a CV-9).
 */
class AuditoriaPrecioProductoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    /** @return Collection<int, LogAuditoria> */
    private function eventosDePrecio()
    {
        return LogAuditoria::where('tipo_operacion', 'precio_producto')->orderBy('id')->get();
    }

    private function archivo(array $filas): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $f => $valores) {
            foreach (array_values($valores) as $c => $valor) {
                $sheet->setCellValueByColumnAndRow($c + 1, $f + 1, $valor);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /** CV-1: cambio de precio por importación. */
    public function test_cambio_por_importacion_genera_evento_modifico_con_rotulo_importacion(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Caño PVC', 'codigo' => 'IMP-1']);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 100]);

        // Los `create()` del arreglo generan sus propios eventos `creo` legítimos;
        // se limpian para que el test mida sólo lo que produce la acción bajo prueba.
        LogAuditoria::query()->delete();

        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Precio'],
            [$producto->id, 'Caño PVC', 250],
        ]);

        (new ImportadorFilas(app(StockService::class)))->importar('productos', $ruta, [
            0 => 'id',
            1 => 'nombre',
            2 => "precio_lista_{$lista->id}",
        ], []);

        $eventos = $this->eventosDePrecio();
        $this->assertCount(1, $eventos);
        $this->assertSame('modifico', $eventos[0]->tipo_accion);
        $this->assertSame(Producto::class, $eventos[0]->entidad_tipo);
        $this->assertSame($producto->id, (int) $eventos[0]->entidad_id);
        $this->assertEqualsWithDelta(250.0, (float) $eventos[0]->total, 0.001);
        $this->assertStringContainsString('100,00', $eventos[0]->detalle);
        $this->assertStringContainsString('250,00', $eventos[0]->detalle);
        $this->assertStringContainsString('(importación)', $eventos[0]->detalle);
    }

    /** CV-2: edición manual desde la ficha del producto. */
    public function test_cambio_por_edicion_manual_genera_evento_con_rotulo_edicion_manual(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Codo 90', 'codigo' => 'MAN-1']);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 100]);

        // Los `create()` del arreglo generan sus propios eventos `creo` legítimos;
        // se limpian para que el test mida sólo lo que produce la acción bajo prueba.
        LogAuditoria::query()->delete();

        $this->putJson(route('productos.update', $producto), [
            'nombre' => 'Codo 90',
            'tipo' => 'producto',
            'precios' => [['lista_precio_id' => $lista->id, 'precio' => 175]],
        ])->assertOk();

        $eventos = $this->eventosDePrecio();
        $this->assertCount(1, $eventos);
        $this->assertSame('modifico', $eventos[0]->tipo_accion);
        $this->assertStringContainsString('(edición manual)', $eventos[0]->detalle);
    }

    /** CV-3: edición masiva genera un evento por cada precio efectivamente modificado. */
    public function test_edicion_masiva_genera_un_evento_por_precio_modificado(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);

        $uno = Producto::create(['nombre' => 'Producto Uno', 'codigo' => 'EM-1']);
        $uno->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 100]);
        $dos = Producto::create(['nombre' => 'Producto Dos', 'codigo' => 'EM-2']);
        $dos->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 200]);

        // Los `create()` del arreglo generan sus propios eventos `creo` legítimos;
        // se limpian para que el test mida sólo lo que produce la acción bajo prueba.
        LogAuditoria::query()->delete();

        $this->postJson(route('productos.acciones-masivas'), [
            'accion' => 'precio_venta',
            'ids' => [$uno->id, $dos->id],
            'modo' => 'porcentaje',
            'campos' => ["lista_{$lista->id}" => ['valor' => 10, 'signo' => 'aumentar']],
        ])->assertOk();

        $eventos = $this->eventosDePrecio();
        $this->assertCount(2, $eventos);

        foreach ($eventos as $evento) {
            $this->assertSame('modifico', $evento->tipo_accion);
            $this->assertStringContainsString('(edición masiva)', $evento->detalle);
        }

        $this->assertEqualsWithDelta(110.0, (float) $eventos[0]->total, 0.001);
        $this->assertEqualsWithDelta(220.0, (float) $eventos[1]->total, 0.001);
    }

    /** CV-4: quitar una lista al guardar el producto genera un evento `elimino`. */
    public function test_quitar_una_lista_de_precios_genera_evento_elimino_con_el_valor_que_tenia(): void
    {
        $general = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $mayorista = ListaPrecio::create(['nombre' => 'Mayorista', 'activo' => true]);

        $producto = Producto::create(['nombre' => 'Rejilla', 'codigo' => 'DEL-1']);
        $producto->precios()->create(['lista_precio_id' => $general->id, 'precio' => 100]);
        $producto->precios()->create(['lista_precio_id' => $mayorista->id, 'precio' => 80]);

        // Los `create()` del arreglo generan sus propios eventos `creo` legítimos;
        // se limpian para que el test mida sólo lo que produce la acción bajo prueba.
        LogAuditoria::query()->delete();

        // Se guarda sólo con la lista General: la Mayorista se quita.
        $this->putJson(route('productos.update', $producto), [
            'nombre' => 'Rejilla',
            'tipo' => 'producto',
            'precios' => [['lista_precio_id' => $general->id, 'precio' => 100]],
        ])->assertOk();

        $eliminados = $this->eventosDePrecio()->where('tipo_accion', 'elimino')->values();
        $this->assertCount(1, $eliminados);
        $this->assertNull($eliminados[0]->total);
        $this->assertStringContainsString('Mayorista', $eliminados[0]->detalle);
        $this->assertStringContainsString('80,00', $eliminados[0]->detalle);
        $this->assertStringContainsString('sin precio', $eliminados[0]->detalle);

        $this->assertSame(1, $producto->precios()->count());
    }

    /** CV-5 / FR-010 / SC-004: guardar el mismo precio no genera ningún evento. */
    public function test_guardar_el_mismo_precio_no_genera_ningun_evento(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Tapa', 'codigo' => 'EQ-1']);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 100]);

        LogAuditoria::query()->delete();

        // 100 vs 100.00: mismo valor normalizado a 2 decimales, no es un cambio.
        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => '100.00']);
        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => 100]);

        $this->assertCount(0, $this->eventosDePrecio());
    }

    /** CV-6: asignar precio a una lista que no tenía. */
    public function test_asignar_precio_a_lista_nueva_genera_evento_creo_sin_precio_anterior(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Bacha', 'codigo' => 'NEW-1']);

        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 320]);

        $eventos = $this->eventosDePrecio();
        $this->assertCount(1, $eventos);
        $this->assertSame('creo', $eventos[0]->tipo_accion);
        $this->assertStringContainsString('sin precio → 320,00', $eventos[0]->detalle);
        $this->assertEqualsWithDelta(320.0, (float) $eventos[0]->total, 0.001);
    }

    /**
     * CV-7 / FR-012: con la escritura de auditoría rota, la operación termina bien igual.
     * Cubre los DOS puntos de fallo: el evento suelto (edición manual) y el vaciado del
     * buffer (importación), que puede arrastrar hasta 200 eventos de una.
     */
    public function test_un_fallo_de_auditoria_no_aborta_el_guardado_del_precio(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Flexible', 'codigo' => 'FAIL-1']);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 100]);

        // Rompe la tabla de auditoría: cualquier INSERT (suelto o en lote) va a fallar.
        Schema::drop('logs_auditoria');

        // Camino 1: evento suelto (edición manual).
        $this->putJson(route('productos.update', $producto), [
            'nombre' => 'Flexible',
            'tipo' => 'producto',
            'precios' => [['lista_precio_id' => $lista->id, 'precio' => 150]],
        ])->assertOk();

        $this->assertEqualsWithDelta(150.0, (float) $producto->precios()->first()->precio, 0.001);

        // Camino 2: vaciado del buffer (importación).
        $ruta = $this->archivo([
            ['Id', 'Nombre', 'Precio'],
            [$producto->id, 'Flexible', 400],
        ]);

        $resultado = (new ImportadorFilas(app(StockService::class)))->importar('productos', $ruta, [
            0 => 'id',
            1 => 'nombre',
            2 => "precio_lista_{$lista->id}",
        ], []);

        $this->assertSame(1, $resultado['importados']);
        $this->assertEqualsWithDelta(400.0, (float) $producto->precios()->first()->precio, 0.001);
    }

    /** CV-9 / FR-017: la sincronización con las integraciones sigue disparándose igual. */
    public function test_el_cambio_de_precio_sigue_disparando_la_sincronizacion_de_integraciones(): void
    {
        FuncionAvanzadaSeeder::class;
        (new FuncionAvanzadaSeeder)->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        MercadoLibreConfiguracion::actual()->update([
            'client_id' => '123456789012',
            'client_secret' => 'clave-secreta-de-prueba-32chars',
            'site_id' => 'MLA',
        ]);
        MercadoLibreCuenta::create([
            'ml_user_id' => 1, 'nickname' => 'CUENTA', 'site_id' => 'MLA',
            'estado' => EstadoConexion::Conectada->value,
            'access_token' => 'atk', 'refresh_token' => 'rtk',
            'token_expira_en' => now()->addHours(3), 'vinculada_en' => now(),
        ]);
        Http::fake([
            'api.mercadolibre.com/items/*' => Http::response(['id' => 'MLA1'], 200),
        ]);

        $lista = ListaPrecio::create(['nombre' => 'Lista ML', 'activo' => true]);
        MercadoLibreConfiguracion::actual()->update(['lista_precio_id' => $lista->id]);

        $producto = Producto::create(['nombre' => 'Producto ML', 'codigo' => 'ML-1']);
        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA1', 'producto_id' => $producto->id,
        ]);

        $producto->precios()->updateOrCreate(['lista_precio_id' => $lista->id], ['precio' => 999.50]);

        // La integración se disparó...
        Http::assertSent(
            fn ($request) => str_contains($request->url(), '/items/MLA1') && $request['price'] === 999.5
        );
        // ...y la auditoría también, sin pisarse entre sí.
        $this->assertCount(1, $this->eventosDePrecio());
    }

    /** research D4: un camino que no declara origen queda auditado con el rótulo por defecto. */
    public function test_cambio_sin_origen_declarado_queda_auditado_como_origen_no_identificado(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Sin Origen', 'codigo' => 'SO-1']);

        // Escritura directa por modelo, sin envolver en OrigenCambioPrecio::durante().
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 55]);

        $eventos = $this->eventosDePrecio();
        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('(origen no identificado)', $eventos[0]->detalle);
    }

    /** El contexto de origen se restaura aunque el callable lance (contrato de `durante()`). */
    public function test_durante_restaura_el_origen_previo_incluso_si_el_callable_lanza(): void
    {
        $this->assertSame(OrigenCambioPrecio::DESCONOCIDO, OrigenCambioPrecio::actual());

        try {
            OrigenCambioPrecio::durante(OrigenCambioPrecio::IMPORTACION, function () {
                $this->assertSame(OrigenCambioPrecio::IMPORTACION, OrigenCambioPrecio::actual());
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame(OrigenCambioPrecio::DESCONOCIDO, OrigenCambioPrecio::actual());
    }

    /** El detalle nunca supera los 255 caracteres, y recorta el nombre, no los importes. */
    public function test_el_detalle_se_recorta_sacrificando_el_nombre_no_los_importes(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $nombreLargo = str_repeat('N', 255);
        $producto = Producto::create(['nombre' => $nombreLargo, 'codigo' => 'LARGO-1']);

        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 1234.56]);

        $evento = $this->eventosDePrecio()->first();
        $this->assertLessThanOrEqual(255, mb_strlen($evento->detalle));
        $this->assertStringContainsString('1.234,56', $evento->detalle);
        $this->assertStringContainsString('(origen no identificado)', $evento->detalle);
        $this->assertStringContainsString('General', $evento->detalle);
    }

    /** El buffer del importador agrupa los eventos en vez de escribirlos de a uno. */
    public function test_el_importador_agrupa_los_eventos_en_lote(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        Deposito::create(['nombre' => 'Central', 'activo' => true]);

        $filas = [['Id', 'Nombre', 'Precio']];
        for ($i = 1; $i <= 5; $i++) {
            $producto = Producto::create(['nombre' => "Producto {$i}", 'codigo' => "LOTE-{$i}"]);
            $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 100]);
            $filas[] = [$producto->id, "Producto {$i}", 100 + $i];
        }

        LogAuditoria::query()->delete();

        $ruta = $this->archivo($filas);

        $insertsDeAuditoria = 0;
        DB::listen(function ($query) use (&$insertsDeAuditoria) {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'logs_auditoria')) {
                $insertsDeAuditoria++;
            }
        });

        (new ImportadorFilas(app(StockService::class)))->importar('productos', $ruta, [
            0 => 'id',
            1 => 'nombre',
            2 => "precio_lista_{$lista->id}",
        ], []);

        // Los 5 eventos existen: el buffer se vació al terminar la tanda, no se perdieron.
        $this->assertCount(5, $this->eventosDePrecio());

        // Y entraron en UN solo INSERT, no en cinco: es la evidencia de que el lote agrupó
        // de verdad (SC-005). Sin el buffer, esto daría 5.
        $this->assertSame(1, $insertsDeAuditoria, 'los eventos deberían entrar en un único INSERT en lote');
    }

    /**
     * CV-8 / FR-011: la pantalla de Auditoría ofrece la operación nueva en el filtro y la
     * muestra en la columna "Operación". No hizo falta tocar la vista: la constante
     * LABELS_OPERACION del controlador alimenta a la vez el <select> y la columna.
     */
    public function test_la_pantalla_de_auditoria_ofrece_y_filtra_la_operacion_nueva(): void
    {
        $lista = ListaPrecio::create(['nombre' => 'General', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Visible', 'codigo' => 'VIS-1']);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 500]);

        // El <select> del filtro incluye la opción nueva.
        $this->get(route('auditoria.index'))
            ->assertOk()
            ->assertSee('value="precio_producto"', false)
            ->assertSee('Precio de producto');

        // Y el DataTable filtrado por esa operación devuelve el evento, con su label.
        $respuesta = $this->getJson(route('auditoria.data', ['operacion' => 'precio_producto']))
            ->assertOk()
            ->json();

        $this->assertCount(1, $respuesta['data']);
        $this->assertSame('Precio de producto', $respuesta['data'][0]['tipo_operacion_label']);
        $this->assertSame('Creó', $respuesta['data'][0]['tipo_accion_label']);
    }

    /** El buffer se vacía aunque el modo buffer nunca se haya encendido (idempotente). */
    public function test_vaciar_buffer_sin_iniciarlo_no_hace_nada(): void
    {
        app(AuditoriaService::class)->vaciarBuffer();

        $this->assertCount(0, $this->eventosDePrecio());
    }
}
