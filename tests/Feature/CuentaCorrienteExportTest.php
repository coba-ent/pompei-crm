<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SC-006 — los importes exportados coinciden con los de pantalla bajo los mismos filtros. */
class CuentaCorrienteExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_csv_de_saldos_coincide_con_la_pantalla(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0, 'nombre' => 'Cliente Export']);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 40000]);

        $saldoPantalla = $this->getJson(route('cuentas-corrientes.clientes.data'))->json();
        $filaPantalla = collect($saldoPantalla['data'])->firstWhere('id', $cliente->id);

        $csv = $this->get(route('cuentas-corrientes.clientes.export.csv'));
        $csv->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Cliente Export', $csv->streamedContent());
        $this->assertStringContainsString(number_format($filaPantalla['saldo'], 2, ',', ''), $csv->streamedContent());
    }

    public function test_export_pdf_de_saldos_responde_pdf_inline(): void
    {
        Cliente::factory()->create();

        $pdf = $this->get(route('cuentas-corrientes.clientes.export.pdf'));

        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_export_csv_de_movimientos_coincide_con_saldo_final(): void
    {
        $cliente = Cliente::factory()->create(['saldo_inicial' => 0]);
        $venta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 100000]);
        Cobro::factory()->create(['venta_id' => $venta->id, 'monto' => 40000]);

        $detalle = $this->getJson(route('cuentas-corrientes.clientes.movimientos.data', $cliente))->json();

        $csv = $this->get(route('cuentas-corrientes.clientes.movimientos.export.csv', $cliente));
        $csv->assertOk();
        $this->assertStringContainsString(number_format($detalle['saldo_final'], 2, ',', ''), $csv->streamedContent());
    }
}
