<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-006: no se elimina físicamente un proveedor con productos asociados; sí uno sin ellos. */
class ProveedorBajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_estado_alterna_activo(): void
    {
        $proveedor = Proveedor::create(['nombre' => 'Toggle', 'activo' => true]);

        $response = $this->patchJson(route('proveedores.estado', $proveedor));

        $response->assertOk()->assertJsonPath('activo', false);
        $this->assertFalse($proveedor->fresh()->activo);

        $this->patchJson(route('proveedores.estado', $proveedor))->assertJsonPath('activo', true);
    }

    public function test_destroy_elimina_proveedor_sin_productos_asociados(): void
    {
        $proveedor = Proveedor::create(['nombre' => 'Borrable']);

        $response = $this->deleteJson(route('proveedores.destroy', $proveedor));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('proveedores', ['id' => $proveedor->id]);
    }

    public function test_destroy_rechaza_proveedor_con_productos_asociados(): void
    {
        $proveedor = Proveedor::create(['nombre' => 'Con productos']);
        Producto::create(['nombre' => 'Producto ligado', 'tipo' => 'producto', 'proveedor_id' => $proveedor->id]);

        $response = $this->deleteJson(route('proveedores.destroy', $proveedor));

        $response->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('mensaje', 'Sólo puede inactivarse: el proveedor tiene productos asociados.');

        $this->assertDatabaseHas('proveedores', ['id' => $proveedor->id]);
    }
}
