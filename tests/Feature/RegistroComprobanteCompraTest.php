<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Compra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US2 — guardar una Compra con datos fiscales del Proveedor crea ComprobanteFiscal sin llamar a ARCA (FR-015). */
class RegistroComprobanteCompraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_guardar_compra_con_datos_fiscales_completos_crea_comprobante_fiscal(): void
    {
        $proveedor = Proveedor::factory()->create();

        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'punto_venta_proveedor' => '0003',
            'numero_comprobante_proveedor' => '00001234',
            'cae_proveedor' => '71234567890123',
            'cae_vencimiento_proveedor' => now()->addDays(10)->toDateString(),
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ];

        $response = $this->postJson(route('compras.store'), $payload);

        $response->assertCreated()->assertJsonPath('ok', true);

        $compra = Compra::firstOrFail();
        $comprobante = $compra->comprobanteFiscal;

        $this->assertNotNull($comprobante);
        $this->assertSame('aprobado', $comprobante->estado);
        $this->assertSame('71234567890123', $comprobante->cae);
        $this->assertNull($comprobante->punto_venta_id);
    }

    public function test_guardar_compra_sin_cae_de_proveedor_no_crea_comprobante_fiscal(): void
    {
        $proveedor = Proveedor::factory()->create();

        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'items' => [
                ['descripcion' => 'Insumo', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ];

        $this->postJson(route('compras.store'), $payload)->assertCreated();

        $compra = Compra::firstOrFail();
        $this->assertNull($compra->comprobanteFiscal);
    }
}
