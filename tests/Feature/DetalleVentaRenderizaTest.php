<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La pantalla de detalle de una venta dejó de compilar y tiraba 500 en
 * producción: una directiva @php(...) en el bloque de datos del cliente rompía
 * el parser de Blade y todo lo que seguía quedaba sin compilar. Este test pide
 * la pantalla de verdad, que es lo único que detecta esa clase de error — una
 * vista se compila recién al renderizarla.
 */
class DetalleVentaRenderizaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La pantalla está detrás de un permiso; el rol Admin los cubre todos.
        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);
    }

    public function test_el_detalle_de_una_venta_responde_sin_romperse(): void
    {
        $venta = Venta::factory()->create();

        $this->get(route('ventas.show', $venta))->assertOk();
    }

    public function test_el_detalle_muestra_el_domicilio_fiscal_cuando_no_hay_comercial(): void
    {
        $cliente = Cliente::factory()->create([
            'domicilio' => '',
            'domicilio_fiscal' => 'CHORROARIN AV. 1015',
        ]);

        $venta = Venta::factory()->create(['cliente_id' => $cliente->id]);

        $this->get(route('ventas.show', $venta))
            ->assertOk()
            ->assertSee('CHORROARIN AV. 1015');
    }

    public function test_una_razon_social_no_muestra_las_filas_de_nombre_y_apellido(): void
    {
        $cliente = Cliente::factory()->create([
            'nombre' => 'BRISTOL MEDICINE SRL',
            'nombre_pila' => null,
            'apellido' => null,
        ]);

        $venta = Venta::factory()->create(['cliente_id' => $cliente->id]);

        $this->get(route('ventas.show', $venta))
            ->assertOk()
            ->assertDontSee('<strong>Nombre:</strong>', false)
            ->assertDontSee('<strong>Apellido:</strong>', false);
    }
}
