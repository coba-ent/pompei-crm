<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-003/FR-004/FR-008: alta, renombrado y toggle de estado de un depósito.
 * FR-005 (Principio IV): no se elimina físicamente un depósito con stock o
 * movimientos asociados; sí uno sin asociaciones.
 */
class DepositoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_crea_renombra_y_alterna_estado_de_un_deposito(): void
    {
        $this->postJson(route('configuracion.depositos.store'), ['nombre' => 'Depósito Norte'])
            ->assertOk()->assertJsonPath('ok', true);

        $deposito = Deposito::where('nombre', 'Depósito Norte')->firstOrFail();
        $this->assertTrue($deposito->activo);

        $this->patchJson(route('configuracion.depositos.update', $deposito), ['nombre' => 'Depósito Norte 2'])
            ->assertOk();
        $this->assertSame('Depósito Norte 2', $deposito->fresh()->nombre);

        $this->patchJson(route('configuracion.depositos.estado', $deposito))
            ->assertOk()->assertJsonPath('activo', false);
        $this->assertFalse($deposito->fresh()->activo);
        $this->assertCount(0, Deposito::activos()->get()->filter(fn ($d) => $d->id === $deposito->id));

        $this->patchJson(route('configuracion.depositos.estado', $deposito))
            ->assertOk()->assertJsonPath('activo', true);
    }

    public function test_rechaza_nombre_vacio(): void
    {
        $this->postJson(route('configuracion.depositos.store'), ['nombre' => ''])
            ->assertStatus(422);
    }

    public function test_destroy_elimina_deposito_sin_operaciones_asociadas(): void
    {
        $deposito = Deposito::create(['nombre' => 'Borrable', 'activo' => true]);

        $this->deleteJson(route('configuracion.depositos.destroy', $deposito))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('depositos', ['id' => $deposito->id]);
    }

    public function test_destroy_rechaza_deposito_con_stock_asociado(): void
    {
        $deposito = Deposito::create(['nombre' => 'Con stock', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Producto con stock', 'tipo' => 'producto']);
        Stock::create(['producto_id' => $producto->id, 'variante_id' => null, 'deposito_id' => $deposito->id, 'cantidad' => 5]);

        $response = $this->deleteJson(route('configuracion.depositos.destroy', $deposito));

        $response->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('mensaje', 'Sólo puede inactivarse: el depósito tiene stock o movimientos asociados.');

        $this->assertDatabaseHas('depositos', ['id' => $deposito->id]);
    }

    public function test_destroy_rechaza_deposito_con_movimientos_asociados(): void
    {
        $deposito = Deposito::create(['nombre' => 'Con movimientos', 'activo' => true]);
        $producto = Producto::create(['nombre' => 'Producto con movimiento', 'tipo' => 'producto']);
        MovimientoStock::create([
            'producto_id' => $producto->id,
            'variante_id' => null,
            'deposito_id' => $deposito->id,
            'tipo' => 'ajuste',
            'cantidad' => 3,
            'fecha' => now(),
        ]);

        $response = $this->deleteJson(route('configuracion.depositos.destroy', $deposito));

        $response->assertStatus(409)->assertJsonPath('ok', false);
        $this->assertDatabaseHas('depositos', ['id' => $deposito->id]);
    }
}
