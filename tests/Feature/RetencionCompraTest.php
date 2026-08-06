<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\Proveedor;
use App\Models\Retencion;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US2 — Retención sobre una Compra: vinculada al pago, listada en el detalle. */
class RetencionCompraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearCompraPagada(): Compra
    {
        $proveedor = Proveedor::factory()->create();
        $cuenta = CuentaTesoreria::factory()->tipo('efectivo')->create();
        $deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('compras.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'nro_comprobante' => '0001-00000001',
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

        return $compra->fresh();
    }

    public function test_crear_retencion_vinculada_al_pago_de_una_compra(): void
    {
        $compra = $this->crearCompraPagada();
        $pago = $compra->pagos->first();

        $this->postJson(route('compras.retenciones.store', $compra), [
            'fecha' => now()->toDateString(),
            'monto' => 210,
            'tipo_retencion' => 'IVA',
            'nro_comprobante' => '0001',
            'descripcion' => 'Retención de IVA',
        ])->assertCreated()->assertJsonPath('ok', true);

        $retencion = Retencion::firstOrFail();
        $this->assertSame($pago->id, $retencion->pago_id);
        $this->assertNull($retencion->cobro_id);
        $this->assertSame('IVA', $retencion->tipo_retencion);
    }

    public function test_retencion_queda_listada_en_el_detalle_de_la_compra(): void
    {
        $compra = $this->crearCompraPagada();

        $this->postJson(route('compras.retenciones.store', $compra), [
            'fecha' => now()->toDateString(),
            'monto' => 100,
            'tipo_retencion' => 'Ganancias',
        ])->assertCreated();

        $retenciones = $compra->fresh()->pagos->flatMap->retenciones;
        $this->assertCount(1, $retenciones);
        $this->assertSame('Ganancias', $retenciones->first()->tipo_retencion);
    }
}
