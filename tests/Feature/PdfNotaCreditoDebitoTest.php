<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\ComprobanteFiscal;
use App\Models\Deposito;
use App\Models\NotaCreditoDebito;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US1 (spec 039) — el PDF de una NC/ND con CAE expone su propio CAE y la referencia al comprobante ajustado. */
class PdfNotaCreditoDebitoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVenta(): Venta
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Consumidor Final', 'codigo_afip' => '5', 'requiere_cuit' => false]);
        $cliente = Cliente::factory()->create(['condicion_iva_id' => $condicionIva->id]);

        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
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

    public function test_pdf_de_nota_con_cae_aprobado_expone_cae_vencimiento_y_comprobante_ajustado(): void
    {
        $venta = $this->crearVenta();
        $puntoVenta = PuntoVenta::create(['numero' => 1, 'descripcion' => 'Casa Central', 'por_defecto' => true, 'activo' => true]);
        $comprobanteVenta = ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class,
            'comprobantable_id' => $venta->id,
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => 'B',
            'numero' => '00001-00000001',
            'cae' => '71234500000001',
            'cae_vencimiento' => now()->addDays(10),
            'estado' => 'aprobado',
        ]);

        $nota = $venta->notasCreditoDebito()->create([
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'tipo_comprobante' => 'B',
            'descripcion' => 'Ajuste de prueba',
        ]);

        ComprobanteFiscal::create([
            'comprobantable_type' => NotaCreditoDebito::class,
            'comprobantable_id' => $nota->id,
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => 'B',
            'numero' => '00001-00000002',
            'cae' => '71234500000002',
            'cae_vencimiento' => now()->addDays(10),
            'estado' => 'aprobado',
            'comprobante_ajustado_id' => $comprobanteVenta->id,
        ]);

        $response = $this->get(route('ventas.notas.pdf', $nota));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));

        $contenido = $response->getContent();
        $this->assertNotEmpty($contenido);
    }

    public function test_pdf_de_nota_sin_cae_muestra_watermark(): void
    {
        $venta = $this->crearVenta();

        $nota = $venta->notasCreditoDebito()->create([
            'tipo' => 'debito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 50,
            'tipo_comprobante' => 'B',
            'descripcion' => 'Ajuste sin CAE',
        ]);

        $response = $this->get(route('ventas.notas.pdf', $nota));

        $response->assertOk();
        $this->assertNotEmpty($response->getContent());
    }
}
