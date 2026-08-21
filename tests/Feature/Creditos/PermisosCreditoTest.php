<?php

namespace Tests\Feature\Creditos;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\NotaCreditoDebito;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aplicar y anular crédito exigen **el mismo permiso** que cargar una cobranza (FR-022). No se creó
 * un permiso nuevo: las rutas viven en el mismo grupo que `ventas.cobranzas.*`.
 */
class PermisosCreditoTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autenticado = false;

    private Venta $destino;

    protected function setUp(): void
    {
        parent::setUp();

        $cliente = Cliente::factory()->create();
        $origen = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 1000, 'fecha_emision' => '2026-08-01',
        ]);
        Cobro::factory()->create(['venta_id' => $origen->id, 'monto' => 1000]);
        NotaCreditoDebito::factory()->create(['venta_id' => $origen->id, 'tipo' => 'credito', 'monto' => 1000]);

        $this->destino = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'total' => 500, 'fecha_emision' => '2026-08-20',
        ]);
    }

    public function test_sin_el_permiso_de_ventas_no_se_puede_aplicar_credito(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson(route('ventas.credito.disponible', $this->destino))->assertForbidden();
        $this->postJson(route('ventas.aplicaciones-credito.store', $this->destino), [
            'monto' => 500, 'fecha' => '2026-08-20',
        ])->assertForbidden();

        $this->assertDatabaseCount('aplicaciones_credito', 0);
    }

    public function test_con_el_mismo_permiso_que_la_cobranza_se_puede_aplicar_y_anular(): void
    {
        $rol = Rol::create(['nombre' => 'Vendedor', 'es_sistema' => false]);
        $permiso = Permiso::create(['codigo' => 'ventas.ver', 'descripcion' => 'Ver ventas', 'modulo' => 'ventas']);
        $rol->permisos()->attach($permiso->id);

        $user = User::factory()->create();
        $user->roles()->attach($rol->id);
        $this->actingAs($user);

        $resp = $this->postJson(route('ventas.aplicaciones-credito.store', $this->destino), [
            'monto' => 500, 'fecha' => '2026-08-20',
        ])->assertCreated();

        $this->deleteJson(route('ventas.aplicaciones-credito.destroy', [
            $this->destino, $resp->json('aplicaciones.0.id'),
        ]))->assertOk();
    }
}
