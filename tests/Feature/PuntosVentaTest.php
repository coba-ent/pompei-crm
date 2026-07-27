<?php

namespace Tests\Feature;

use App\Models\ComprobanteFiscal;
use App\Models\PuntoVenta;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PuntosVentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['negocio.facturacion_electronica_activo' => true]);
    }

    public function test_alta_de_punto_de_venta_con_numero_unico(): void
    {
        $response = $this->postJson(route('puntos-venta.store'), [
            'numero' => '0001',
            'domicilio' => 'Av. Siempreviva 742',
        ]);

        $response->assertStatus(201)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('puntos_venta', ['numero' => '0001', 'activo' => true]);
    }

    public function test_rechaza_numero_duplicado(): void
    {
        PuntoVenta::create(['numero' => '0001', 'activo' => true]);

        $response = $this->postJson(route('puntos-venta.store'), ['numero' => '0001']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('numero', $response->json('errors'));
    }

    public function test_no_elimina_un_punto_de_venta_con_comprobantes_asociados(): void
    {
        $puntoVenta = PuntoVenta::create(['numero' => '0001', 'activo' => true]);
        $venta = Venta::factory()->create(['punto_venta_id' => $puntoVenta->id]);
        ComprobanteFiscal::create([
            'facturable_type' => Venta::class,
            'facturable_id' => $venta->id,
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => 'B',
            'cbte_tipo_afip' => 6,
            'estado' => 'aprobado',
            'numero' => 1,
            'cae' => '75123456789012',
            'cae_vencimiento' => now()->addDays(10),
        ]);

        $response = $this->deleteJson(route('puntos-venta.destroy', $puntoVenta));

        $response->assertStatus(422);
        $this->assertDatabaseHas('puntos_venta', ['id' => $puntoVenta->id]);
    }

    public function test_elimina_un_punto_de_venta_sin_comprobantes(): void
    {
        $puntoVenta = PuntoVenta::create(['numero' => '0002', 'activo' => true]);

        $response = $this->deleteJson(route('puntos-venta.destroy', $puntoVenta));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('puntos_venta', ['id' => $puntoVenta->id]);
    }

    public function test_el_flag_desactivado_bloquea_las_rutas(): void
    {
        config(['negocio.facturacion_electronica_activo' => false]);

        $response = $this->postJson(route('puntos-venta.store'), ['numero' => '0001']);

        $response->assertStatus(403);
    }
}
