<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\ComprobanteFiscal;
use App\Models\CondicionIva;
use App\Models\Proveedor;
use App\Models\PuntoVenta;
use App\Models\Venta;
use App\Services\Informes\InformeIvaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InformeIvaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function filtros(): array
    {
        return ['fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30'];
    }

    public function test_iva_ventas_suma_solo_los_comprobantes_completos(): void
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Responsable Inscripto']);
        $puntoVenta = PuntoVenta::create(['numero' => '0001', 'activo' => true]);

        // Completa: cliente con condición de IVA + comprobante aprobado con número.
        $clienteCompleto = Cliente::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        $ventaCompleta = Venta::factory()->create([
            'cliente_id' => $clienteCompleto->id, 'fecha_emision' => '2026-06-10',
            'subtotal' => 1000, 'total' => 1210,
        ]);
        ComprobanteFiscal::create([
            'facturable_type' => Venta::class, 'facturable_id' => $ventaCompleta->id,
            'punto_venta_id' => $puntoVenta->id, 'tipo_comprobante' => 'A', 'cbte_tipo_afip' => 1,
            'estado' => 'aprobado', 'numero' => 1, 'cae' => '1234', 'cae_vencimiento' => now()->addDays(10),
        ]);

        // Incompleta: cliente sin condición de IVA cargada, sin comprobante aprobado.
        $clienteIncompleto = Cliente::factory()->create(['condicion_iva_id' => null]);
        Venta::factory()->create([
            'cliente_id' => $clienteIncompleto->id, 'fecha_emision' => '2026-06-15',
            'subtotal' => 5000, 'total' => 6050,
        ]);

        $resultado = app(InformeIvaService::class)->ventas($this->filtros());

        $this->assertCount(2, $resultado['lineas']);
        $completa = collect($resultado['lineas'])->firstWhere('completo', true);
        $incompleta = collect($resultado['lineas'])->firstWhere('completo', false);

        $this->assertNotNull($completa);
        $this->assertNotNull($incompleta);
        $this->assertEquals(1000.0, $completa['base_imponible']);
        $this->assertEquals(210.0, $completa['iva']);

        // Sólo la completa entra en los subtotales fiscales (FR-015, SC-004).
        $this->assertEquals(1000.0, $resultado['subtotal_base']);
        $this->assertEquals(210.0, $resultado['subtotal_iva']);
        $this->assertEquals(1210.0, $resultado['subtotal_total']);
    }

    public function test_iva_compras_suma_solo_los_proveedores_con_condicion_iva_cargada(): void
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Monotributista']);

        $proveedorCompleto = Proveedor::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        Compra::factory()->create([
            'proveedor_id' => $proveedorCompleto->id, 'fecha_emision' => '2026-06-05',
            'subtotal' => 2000, 'total' => 2420,
        ]);

        $proveedorIncompleto = Proveedor::factory()->create(['condicion_iva_id' => null]);
        Compra::factory()->create([
            'proveedor_id' => $proveedorIncompleto->id, 'fecha_emision' => '2026-06-08',
            'subtotal' => 3000, 'total' => 3630,
        ]);

        $resultado = app(InformeIvaService::class)->compras($this->filtros());

        $this->assertCount(2, $resultado['lineas']);
        $this->assertEquals(2000.0, $resultado['subtotal_base']);
        $this->assertEquals(420.0, $resultado['subtotal_iva']);
        $this->assertEquals(2420.0, $resultado['subtotal_total']);

        $incompleta = collect($resultado['lineas'])->firstWhere('completo', false);
        $this->assertNotNull($incompleta);
        $this->assertEquals(3000.0, $incompleta['base_imponible']);
    }
}
