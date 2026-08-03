<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\ComprobanteFiscal;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-012: una Venta con ComprobanteFiscal aprobado es inmutable en Tipo de Comprobante/cliente/ítems. */
class ComprobanteInmutableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_editar_tipo_comprobante_de_venta_con_cae_aprobado_es_rechazado(): void
    {
        $condicion = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicion->id]);
        $otroCliente = Cliente::factory()->create(['condicion_iva_id' => $condicion->id]);

        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'tipo_comprobante' => 'B', 'total' => 1210]);
        $venta->items()->create(['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'subtotal' => 1000, 'subtotal_con_iva' => 1210]);

        $puntoVenta = PuntoVenta::create(['numero' => 1, 'descripcion' => 'Casa Central', 'por_defecto' => true, 'activo' => true]);
        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class,
            'comprobantable_id' => $venta->id,
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => 'B',
            'numero' => '0001-00000001',
            'cae' => '71234567890123',
            'cae_vencimiento' => now()->addDays(10),
            'estado' => 'aprobado',
        ]);

        $response = $this->putJson(route('ventas.update', $venta), [
            'cliente_id' => $otroCliente->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tipo_comprobante', 'cliente_id']);
        $this->assertSame('B', $venta->fresh()->tipo_comprobante);
    }
}
