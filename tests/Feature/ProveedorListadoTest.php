<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-004: columnas del listado (espejo de Cliente, sin "Usuario de Mercado Libre") y búsqueda global. */
class ProveedorListadoTest extends TestCase
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
            'nota', 'pagina_web', 'acciones',
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

    public function test_data_devuelve_las_columnas_esperadas_sin_usuario_ml(): void
    {
        Proveedor::create([
            'nombre' => 'Distribuidora Norte',
            'nombre_pila' => 'Juan',
            'apellido' => 'Pérez',
            'email' => 'juan@norte.com',
            'telefono' => '11-1111',
            'telefono_celular' => '11-2222',
            'domicilio' => 'Av. Siempre Viva 742',
            'localidad' => 'Springfield',
            'provincia' => 'Buenos Aires',
            'tipo_documento' => 'DNI',
            'cuit' => '12345678',
            'nota' => 'Nota de prueba',
            'pagina_web' => 'norte.com',
        ]);

        $resp = $this->getJson(route('proveedores.data').'?draw=1&start=0&length=10')
            ->assertOk()->json();

        $this->assertCount(1, $resp['data']);
        $fila = $resp['data'][0];

        foreach ([
            'id', 'nombre', 'nombre_pila', 'apellido', 'email', 'telefono', 'telefono_celular',
            'domicilio', 'localidad', 'provincia', 'doc_dni', 'doc_cuit', 'condicion_iva',
            'nota', 'pagina_web', 'acciones',
        ] as $columna) {
            $this->assertArrayHasKey($columna, $fila);
        }

        $this->assertArrayNotHasKey('usuario_ml', $fila);
        $this->assertArrayNotHasKey('apodo_ml', $fila);
        $this->assertSame('12345678', $fila['doc_dni']);
        $this->assertSame('', $fila['doc_cuit']);
    }

    public function test_busqueda_global_encuentra_por_nombre(): void
    {
        Proveedor::create(['nombre' => 'Alfa Insumos']);
        Proveedor::create(['nombre' => 'Beta Repuestos']);

        $response = $this->getJson(route('proveedores.data').'?'.http_build_query(
            $this->datatablesParams(['search' => ['value' => 'Beta', 'regex' => 'false']])
        ));

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Beta Repuestos', $data[0]['nombre']);
    }

    public function test_busqueda_global_encuentra_por_cuit(): void
    {
        Proveedor::create(['nombre' => 'Con CUIT', 'cuit' => '20111111112']);
        Proveedor::create(['nombre' => 'Sin CUIT']);

        $response = $this->getJson(route('proveedores.data').'?'.http_build_query(
            $this->datatablesParams(['search' => ['value' => '20111111112', 'regex' => 'false']])
        ));

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Con CUIT', $data[0]['nombre']);
    }
}
