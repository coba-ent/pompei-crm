<?php

namespace Tests\Feature\Compras;

use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El select de Proveedor del formulario de Compra permite crear/editar la ficha completa
 * sin salir de la pantalla, igual que el select de Cliente en Ventas.
 *
 * Lo que estos casos protegen no es el JS sino el contrato Blade→controlador: el partial
 * `proveedores._modal_form` usa `$condicionesIva`, `$categorias` y `$provincias`, que
 * CompraController NO pasaba antes de este cambio. Sin ellas la vista revienta al renderizar,
 * y es un error que no aparece en ningún test de alta/edición por endpoint.
 */
class ProveedorDesdeFormularioCompraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );
    }

    public function test_el_alta_de_compra_renderiza_el_modal_de_proveedor(): void
    {
        $resp = $this->get(route('compras.create'));

        $resp->assertOk();
        $resp->assertSee('modal-proveedor', false);
        $resp->assertSee('form-proveedor', false);
        // Las rutas que consume ProveedorModal.init().
        $resp->assertSee('proveedoresStore', false);
        $resp->assertSee('proveedoresVerificarDocumento', false);
    }

    public function test_la_edicion_de_compra_renderiza_el_modal_de_proveedor(): void
    {
        $compra = Compra::factory()->create(['proveedor_id' => Proveedor::factory()->create()->id]);

        $resp = $this->get(route('compras.edit', $compra));

        $resp->assertOk();
        $resp->assertSee('modal-proveedor', false);
        $resp->assertSee('proveedoresStore', false);
    }
}
