<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-001, FR-003: alta de proveedor con sólo el nombre requerido, y
 * validación de CUIT (reuso de CuitValido, mismo comportamiento que Cliente).
 */
class ProveedorAltaTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_proveedor_con_solo_el_nombre(): void
    {
        $response = $this->postJson(route('proveedores.store'), [
            'nombre' => 'Distribuidora Sur S.A.',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('proveedores', ['nombre' => 'Distribuidora Sur S.A.', 'activo' => true]);
    }

    public function test_rechaza_alta_sin_nombre(): void
    {
        $response = $this->postJson(route('proveedores.store'), [
            'nombre' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['errors' => ['nombre']]);

        $this->assertSame(0, Proveedor::count());
    }

    public function test_rechaza_cuit_matematicamente_invalido(): void
    {
        $response = $this->postJson(route('proveedores.store'), [
            'nombre' => 'Con CUIT malo',
            'tipo_documento' => 'CUIT',
            'cuit' => '20111111113',
        ]);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['cuit']]);
        $this->assertSame(0, Proveedor::count());
    }

    public function test_acepta_cuit_vacio(): void
    {
        $response = $this->postJson(route('proveedores.store'), [
            'nombre' => 'Sin CUIT',
            'cuit' => '',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('proveedores', ['nombre' => 'Sin CUIT', 'cuit' => null]);
    }

    public function test_saldo_inicial_vacio_no_rompe(): void
    {
        $response = $this->postJson(route('proveedores.store'), [
            'nombre' => 'Sin saldo',
            'saldo_inicial' => '',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('proveedores', ['nombre' => 'Sin saldo', 'saldo_inicial' => 0]);
    }

    public function test_edita_proveedor(): void
    {
        $proveedor = Proveedor::create(['nombre' => 'Original']);

        $response = $this->patchJson(route('proveedores.update', $proveedor), [
            'nombre' => 'Modificado',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('proveedores', ['id' => $proveedor->id, 'nombre' => 'Modificado']);
    }
}
