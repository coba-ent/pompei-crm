<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\ComprobanteFiscal;
use App\Models\CondicionIva;
use App\Models\Proveedor;
use App\Models\PuntoVenta;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InformeContadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_tipo_ventas_devuelve_las_lineas_e_incompletas_marcadas(): void
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Responsable Inscripto']);
        $puntoVenta = PuntoVenta::create(['numero' => '0001', 'activo' => true]);

        $clienteCompleto = Cliente::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        $venta = Venta::factory()->create([
            'cliente_id' => $clienteCompleto->id, 'fecha_emision' => '2026-06-10', 'subtotal' => 1000, 'total' => 1210,
        ]);
        ComprobanteFiscal::create([
            'facturable_type' => Venta::class, 'facturable_id' => $venta->id,
            'punto_venta_id' => $puntoVenta->id, 'tipo_comprobante' => 'A', 'cbte_tipo_afip' => 1,
            'estado' => 'aprobado', 'numero' => 1, 'cae' => '1234', 'cae_vencimiento' => now()->addDays(10),
        ]);

        $clienteIncompleto = Cliente::factory()->create(['condicion_iva_id' => null]);
        Venta::factory()->create([
            'cliente_id' => $clienteIncompleto->id, 'fecha_emision' => '2026-06-15', 'subtotal' => 500, 'total' => 605,
        ]);

        $resp = $this->getJson(route('informes.contador.data', [
            'tipo' => 'ventas', 'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(2, $resp['lineas']);
        $this->assertEquals(1000.0, $resp['subtotal_base']);
        $this->assertTrue(collect($resp['lineas'])->contains('completo', false));
    }

    public function test_tipo_compras_discrimina_secciones_por_separado(): void
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Monotributista']);
        $proveedor = Proveedor::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-06-05', 'subtotal' => 2000, 'total' => 2420,
        ]);

        $resp = $this->getJson(route('informes.contador.data', [
            'tipo' => 'compras', 'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(1, $resp['lineas']);
        $this->assertEquals(2000.0, $resp['subtotal_base']);
    }

    public function test_export_csv_discrimina_ventas_y_compras(): void
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Responsable Inscripto']);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        $puntoVenta = PuntoVenta::create(['numero' => '0001', 'activo' => true]);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'fecha_emision' => '2026-06-10', 'subtotal' => 1000, 'total' => 1210,
        ]);
        ComprobanteFiscal::create([
            'facturable_type' => Venta::class, 'facturable_id' => $venta->id,
            'punto_venta_id' => $puntoVenta->id, 'tipo_comprobante' => 'A', 'cbte_tipo_afip' => 1,
            'estado' => 'aprobado', 'numero' => 7, 'cae' => '999', 'cae_vencimiento' => now()->addDays(10),
        ]);

        $csv = $this->get(route('informes.contador.csv', [
            'tipo' => 'ventas', 'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk();

        $this->assertStringContainsString('IVA Ventas', $csv->streamedContent());
    }
}
