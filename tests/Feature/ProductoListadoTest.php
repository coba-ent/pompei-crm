<?php

namespace Tests\Feature;

use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoListadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Parámetros que envía el cliente DataTables (incluye metadata de columnas
     * para que yajra aplique la búsqueda global).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function datatablesParams(array $extra = []): array
    {
        $columnas = ['nombre', 'codigo', 'tipo', 'precio_venta', 'stock_total', 'activo', 'acciones'];
        $noBuscables = ['stock_total', 'activo', 'acciones'];

        $columns = [];
        foreach ($columnas as $i => $name) {
            $columns[$i] = [
                'data' => $name,
                'name' => $name,
                'searchable' => in_array($name, $noBuscables, true) ? 'false' : 'true',
                'orderable' => 'true',
                'search' => ['value' => '', 'regex' => 'false'],
            ];
        }

        return array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'columns' => $columns,
            'order' => [['column' => 0, 'dir' => 'asc']],
            'search' => ['value' => '', 'regex' => 'false'],
        ], $extra);
    }

    public function test_data_devuelve_formato_datatables(): void
    {
        Producto::create(['nombre' => 'Alfa', 'tipo' => 'producto']);

        $response = $this->getJson(route('productos.data').'?draw=1&start=0&length=10');

        $response->assertOk()->assertJsonStructure([
            'draw', 'recordsTotal', 'recordsFiltered', 'data',
        ]);
    }

    public function test_busqueda_por_nombre_y_sku(): void
    {
        Producto::create(['nombre' => 'Zapato cuero', 'tipo' => 'producto', 'codigo' => 'ZAP-1']);
        Producto::create(['nombre' => 'Remera', 'tipo' => 'producto', 'codigo' => 'REM-9']);

        $porNombre = $this->getJson(route('productos.data').'?'.http_build_query(
            $this->datatablesParams(['search' => ['value' => 'Zapato', 'regex' => 'false']])
        ));
        $this->assertCount(1, $porNombre->json('data'));
        $this->assertSame('Zapato cuero', $porNombre->json('data')[0]['nombre']);

        $porSku = $this->getJson(route('productos.data').'?'.http_build_query(
            $this->datatablesParams(['search' => ['value' => 'REM-9', 'regex' => 'false']])
        ));
        $this->assertCount(1, $porSku->json('data'));
        $this->assertSame('Remera', $porSku->json('data')[0]['nombre']);
    }

    public function test_index_incluye_columna_de_checkbox_de_seleccion(): void
    {
        $response = $this->get(route('productos.index'));

        $response->assertOk();
        $response->assertSee('chk-seleccionar-todo', false);
        $response->assertSee('modal-acciones-masivas', false);
    }

    public function test_filtro_estado_y_tipo(): void
    {
        Producto::create(['nombre' => 'Activo', 'tipo' => 'producto', 'activo' => true]);
        Producto::create(['nombre' => 'Inactivo', 'tipo' => 'producto', 'activo' => false]);
        Producto::create(['nombre' => 'Servicio', 'tipo' => 'servicio', 'activo' => true]);

        $activos = $this->getJson(route('productos.data').'?draw=1&start=0&length=10&estado=activos');
        $activos->assertOk()->assertJsonPath('recordsFiltered', 2);

        $servicios = $this->getJson(route('productos.data').'?draw=1&start=0&length=10&estado=todos&tipo=servicio');
        $servicios->assertOk()->assertJsonPath('recordsFiltered', 1);
    }
}
