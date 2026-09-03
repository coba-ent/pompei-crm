<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\Deposito;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PDF de NC/ND: agrega la fila "Descuento General" al bloque de totales, sin tocar la columna
 * "%Bonif." de cada línea — spec 098, User Story 3. A diferencia de Presupuesto/Venta/Compra, acá
 * los dos descuentos NO se combinan (FR-008): la columna sigue mostrando el descuento propio de
 * línea tal cual, y el Descuento General se ve aparte, en su propia fila.
 */
class NotaCreditoDebitoPdfDescuentoGeneralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        auth()->user()->roles()->syncWithoutDetaching(Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id);
    }

    private function crearVenta(): Venta
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $payload = [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ];

        $this->postJson(route('ventas.store'), $payload)->assertCreated();

        return Venta::firstOrFail();
    }

    public function test_pdf_muestra_fila_descuento_general_con_importe_mayor_a_cero(): void
    {
        $venta = $this->crearVenta();

        $payload = [
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'descripcion' => 'Ajuste con descuento general',
            'monto' => 900, // 1000 con 10% de descuento general.
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 10,
            'items' => [[
                'producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                'cantidad' => 1,
                'precio' => 1000,
                'descuento_pct' => 0,
                'iva_pct' => 21,
            ]],
        ];

        $this->postJson(route('ventas.notas.store', $venta), $payload)->assertCreated();
        $nota = NotaCreditoDebito::firstOrFail();
        $nota->load(['items.producto', 'venta.cliente', 'venta.comprobanteFiscal']);

        $html = view('notas-credito-debito.pdf', [
            'notaCreditoDebito' => $nota,
            'qrDataUri' => null,
            'datosEmpresa' => null,
        ])->render();

        $this->assertStringContainsString('Descuento General', $html);
        $this->assertStringContainsString('$ 100,00', $html);
        // La columna %Bonif. de la línea sigue mostrando el descuento propio (0%), NO el 10%
        // general combinado — es la parte de FR-008 que esta feature no debe romper.
        $this->assertStringContainsString('0%', $html);
    }

    public function test_pdf_sin_descuento_general_muestra_fila_en_cero_sin_romper(): void
    {
        $venta = $this->crearVenta();

        $payload = [
            'tipo' => 'debito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'descripcion' => 'Ajuste sin descuento general',
            'monto' => 500,
            'items' => [[
                'producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                'cantidad' => 1,
                'precio' => 500,
                'iva_pct' => 21,
            ]],
        ];

        $this->postJson(route('ventas.notas.store', $venta), $payload)->assertCreated();
        $nota = NotaCreditoDebito::firstOrFail();
        $nota->load(['items.producto', 'venta.cliente', 'venta.comprobanteFiscal']);

        $html = view('notas-credito-debito.pdf', [
            'notaCreditoDebito' => $nota,
            'qrDataUri' => null,
            'datosEmpresa' => null,
        ])->render();

        $this->assertStringContainsString('Descuento General', $html);
        $this->assertStringContainsString('$ 0,00', $html);
    }
}
