<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteListadoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Construye los parámetros que envía el cliente DataTables (incluye la
     * metadata de columnas necesaria para que yajra aplique la búsqueda global).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function datatablesParams(array $extra = []): array
    {
        $columnas = [
            'id', 'nombre', 'nombre_pila', 'apellido', 'email', 'telefono', 'telefono_celular',
            'domicilio', 'localidad', 'provincia', 'doc_dni', 'doc_cuit', 'condicion_iva',
            'apodo_ml', 'nota', 'pagina_web', 'acciones',
        ];
        $noBuscables = ['doc_dni', 'doc_cuit', 'condicion_iva', 'acciones'];

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
        Cliente::create(['nombre' => 'Alfa']);

        $response = $this->getJson(route('clientes.data').'?draw=1&start=0&length=10');

        $response->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
    }

    public function test_busqueda_por_nombre(): void
    {
        Cliente::create(['nombre' => 'Ferretería Norte']);
        Cliente::create(['nombre' => 'Panadería Sur']);

        $response = $this->getJson(route('clientes.data').'?'.http_build_query(
            $this->datatablesParams(['search' => ['value' => 'Ferre', 'regex' => 'false']])
        ));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Ferretería Norte', $data[0]['nombre']);
    }

    public function test_busqueda_por_cuit(): void
    {
        Cliente::create(['nombre' => 'Con CUIT', 'cuit' => '20111111112']);
        Cliente::create(['nombre' => 'Sin CUIT']);

        $response = $this->getJson(route('clientes.data').'?'.http_build_query(
            $this->datatablesParams(['search' => ['value' => '20111111112', 'regex' => 'false']])
        ));

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Con CUIT', $data[0]['nombre']);
    }

    public function test_filtro_estado_inactivos(): void
    {
        Cliente::create(['nombre' => 'Activo', 'activo' => true]);
        Cliente::create(['nombre' => 'Inactivo', 'activo' => false]);

        $response = $this->getJson(route('clientes.data').'?draw=1&start=0&length=10&estado=inactivos');

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Inactivo', $data[0]['nombre']);
    }

    public function test_filtro_por_categoria(): void
    {
        $cat = Categoria::create(['tipo' => 'venta', 'nombre' => 'Mayoristas']);
        Cliente::create(['nombre' => 'De categoría', 'categoria_id' => $cat->id]);
        Cliente::create(['nombre' => 'Sin categoría']);

        $response = $this->getJson(route('clientes.data')."?draw=1&start=0&length=10&categoria_id={$cat->id}");

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('De categoría', $data[0]['nombre']);
    }
}
