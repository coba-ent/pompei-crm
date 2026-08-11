<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\Compra;
use App\Models\ComprobanteFiscal;
use App\Models\Deposito;
use App\Models\NotaCreditoDebito;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 062 (US1/US2) — columnas N° Comprobante y Documento que Ajusta en el detalle de Venta. */
class NotaCreditoDebitoTablaDetalleTest extends TestCase
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

    public function test_nc_nd_con_comprobante_fiscal_aprobado_muestra_n_comprobante_real(): void
    {
        $venta = $this->crearVenta();
        $puntoVenta = PuntoVenta::create(['numero' => 1, 'descripcion' => 'Casa Central', 'por_defecto' => true, 'activo' => true]);

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
        ]);

        $response = $this->get(route('ventas.show', $venta));

        $response->assertOk();
        $response->assertSee('00001-00000002');
    }

    public function test_nc_nd_sin_comprobante_fiscal_muestra_n_comprobante_en_guion_sin_error(): void
    {
        $venta = $this->crearVenta();

        $venta->notasCreditoDebito()->create([
            'tipo' => 'debito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 50,
            'tipo_comprobante' => 'B',
            'descripcion' => 'Ajuste sin CAE',
        ]);

        $response = $this->get(route('ventas.show', $venta));

        $response->assertOk();
    }

    public function test_documento_que_ajusta_muestra_comprobante_original_sin_nota_ajustada_id(): void
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

        $response = $this->get(route('ventas.show', $venta));

        $response->assertOk();
        $response->assertSee($comprobanteVenta->numero);
        $this->assertSame($comprobanteVenta->numero, $nota->documentoQueAjusta($venta->fresh()));
    }

    public function test_documento_que_ajusta_muestra_la_nota_ajustada_cuando_esta_encadenada(): void
    {
        $venta = $this->crearVenta();
        $puntoVenta = PuntoVenta::create(['numero' => 1, 'descripcion' => 'Casa Central', 'por_defecto' => true, 'activo' => true]);

        $notaBase = $venta->notasCreditoDebito()->create([
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'tipo_comprobante' => 'B',
            'descripcion' => 'Nota base',
        ]);

        ComprobanteFiscal::create([
            'comprobantable_type' => NotaCreditoDebito::class,
            'comprobantable_id' => $notaBase->id,
            'punto_venta_id' => $puntoVenta->id,
            'tipo_comprobante' => 'B',
            'numero' => '00001-00000003',
            'cae' => '71234500000003',
            'cae_vencimiento' => now()->addDays(10),
            'estado' => 'aprobado',
        ]);

        $notaEncadenada = $venta->notasCreditoDebito()->create([
            'tipo' => 'debito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 30,
            'tipo_comprobante' => 'B',
            'descripcion' => 'Nota que ajusta a la base',
            'nota_ajustada_id' => $notaBase->id,
        ]);

        $response = $this->get(route('ventas.show', $venta));

        $response->assertOk();
        $this->assertSame('00001-00000003', $notaEncadenada->documentoQueAjusta($venta->fresh()));
    }

    public function test_documento_que_ajusta_muestra_nro_comprobante_de_compra_sin_cae(): void
    {
        // Compras nunca emiten ComprobanteFiscal propio (el CAE es sólo para Ventas vía ARCA) —
        // "Documento que Ajusta" tiene que caer al nro_comprobante que cargó el proveedor.
        $compra = Compra::factory()->create([
            'tipo_comprobante' => 'A', 'nro_comprobante' => '0011-04769061',
        ]);

        $nota = $compra->notasCreditoDebito()->create([
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'tipo_comprobante' => 'A',
            'descripcion' => 'Ajuste sobre compra sin CAE',
        ]);

        $response = $this->get(route('compras.show', $compra));

        $response->assertOk();
        $this->assertSame('A 0011-04769061', $nota->documentoQueAjusta($compra->fresh()));
    }

    public function test_documento_que_ajusta_queda_vacio_sin_comprobante_original_ni_nota_ajustada(): void
    {
        $venta = Venta::factory()->create(['tipo_comprobante' => 'B', 'nro_comprobante' => null]);

        $nota = $venta->notasCreditoDebito()->create([
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => now()->startOfMonth()->toDateString(),
            'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'tipo_comprobante' => 'B',
            'descripcion' => 'Sin comprobante original aprobado',
        ]);

        $response = $this->get(route('ventas.show', $venta));

        $response->assertOk();
        $this->assertNull($nota->documentoQueAjusta($venta->fresh()));
    }
}
