<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Support\Str;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * PDF de Compra: la columna "Bonif." muestra el % efectivo combinado (línea + Descuento
 * General), no sólo el descuento propio de línea — spec 098, User Story 2.
 */
class CompraPdfBonifTest extends TestCase
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
            'proveedor_id' => Proveedor::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Depósito', 'activo' => true])->id,
            'nro_comprobante' => '0001-00000001',
            'fecha_emision' => '2026-07-18',
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

        $this->postJson(route('compras.store'), $payload)->assertCreated();
        $compra = Compra::firstOrFail();
        $compra->load('items');

        $html = view('compras.pdf', ['compra' => $compra, 'datosEmpresa' => null])->render();

        $this->assertStringContainsString('10%', $html);
    }

    public function test_bonif_combina_descuento_de_linea_y_general_sin_sumarlos(): void
    {
        $payload = $this->payload([
            'descuento_general_tipo' => 'porcentaje',
            'descuento_general_pct' => 10,
        ]);
        $payload['items'][0]['descuento_pct'] = 10;

        $this->postJson(route('compras.store'), $payload)->assertCreated();
        $compra = Compra::firstOrFail();
        $compra->load('items');

        $html = view('compras.pdf', ['compra' => $compra, 'datosEmpresa' => null])->render();

        $this->assertStringContainsString('19%', $html);
        $this->assertStringNotContainsString('20%', $html);
    }

    public function test_bonif_sin_descuento_general_sigue_mostrando_solo_el_de_linea(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['descuento_pct'] = 8;

        $this->postJson(route('compras.store'), $payload)->assertCreated();
        $compra = Compra::firstOrFail();
        $compra->load('items');

        $html = view('compras.pdf', ['compra' => $compra, 'datosEmpresa' => null])->render();

        $this->assertStringContainsString('8%', $html);
    }
}
