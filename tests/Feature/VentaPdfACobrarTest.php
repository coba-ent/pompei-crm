<?php

namespace Tests\Feature;

use App\Models\AplicacionCredito;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PDF de Venta: "Total a Cobrar" usa `aCobrar()`, no `total - cobros`.
 *
 * Caso real (venta 24996, 07/09/2026). La clienta compró una Tapa Florencia el 28/08 y la pagó;
 * después la devolvió —nota de crédito por $108.581,80— y el 07/09 se llevó una Tapa Tauro del
 * mismo precio. El crédito de la devolución se aplicó a la venta nueva, así que no quedaba nada
 * por cobrar: `aCobrar()` daba $0 y el estado era "cobrada".
 *
 * Pero el PDF calculaba el saldo a mano como `total - cobros`, ignorando las notas de crédito y los
 * créditos aplicados. Como en esa venta no entró plata (se pagó con el crédito), imprimía
 * "Total a Cobrar: $108.581,80" — y se le entregó a la clienta un comprobante que decía que debía
 * plata cuando no debía nada.
 *
 * El modelo ya tenía la cuenta correcta; el PDF era el único lugar que la rehacía por su cuenta.
 */
class VentaPdfACobrarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );
    }

    private function crearVenta(Cliente $cliente, float $precio): Venta
    {
        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => Deposito::first()?->id ?? Deposito::create(['nombre' => 'Local', 'activo' => true])->id,
            'fecha_emision' => '2026-09-07',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                'descripcion' => 'Tapa',
                'cantidad' => 1,
                'precio_unitario' => $precio,
                'iva_pct' => '0',
            ]],
        ])->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    private function html(Venta $venta): string
    {
        return view('ventas.pdf', [
            'venta' => $venta->fresh()->load('items'),
            'qrDataUri' => null,
            'datosEmpresa' => null,
        ])->render();
    }

    /** EL CASO DE LA CLIENTA: saldada con un crédito aplicado desde otra venta. */
    public function test_una_venta_saldada_con_credito_aplicado_no_figura_como_deuda(): void
    {
        $cliente = Cliente::factory()->create();
        $devuelta = $this->crearVenta($cliente, 108581.80);
        $nueva = $this->crearVenta($cliente, 108581.80);

        $nota = NotaCreditoDebito::factory()->create([
            'venta_id' => $devuelta->id, 'compra_id' => null,
            'tipo' => 'credito', 'monto' => 108581.80,
        ]);

        AplicacionCredito::create([
            'origen_type' => Venta::class, 'origen_id' => $devuelta->id,
            'destino_type' => Venta::class, 'destino_id' => $nueva->id,
            'nota_credito_debito_id' => $nota->id,
            'monto' => 108581.80, 'fecha' => '2026-09-07',
        ]);

        $this->assertSame(0.0, $nueva->fresh()->aCobrar(), 'El modelo ya la da por saldada.');

        $html = $this->html($nueva);

        $this->assertStringContainsString('Crédito Aplicado', $html);

        // Lo que importa: el renglón "Total a Cobrar" imprime 0,00 y no el total de la venta.
        preg_match('/Total a Cobrar.*?\$ ([\d.,]+)/s', $html, $m);
        $this->assertSame('0,00', $m[1] ?? null, 'La venta está saldada con el crédito.');
    }

    /** Una venta con Nota de Crédito propia tampoco figura como deuda. */
    public function test_una_venta_saldada_con_nota_de_credito_no_figura_como_deuda(): void
    {
        $venta = $this->crearVenta(Cliente::factory()->create(), 1000);

        NotaCreditoDebito::factory()->create([
            'venta_id' => $venta->id, 'compra_id' => null, 'tipo' => 'credito', 'monto' => 1000,
        ]);

        $this->assertSame(0.0, $venta->fresh()->aCobrar());

        $html = $this->html($venta);

        $this->assertStringContainsString('Nota de Crédito', $html);
        preg_match('/Total a Cobrar.*?\$ ([\d.,]+)/s', $html, $m);
        $this->assertSame('0,00', $m[1] ?? null);
    }

    /** Una venta normal sin cobrar sigue mostrando su deuda: el fix no puede esconder saldos reales. */
    public function test_una_venta_sin_cobrar_sigue_mostrando_lo_que_se_debe(): void
    {
        $venta = $this->crearVenta(Cliente::factory()->create(), 5000);

        $this->assertSame(5000.0, $venta->fresh()->aCobrar());

        $html = $this->html($venta);

        preg_match('/Total a Cobrar.*?\$ ([\d.,]+)/s', $html, $m);
        $this->assertSame('5.000,00', $m[1] ?? null, 'Sigue debiendo los $5.000.');

        // Sin crédito ni notas, esas líneas no ensucian el comprobante.
        $this->assertStringNotContainsString('Crédito Aplicado', $html);
        $this->assertStringNotContainsString('Nota de Crédito', $html);
    }
}
