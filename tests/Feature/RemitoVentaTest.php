<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US1 (spec 064) — emitir un remito sobre una Venta: precarga, número, documento imprimible. */
class RemitoVentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    private function crearVenta(?Cliente $cliente = null, ?Producto $producto = null): Venta
    {
        $cliente = $cliente ?? Cliente::factory()->create(['domicilio' => 'Calle Falsa 123']);
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = $producto ?? Producto::factory()->create();

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [
                ['producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'cantidad' => 3, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ],
        ])->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    public function test_formulario_de_creacion_precarga_las_lineas_de_la_venta(): void
    {
        $venta = $this->crearVenta();

        $respuesta = $this->get(route('ventas.remitos.create', $venta));

        $respuesta->assertOk();
        $respuesta->assertSee($venta->items->first()->descripcion);
    }

    public function test_guardar_remito_asigna_numero_y_se_ve_en_el_detalle(): void
    {
        $venta = $this->crearVenta();
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated()->assertJsonPath('ok', true);

        $remito = $venta->fresh()->remitos()->firstOrFail();
        $this->assertNotEmpty($remito->nro_remito);
        $this->assertSame($venta->id, $remito->venta_id);
        $this->assertNull($remito->compra_id);

        $this->get(route('ventas.show', $venta))->assertOk()->assertSee('Remitos');
    }

    public function test_documento_del_remito_responde_200(): void
    {
        $venta = $this->crearVenta();
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();

        $remito = $venta->fresh()->remitos()->firstOrFail();

        $respuesta = $this->get(route('remitos.pdf', $remito));

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
    }

    public function test_cliente_sin_cuit_ni_condicion_iva_no_rompe_el_documento(): void
    {
        $cliente = Cliente::factory()->create(['domicilio' => 'Sin datos fiscales', 'cuit' => null, 'condicion_iva_id' => null]);
        $venta = $this->crearVenta($cliente);
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();

        $remito = $venta->fresh()->remitos()->firstOrFail();

        $this->get(route('remitos.pdf', $remito))->assertOk();
    }

    public function test_no_se_puede_guardar_un_remito_sin_lineas(): void
    {
        $venta = $this->crearVenta();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_editar_remito_sin_campos_bloqueados_actualiza_los_datos(): void
    {
        $venta = $this->crearVenta();
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'nota' => 'Nota original',
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();
        $remito = $venta->fresh()->remitos()->firstOrFail();

        $this->get(route('ventas.remitos.edit', [$venta, $remito]))->assertOk();

        $this->putJson(route('ventas.remitos.update', [$venta, $remito]), [
            'fecha' => now()->toDateString(),
            'nota' => 'Nota actualizada',
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 1],
            ],
        ])->assertOk()->assertJsonPath('ok', true);

        $remito->refresh();
        $this->assertSame('Nota actualizada', $remito->nota);
        $this->assertSame(1.0, (float) $remito->items->first()->cantidad);
    }

    public function test_eliminar_remito_lo_saca_de_la_seccion(): void
    {
        $venta = $this->crearVenta();
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();
        $remito = $venta->fresh()->remitos()->firstOrFail();

        $this->deleteJson(route('ventas.remitos.destroy', [$venta, $remito]))->assertOk()->assertJsonPath('ok', true);

        $this->assertModelMissing($remito);
        $this->assertCount(0, $venta->fresh()->remitos);
    }

    public function test_no_se_puede_guardar_un_remito_con_cantidad_cero(): void
    {
        $venta = $this->crearVenta();
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 0],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
    }

    /** US3 (FR-019, FR-020): el botón sigue disponible y cada remito parcial precarga las cantidades totales originales. */
    public function test_dos_remitos_conviven_sobre_la_misma_venta_cada_uno_con_su_numero(): void
    {
        $venta = $this->crearVenta();
        $item = $venta->items->first();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 1],
            ],
        ])->assertCreated();

        // El botón "Crear Remito" sigue disponible: se puede pedir el formulario de alta de nuevo.
        $this->get(route('ventas.remitos.create', $venta))->assertOk();

        $this->postJson(route('ventas.remitos.store', $venta), [
            'fecha' => now()->toDateString(),
            'items' => [
                ['producto_id' => $item->producto_id, 'descripcion' => $item->descripcion, 'cantidad' => 3],
            ],
        ])->assertCreated();

        $remitos = $venta->fresh()->remitos()->orderBy('id')->get();
        $this->assertCount(2, $remitos);
        $this->assertNotSame($remitos[0]->nro_remito, $remitos[1]->nro_remito);
        $this->assertSame(1.0, (float) $remitos[0]->items->first()->cantidad);
        $this->assertSame(3.0, (float) $remitos[1]->items->first()->cantidad);

        $this->get(route('remitos.pdf', $remitos[0]))->assertOk();
        $this->get(route('remitos.pdf', $remitos[1]))->assertOk();
    }

    /** FR-026: los remitos preexistentes (N° 1 y N° 2 en producción) no tienen ítems ni transportista. */
    public function test_remito_sin_items_ni_transportista_se_ve_en_el_detalle_y_su_documento_abre(): void
    {
        $venta = $this->crearVenta();
        $remitoHistorico = $venta->remitos()->create([
            'fecha' => now()->toDateString(),
            'nro_remito' => '1',
        ]);

        $this->get(route('ventas.show', $venta))->assertOk()->assertSee('Remitos');
        $this->get(route('remitos.pdf', $remitoHistorico))->assertOk();
    }
}
