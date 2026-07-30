<?php

namespace Tests\Feature;

use App\Models\Presupuesto;
use App\Models\Vendedor;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ABM inline de Vendedores (spec 020, FR-002/FR-004/FR-005/FR-006): crear, rechazar
 * duplicado, renombrar, y el bloqueo de borrado por uso — la única regla de integridad
 * de esta feature (constitución principio IV).
 */
class VendedorTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_vendedor_y_rechaza_nombre_duplicado(): void
    {
        $this->postJson(route('vendedores.store'), ['nombre' => 'Juan Pérez'])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('vendedor.nombre', 'Juan Pérez');

        $this->assertDatabaseHas('vendedores', ['nombre' => 'Juan Pérez']);

        $this->postJson(route('vendedores.store'), ['nombre' => 'Juan Pérez'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');
    }

    public function test_renombra_vendedor(): void
    {
        $vendedor = Vendedor::create(['nombre' => 'Original']);

        $this->patchJson(route('vendedores.update', $vendedor), ['nombre' => 'Renombrado'])
            ->assertOk()
            ->assertJsonPath('vendedor.nombre', 'Renombrado');

        $this->assertSame('Renombrado', $vendedor->fresh()->nombre);
    }

    public function test_elimina_vendedor_sin_uso(): void
    {
        $vendedor = Vendedor::create(['nombre' => 'Sin uso']);

        $this->deleteJson(route('vendedores.destroy', $vendedor))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('vendedores', ['id' => $vendedor->id]);
    }

    public function test_rechaza_eliminar_vendedor_con_venta_asociada(): void
    {
        $vendedor = Vendedor::create(['nombre' => 'Con venta']);
        Venta::factory()->create(['vendedor_id' => $vendedor->id]);

        $this->deleteJson(route('vendedores.destroy', $vendedor))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('mensaje', 'No se puede eliminar: está en uso.');

        $this->assertDatabaseHas('vendedores', ['id' => $vendedor->id]);
    }

    public function test_rechaza_eliminar_vendedor_con_presupuesto_asociado(): void
    {
        $vendedor = Vendedor::create(['nombre' => 'Con presupuesto']);
        Presupuesto::factory()->create(['vendedor_id' => $vendedor->id]);

        $this->deleteJson(route('vendedores.destroy', $vendedor))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseHas('vendedores', ['id' => $vendedor->id]);
    }
}
