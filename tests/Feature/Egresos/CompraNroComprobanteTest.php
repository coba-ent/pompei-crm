<?php

namespace Tests\Feature\Egresos;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Spec 049 (US3): N° de comprobante de Compra pasa de autogenerado-oculto a editable-obligatorio. */
class CompraNroComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private Deposito $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        $this->deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => Proveedor::factory()->create()->id,
            'deposito_id' => $this->deposito->id,
            'nro_comprobante' => '0001-00000001',
            'fecha_emision' => '2026-08-17',
            'items' => [[
                'producto_id' => null,
                'descripcion' => 'Insumo',
                'cantidad' => 1,
                'precio_unitario' => 100,
            ]],
        ], $overrides);
    }

    public function test_alta_sin_tocar_el_campo_persiste_el_sugerido(): void
    {
        $sugerido = Compra::siguienteNroComprobante('B');

        $respuesta = $this->postJson(route('compras.store'), $this->payload(['nro_comprobante' => $sugerido]));

        $respuesta->assertCreated();
        $this->assertDatabaseHas('compras', ['nro_comprobante' => $sugerido]);
    }

    public function test_alta_editando_el_campo_persiste_el_valor_real_cargado(): void
    {
        $respuesta = $this->postJson(route('compras.store'), $this->payload(['nro_comprobante' => '0003-00012345']));

        $respuesta->assertCreated();
        $this->assertDatabaseHas('compras', ['nro_comprobante' => '0003-00012345']);
    }

    public function test_alta_con_el_campo_vacio_devuelve_422(): void
    {
        $respuesta = $this->postJson(route('compras.store'), $this->payload(['nro_comprobante' => '']));

        $respuesta->assertStatus(422)->assertJsonValidationErrors(['nro_comprobante']);
    }

    public function test_edicion_muestra_y_permite_cambiar_el_valor_ya_persistido(): void
    {
        $this->postJson(route('compras.store'), $this->payload(['nro_comprobante' => '0003-00012345']))->assertCreated();
        $compra = Compra::firstOrFail();

        $this->assertSame('0003-00012345', $compra->nro_comprobante);

        $respuesta = $this->putJson(route('compras.update', $compra), [
            'proveedor_id' => $compra->proveedor_id,
            'deposito_id' => $this->deposito->id,
            'nro_comprobante' => '0003-00099999',
            'fecha_emision' => '2026-08-17',
            'items' => [[
                'producto_id' => null,
                'descripcion' => 'Insumo',
                'cantidad' => 1,
                'precio_unitario' => 100,
            ]],
        ]);

        $respuesta->assertOk();
        $this->assertDatabaseHas('compras', ['id' => $compra->id, 'nro_comprobante' => '0003-00099999']);
    }
}
