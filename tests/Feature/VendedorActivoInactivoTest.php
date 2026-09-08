<?php

namespace Tests\Feature;

use App\Models\ConfiguracionVentas;
use App\Models\Rol;
use App\Models\Vendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 101: un vendedor desactivado desaparece de los selects de asignación nuevos
 * (Crear Venta, Crear Presupuesto) pero sigue resolviendo su nombre en registros ya
 * emitidos (Principio IV: bajo riesgo pero varios puntos de consumo).
 */
class VendedorActivoInactivoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_crea_renombra_y_alterna_estado_de_un_vendedor(): void
    {
        $this->postJson(route('vendedores.store'), ['nombre' => 'Juan Pérez'])
            ->assertCreated()->assertJsonPath('ok', true);

        $vendedor = Vendedor::where('nombre', 'Juan Pérez')->firstOrFail();
        $this->assertTrue($vendedor->activo);

        $this->patchJson(route('vendedores.update', $vendedor), ['nombre' => 'Juan Pérez 2'])
            ->assertOk();
        $this->assertSame('Juan Pérez 2', $vendedor->fresh()->nombre);

        $this->patchJson(route('vendedores.estado', $vendedor))
            ->assertOk()->assertJsonPath('activo', false);
        $this->assertFalse($vendedor->fresh()->activo);
        $this->assertCount(0, Vendedor::activos()->get()->filter(fn ($v) => $v->id === $vendedor->id));

        $this->patchJson(route('vendedores.estado', $vendedor))
            ->assertOk()->assertJsonPath('activo', true);
    }

    public function test_vendedor_inactivo_no_aparece_en_crear_venta_ni_crear_presupuesto(): void
    {
        $activo = Vendedor::create(['nombre' => 'Activo', 'activo' => true]);
        $inactivo = Vendedor::create(['nombre' => 'Inactivo', 'activo' => false]);

        $responseVenta = $this->get(route('ventas.create'));
        $responseVenta->assertOk();
        $vendedoresVenta = $responseVenta->viewData('vendedores')->pluck('id');
        $this->assertTrue($vendedoresVenta->contains($activo->id));
        $this->assertFalse($vendedoresVenta->contains($inactivo->id));

        $responsePresupuesto = $this->get(route('presupuestos.create'));
        $responsePresupuesto->assertOk();
        $vendedoresPresupuesto = $responsePresupuesto->viewData('vendedores')->pluck('id');
        $this->assertTrue($vendedoresPresupuesto->contains($activo->id));
        $this->assertFalse($vendedoresPresupuesto->contains($inactivo->id));
    }

    public function test_vendedor_por_defecto_inactivo_no_se_precarga_en_crear_venta(): void
    {
        $vendedor = Vendedor::create(['nombre' => 'Ex vendedor', 'activo' => false]);
        ConfiguracionVentas::query()->update(['vendedor_id' => $vendedor->id]);

        $response = $this->get(route('ventas.create'));

        $response->assertOk();
        $defaults = $response->viewData('defaults');
        $this->assertNull($defaults['vendedorId'] ?? null);

        $responseConfiguracion = $this->get(route('configuracion.index'));
        $responseConfiguracion->assertOk();
        $this->assertTrue($responseConfiguracion->viewData('vendedorPorDefectoInactivo'));
    }
}
