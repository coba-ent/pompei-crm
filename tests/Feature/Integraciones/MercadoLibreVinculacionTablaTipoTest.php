<?php

namespace Tests\Feature\Integraciones;

use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 115 (US2): la tabla de Vinculaciones muestra el tipo de publicación con su filtro y
 * permite encontrar un vínculo por el ID o el código del producto del CRM (FR-016..FR-018).
 */
class MercadoLibreVinculacionTablaTipoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder)->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);
        Deposito::firstOrCreate(['nombre' => 'Principal'], ['activo' => true]);
    }

    private function vinculo(string $mlItemId, ?string $tipo, array $producto = []): MercadoLibrePublicacionProducto
    {
        $p = Producto::factory()->create($producto);

        return MercadoLibrePublicacionProducto::create([
            'ml_item_id' => $mlItemId, 'producto_id' => $p->id, 'listing_type_id' => $tipo, 'precio_publicado' => 1000,
        ]);
    }

    /** Parámetros como los manda DataTables, con las columnas buscables de la pantalla. */
    private function datatable(array $extra = [], string $busqueda = ''): array
    {
        $columnas = collect(['ml_item_id', 'titulo_ml', 'producto.nombre'])->map(fn ($nombre) => [
            'data' => $nombre === 'producto.nombre' ? 'producto_nombre' : $nombre,
            'name' => $nombre, 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false'],
        ])->all();

        return $this->getJson(route('ingresos.mercadolibre.vinculaciones.datatable', [
            'draw' => 1, 'start' => 0, 'length' => 50, 'columns' => $columnas,
            'search' => ['value' => $busqueda, 'regex' => 'false'],
            ...$extra,
        ]))->assertOk()->json('data');
    }

    public function test_devuelve_tipo_y_producto_del_crm(): void
    {
        $v = $this->vinculo('MLA1', 'gold_pro', ['codigo' => 'TAP-01', 'nombre' => 'Canilla']);
        $this->vinculo('MLA2', 'gold_special');
        $this->vinculo('MLA3', null);

        $filas = collect($this->datatable())->keyBy('ml_item_id');

        $this->assertSame($v->producto_id, $filas['MLA1']['producto_id']);
        $this->assertSame('TAP-01', $filas['MLA1']['producto_codigo']);
        $this->assertSame('Premium', $filas['MLA1']['tipo_etiqueta']);
        $this->assertSame('gold_pro', $filas['MLA1']['listing_type_id']);
        $this->assertArrayHasKey('precio_publicado', $filas['MLA1']);
        $this->assertSame('Clásica', $filas['MLA2']['tipo_etiqueta']);
        $this->assertSame('Sin clasificar', $filas['MLA3']['tipo_etiqueta']);
    }

    public function test_filtra_por_tipo(): void
    {
        $this->vinculo('MLA1', 'gold_pro');
        $this->vinculo('MLA2', 'gold_special');
        $this->vinculo('MLA3', null);

        $this->assertSame(['MLA1'], array_column($this->datatable(['listing_type' => 'gold_pro']), 'ml_item_id'));
        $this->assertSame(['MLA2'], array_column($this->datatable(['listing_type' => 'gold_special']), 'ml_item_id'));
        $this->assertSame(['MLA3'], array_column($this->datatable(['listing_type' => 'sin_clasificar']), 'ml_item_id'));
        $this->assertCount(3, $this->datatable(['listing_type' => '']));
    }

    public function test_busca_por_id_codigo_y_nombre_del_producto(): void
    {
        $buscado = $this->vinculo('MLA1', 'gold_pro', ['codigo' => 'TAP-XYZ', 'nombre' => 'Grifería monocomando']);
        $this->vinculo('MLA2', 'gold_special', ['codigo' => 'OTRO-1', 'nombre' => 'Inodoro']);

        $this->assertSame(['MLA1'], array_column($this->datatable(busqueda: 'TAP-XYZ'), 'ml_item_id'));
        $this->assertSame(['MLA1'], array_column($this->datatable(busqueda: 'monocomando'), 'ml_item_id'));
        $this->assertContains('MLA1', array_column($this->datatable(busqueda: (string) $buscado->producto_id), 'ml_item_id'));
    }
}
