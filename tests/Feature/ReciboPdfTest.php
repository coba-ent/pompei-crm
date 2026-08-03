<?php

namespace Tests\Feature;

use App\Models\CondicionIva;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US3 (spec 039) — Recibo de Cobranza/Pago: documento no fiscal, mejor esfuerzo (smoke test). */
class ReciboPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_recibo_de_cobranza_se_genera_correctamente(): void
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();
        $venta = Venta::firstOrFail();

        $this->postJson(route('ventas.cobranzas.store', $venta), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();
        $cobro = $venta->cobros()->firstOrFail();

        $response = $this->get(route('ventas.cobranzas.recibo', [$venta, $cobro]));

        $response->assertOk();
        $this->assertNotEmpty($response->getContent());
    }

    public function test_recibo_de_pago_se_genera_correctamente(): void
    {
        $proveedor = Proveedor::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        $this->postJson(route('compras.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();
        $compra = Compra::firstOrFail();

        $this->postJson(route('compras.pagos.store', $compra), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();
        $pago = $compra->pagos()->firstOrFail();

        $response = $this->get(route('compras.pagos.recibo', [$compra, $pago]));

        $response->assertOk();
        $this->assertNotEmpty($response->getContent());
    }

    public function test_recibo_de_cobranza_de_otra_venta_devuelve_404(): void
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();

        foreach ([1, 2] as $i) {
            $this->postJson(route('ventas.store'), [
                'submit_token' => (string) \Illuminate\Support\Str::uuid(),
                'cliente_id' => $cliente->id,
                'fecha_emision' => now()->toDateString(),
                'tipo_comprobante' => 'B',
                'items' => [
                    ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
                ],
            ])->assertCreated();
        }

        $ventas = Venta::orderBy('id')->get();
        $this->postJson(route('ventas.cobranzas.store', $ventas[0]), [
            'cuenta_tesoreria_id' => $cuenta->id,
            'monto' => 1210,
            'fecha' => now()->toDateString(),
        ])->assertCreated();
        $cobro = $ventas[0]->cobros()->firstOrFail();

        $response = $this->get(route('ventas.cobranzas.recibo', [$ventas[1], $cobro]));

        $response->assertNotFound();
    }
}
