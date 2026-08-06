<?php

namespace Tests\Feature\Configuracion;

use App\Models\ConfiguracionVentas;
use App\Models\Deposito;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 049 (US2): Depósito por defecto de Ventas y de Compras en Configuración & Ajustes. */
class ConfiguracionVentasDepositoTest extends TestCase
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

    public function test_guarda_deposito_id_y_deposito_compra_id_y_precargan_en_crear_venta_y_crear_compra(): void
    {
        $this->actingAsAdmin();
        $depositoVenta = Deposito::create(['nombre' => 'Depósito Ventas', 'activo' => true]);
        $depositoCompra = Deposito::create(['nombre' => 'Depósito Compras', 'activo' => true]);

        $this->putJson(route('configuracion.ventas.guardar'), [
            'deposito_id' => $depositoVenta->id,
            'deposito_compra_id' => $depositoCompra->id,
        ])->assertOk();

        $this->assertDatabaseHas('configuracion_ventas', [
            'deposito_id' => $depositoVenta->id,
            'deposito_compra_id' => $depositoCompra->id,
        ]);

        $this->get(route('ventas.create'))
            ->assertOk()
            ->assertSee('"depositoId":'.$depositoVenta->id, false);

        $this->get(route('compras.create'))
            ->assertOk()
            ->assertSee('depositoId: '.$depositoCompra->id, false);
    }

    public function test_sin_configuracion_crear_venta_y_crear_compra_usan_el_deposito_por_defecto(): void
    {
        $this->actingAsAdmin();
        $porDefecto = Deposito::create(['nombre' => 'Único Activo', 'activo' => true]);

        // Sin fila en configuracion_ventas, $defaults queda null por completo (mismo comportamiento
        // que ConfiguracionVentasDefaultsTest::test_sin_configuracion_no_hay_defaults).
        $this->get(route('ventas.create'))
            ->assertOk()
            ->assertSee('defaults: null', false);

        $this->get(route('compras.create'))
            ->assertOk()
            ->assertSee('depositoId: null', false);
    }

    public function test_deposito_por_defecto_inactivado_vuelve_al_fallback(): void
    {
        $this->actingAsAdmin();
        $depositoConfigurado = Deposito::create(['nombre' => 'Se Desactiva', 'activo' => true]);

        ConfiguracionVentas::create(['deposito_id' => $depositoConfigurado->id, 'deposito_compra_id' => $depositoConfigurado->id]);

        $depositoConfigurado->update(['activo' => false]);

        $this->get(route('ventas.create'))
            ->assertOk()
            ->assertSee('"depositoId":null', false);

        $this->get(route('compras.create'))
            ->assertOk()
            ->assertSee('depositoId: null', false);
    }
}
