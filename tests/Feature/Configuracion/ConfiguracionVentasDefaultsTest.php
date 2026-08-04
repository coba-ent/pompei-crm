<?php

namespace Tests\Feature\Configuracion;

use App\Models\Categoria;
use App\Models\ConfiguracionVentas;
use App\Models\Presupuesto;
use App\Models\Rol;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Spec 043 (US3): defaults de "Crear Venta" configurados en Configuración & Ajustes → Ventas. */
class ConfiguracionVentasDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = Rol::create(['nombre' => 'Admin', 'es_sistema' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($admin->id);
        $this->actingAs($user);

        return $user;
    }

    public function test_crear_venta_precarga_los_defaults_configurados(): void
    {
        $this->actingAsAdmin();
        Carbon::setTestNow('2026-08-04');

        $categoria = Categoria::factory()->create(['tipo' => 'venta', 'activo' => true]);
        $vendedor = Vendedor::create(['nombre' => 'Vendedor Test']);

        ConfiguracionVentas::create([
            'categoria_id' => $categoria->id,
            'vendedor_id' => $vendedor->id,
            'tipo_comprobante' => 'A',
            'dias_vto_cobro' => 15,
        ]);

        $response = $this->get(route('ventas.create'));

        $response->assertOk();
        $response->assertSee('"categoriaId":'.$categoria->id, false);
        $response->assertSee('"vendedorId":'.$vendedor->id, false);
        $response->assertSee('"tipoComprobante":"A"', false);
        $response->assertSee('"fechaVtoCobro":"2026-08-19"', false);

        Carbon::setTestNow();
    }

    public function test_sin_configuracion_no_hay_defaults(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('ventas.create'));

        $response->assertOk();
        $response->assertSee('defaults: null', false);
    }

    public function test_editar_venta_existente_no_aplica_defaults(): void
    {
        $user = $this->actingAsAdmin();
        $categoria = Categoria::factory()->create(['tipo' => 'venta', 'activo' => true]);
        ConfiguracionVentas::create(['categoria_id' => $categoria->id, 'dias_vto_cobro' => 15]);

        $venta = Venta::factory()->create();

        $response = $this->get(route('ventas.edit', $venta));

        $response->assertOk();
        $response->assertSee('defaults: null', false);
    }

    public function test_default_de_categoria_eliminada_no_rompe_crear_venta(): void
    {
        $this->actingAsAdmin();
        $categoria = Categoria::factory()->create(['tipo' => 'venta', 'activo' => true]);
        ConfiguracionVentas::create(['categoria_id' => $categoria->id]);
        $categoria->delete();

        $response = $this->get(route('ventas.create'));

        $response->assertOk();
    }

    public function test_default_de_categoria_inactiva_no_se_precarga(): void
    {
        $this->actingAsAdmin();
        $categoria = Categoria::factory()->create(['tipo' => 'venta', 'activo' => false]);
        ConfiguracionVentas::create(['categoria_id' => $categoria->id]);

        $response = $this->get(route('ventas.create'));

        $response->assertOk();
        $response->assertSee('"categoriaId":null', false);
    }
}
