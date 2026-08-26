<?php

namespace Tests\Feature;

use App\Exports\ProductosExport;
use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\User;
use App\Services\Import\DefinicionCamposImportables;
use App\Services\Import\FuenteFilasImportacion;
use App\Services\Import\ImportadorFilas;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Spec 083, US3 — el circuito exportar → editar → reimportar.
 *
 * El defecto que motiva esto: la exportación escribe la columna "Precio venta" y el importador sólo
 * conocía la etiqueta "Precio de Venta". Sin coincidencia exacta el automapeo la dejaba en "No
 * importar" y el precio no se actualizaba nunca — así quedaron 124 productos en cero.
 */
class RoundTripExportImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
            @unlink(FuenteFilasImportacion::rutaNdjsonPara($ruta));
        }
        $this->temporales = [];

        parent::tearDown();
    }

    /** Misma normalización que usa el automapeo del Paso 2 (`ImportacionController::sugerirMapeo()`). */
    private function normalizar(string $valor): string
    {
        return Str::of($valor)->lower()->ascii()->trim()->replaceMatches('/\s+/', ' ')->toString();
    }

    /**
     * **El test que evita la próxima vez.** Recorre TODOS los encabezados que escribe
     * `ProductosExport` y falla listando los que ningún campo importable reconoce. Un encabezado
     * huérfano es, por definición, un dato que el round-trip pierde en silencio.
     *
     * FR-016.
     */
    public function test_ningun_encabezado_del_export_de_productos_queda_huerfano(): void
    {
        $listas = collect([ListaPrecio::create(['nombre' => 'AHORA 12', 'activo' => true])]);
        $depositos = collect([Deposito::create(['nombre' => 'Central', 'activo' => true])]);

        $encabezados = (new ProductosExport(Producto::query(), $listas, $depositos))->headings();

        $conocidos = [];
        foreach (DefinicionCamposImportables::productos() as $def) {
            $conocidos[$this->normalizar($def['etiqueta'])] = true;
            foreach ((array) ($def['alias'] ?? []) as $alias) {
                if ($alias !== '' && $alias !== null) {
                    $conocidos[$this->normalizar((string) $alias)] = true;
                }
            }
        }

        $huerfanos = array_values(array_filter(
            $encabezados,
            fn ($encabezado) => ! isset($conocidos[$this->normalizar((string) $encabezado)])
        ));

        $this->assertSame(
            [],
            $huerfanos,
            'Estas columnas del export de Productos no las reconoce ningún campo importable: '.implode(', ', $huerfanos)
        );
    }

    /**
     * FR-017 / SC-005: exportar y reimportar sin tocar nada deja los valores idénticos. Es el
     * chequeo de punta a punta del que sale la confianza en la edición masiva por Excel.
     */
    public function test_exportar_y_reimportar_sin_cambios_no_altera_ningun_valor(): void
    {
        $deposito = Deposito::create(['nombre' => 'Central', 'activo' => true]);
        $lista = ListaPrecio::create(['nombre' => 'AHORA 12', 'activo' => true]);

        $producto = Producto::factory()->create([
            'nombre' => 'Producto Round Trip',
            'codigo' => 'RT-1',
            'precio_venta' => 1234.56,
            'costo' => 789.01,
            'punto_reposicion' => 7,
        ]);
        $producto->precios()->create(['lista_precio_id' => $lista->id, 'precio' => 1500.25]);
        app(StockService::class)->ajustar($producto, null, $deposito, 13, 'Registro inicial (test)', null);

        $esperado = [
            'precio_venta' => (float) $producto->fresh()->precio_venta,
            'costo' => (float) $producto->fresh()->costo,
            'punto_reposicion' => (float) $producto->fresh()->punto_reposicion,
            'precio_lista' => 1500.25,
            'stock' => 13.0,
        ];

        // Exportar por la ruta real de la pantalla de Productos, no armando el export a mano: las
        // columnas de stock y de cada lista de precios salen de subconsultas que agrega
        // `ProductoController::queryFiltrada()`, así que un export construido con
        // `Producto::query()` pelado las escribiría en cero y el round-trip no probaría nada.
        $ruta = tempnam(sys_get_temp_dir(), 'roundtrip').'.xlsx';
        $this->temporales[] = $ruta;

        $descarga = $this->actingAs(User::factory()->create())->get(route('productos.export'));
        $descarga->assertOk();
        file_put_contents($ruta, $descarga->streamedContent());

        // Reimportar el archivo exportado, mapeando por el mismo automapeo del Paso 2.
        $encabezados = (new ProductosExport(Producto::query(), collect([$lista]), collect([$deposito])))->headings();
        $mapeo = $this->automapear($encabezados);

        // Las columnas de dinero y stock del export TIENEN que estar mapeadas: si alguna quedó
        // afuera, el round-trip no prueba nada.
        foreach (['precio_venta', 'costo', 'punto_reposicion', "precio_lista_{$lista->id}", "stock_deposito_{$deposito->id}"] as $campo) {
            $this->assertContains($campo, $mapeo, "El automapeo dejó \"{$campo}\" sin mapear: el round-trip perdería ese dato.");
        }

        $resultado = (new ImportadorFilas(app(StockService::class)))
            ->importar('productos', $ruta, $mapeo, [], null, $encabezados);

        $this->assertSame([], $resultado['fallidos']);
        $this->assertSame(1, Producto::count(), 'Reimportar el propio export no puede duplicar productos.');

        $fresco = $producto->fresh();
        $this->assertEquals($esperado['precio_venta'], (float) $fresco->precio_venta);
        $this->assertEquals($esperado['costo'], (float) $fresco->costo);
        $this->assertEquals($esperado['punto_reposicion'], (float) $fresco->punto_reposicion);
        $this->assertEquals($esperado['precio_lista'], (float) $fresco->precios()->where('lista_precio_id', $lista->id)->firstOrFail()->precio);
        $this->assertEquals($esperado['stock'], (float) $fresco->stocks()->where('deposito_id', $deposito->id)->firstOrFail()->cantidad);
    }

    /**
     * FR-015: no existe export de Clientes ni de Proveedores (verificado el 26/08/2026: la única
     * clase en `app/Exports/` que exporta datos importables es `ProductosExport`). Este test lo deja
     * fijado: si algún día se agrega uno, hay que extenderle el chequeo de encabezados huérfanos.
     */
    public function test_no_existe_export_de_clientes_ni_de_proveedores(): void
    {
        $this->assertFileDoesNotExist(app_path('Exports/ClientesExport.php'));
        $this->assertFileDoesNotExist(app_path('Exports/ProveedoresExport.php'));
    }

    /**
     * Réplica del automapeo del Paso 2 sobre una lista de encabezados.
     *
     * @param  array<int, mixed>  $encabezados
     * @return array<int, string>
     */
    private function automapear(array $encabezados): array
    {
        $porNombre = [];
        foreach (DefinicionCamposImportables::productos() as $campo => $def) {
            $porNombre[$this->normalizar($def['etiqueta'])] = $campo;
            foreach ((array) ($def['alias'] ?? []) as $alias) {
                if ($alias !== '' && $alias !== null) {
                    $porNombre[$this->normalizar((string) $alias)] = $campo;
                }
            }
        }

        $mapeo = [];
        $usados = [];
        foreach ($encabezados as $indice => $encabezado) {
            $campo = $porNombre[$this->normalizar((string) $encabezado)] ?? null;
            if ($campo === null || isset($usados[$campo])) {
                continue;
            }
            $mapeo[$indice] = $campo;
            $usados[$campo] = true;
        }

        return $mapeo;
    }
}
