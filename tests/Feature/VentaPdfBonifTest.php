<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Support\Str;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * PDF de Venta: la columna "Bonif." muestra el % efectivo combinado (línea + Descuento General),
 * no sólo el descuento propio de línea — spec 098, User Story 2.
 */
class VentaPdfBonifTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        auth()->user()->roles()->syncWithoutDetaching(Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id);
    }

    /** @param  array<string, mixed>  $extra */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Local', 'activo' => true])->id,
            'fecha_emision' => '2026-07-18',
            'tipo_comprobante' => 'B',
            'items' => [[
                'producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                'descripcion' => 'Servicio',
                'cantidad' => 1,
                'precio_unitario' => 100,
                'iva_pct' => '21',
            ]],
        ], $extra);
    }

    public function test_bonif_muestra_el_descuento_general_cuando_la_linea_no_tiene_propio(): void
    {
        $payload = $this->payload([
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 10,
        ]);

        $this->postJson(route('ventas.store'), $payload)->assertCreated();
        $venta = Venta::firstOrFail();
        $venta->load('items');

        $html = view('ventas.pdf', ['venta' => $venta, 'qrDataUri' => null, 'datosEmpresa' => null])->render();

        $this->assertStringContainsString('10%', $html);
    }

    public function test_bonif_combina_descuento_de_linea_y_general_sin_sumarlos(): void
    {
        $payload = $this->payload([
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 10,
        ]);
        $payload['items'][0]['descuento_pct'] = 10;

        $this->postJson(route('ventas.store'), $payload)->assertCreated();
        $venta = Venta::firstOrFail();
        $venta->load('items');

        $html = view('ventas.pdf', ['venta' => $venta, 'qrDataUri' => null, 'datosEmpresa' => null])->render();

        // 10% de línea + 10% general combinados = 19%, no 20%.
        $this->assertStringContainsString('19%', $html);
        $this->assertStringNotContainsString('20%', $html);
    }

    public function test_bonif_sin_descuento_general_sigue_mostrando_solo_el_de_linea(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['descuento_pct'] = 8;

        $this->postJson(route('ventas.store'), $payload)->assertCreated();
        $venta = Venta::firstOrFail();
        $venta->load('items');

        $html = view('ventas.pdf', ['venta' => $venta, 'qrDataUri' => null, 'datosEmpresa' => null])->render();

        $this->assertStringContainsString('8%', $html);
    }
}
