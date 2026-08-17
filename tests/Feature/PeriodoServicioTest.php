<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Servicio Desde/Hasta" en el alta y la edición de Venta y Compra.
 *
 * El autocompletado (arrancar en la fecha de emisión) es de front y está cubierto por
 * `tests/js/fecha-ar-seguir.test.mjs`. Lo que se prueba acá es el otro extremo: que lo que el
 * formulario manda **llegue a la base y vuelva**, en los dos comprobantes.
 *
 * En Compra hace falta especialmente: el modelo y el listado ya filtraban por estos campos, pero
 * el formulario nunca los mostró ni los mandaba, así que este camino no estaba ejercitado.
 *
 * El caso peligroso son las fechas de día ≤ 12 — si algo invierte día y mes, `2026-08-05` se
 * guarda como 8 de mayo y sigue siendo una fecha válida, así que el error pasa en silencio.
 */
class PeriodoServicioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );
    }

    private function payloadVenta(array $extra = []): array
    {
        return array_merge([
            'cliente_id' => Cliente::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Local', 'activo' => true])->id,
            'fecha_emision' => '2026-08-05',
            'tipo_comprobante' => 'B',
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [
                ['producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                    'descripcion' => 'Servicio', 'cantidad' => 1,
                    'precio_unitario' => 100, 'iva_pct' => '21'],
            ],
        ], $extra);
    }

    private function payloadCompra(array $extra = []): array
    {
        return array_merge([
            'proveedor_id' => Proveedor::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Depósito', 'activo' => true])->id,
            'nro_comprobante' => '0001-'.fake()->unique()->numerify('########'),
            'fecha_emision' => '2026-08-05',
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [
                ['producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                    'descripcion' => 'Servicio', 'cantidad' => 1,
                    'precio_unitario' => 100, 'iva_pct' => '21'],
            ],
        ], $extra);
    }

    public function test_la_venta_guarda_el_periodo_de_servicio(): void
    {
        $this->postJson(route('ventas.store'), $this->payloadVenta([
            'servicio_desde' => '2026-08-05',
            'servicio_hasta' => '2026-08-05',
        ]))->assertSuccessful();

        $venta = Venta::latest('id')->first();

        $this->assertSame('2026-08-05', $venta->servicio_desde->format('Y-m-d'));
        $this->assertSame('2026-08-05', $venta->servicio_hasta->format('Y-m-d'));
    }

    public function test_la_compra_guarda_el_periodo_de_servicio(): void
    {
        // El camino que el formulario de Compra no tenía: los campos existían en la base y en los
        // filtros, pero no había forma de cargarlos salvo importándolos.
        $this->postJson(route('compras.store'), $this->payloadCompra([
            'servicio_desde' => '2026-08-05',
            'servicio_hasta' => '2026-09-04',
        ]))->assertSuccessful();

        $compra = Compra::latest('id')->first();

        $this->assertSame('2026-08-05', $compra->servicio_desde->format('Y-m-d'));
        $this->assertSame('2026-09-04', $compra->servicio_hasta->format('Y-m-d'));
    }

    public function test_el_periodo_de_servicio_es_opcional_en_los_dos(): void
    {
        // El autocompletado es una comodidad del front, no una regla de negocio: el vendedor
        // puede vaciar los campos y el comprobante tiene que guardarse igual.
        $this->postJson(route('ventas.store'), $this->payloadVenta())->assertSuccessful();
        $this->postJson(route('compras.store'), $this->payloadCompra())->assertSuccessful();

        $this->assertNull(Venta::latest('id')->first()->servicio_desde);
        $this->assertNull(Compra::latest('id')->first()->servicio_desde);
    }

    public function test_editar_una_compra_puede_cambiar_el_periodo_de_servicio(): void
    {
        $this->postJson(route('compras.store'), $this->payloadCompra([
            'servicio_desde' => '2026-08-05',
            'servicio_hasta' => '2026-09-04',
        ]))->assertSuccessful();

        $compra = Compra::latest('id')->first();

        $this->putJson(route('compras.update', $compra), $this->payloadCompra([
            'proveedor_id' => $compra->proveedor_id,
            'servicio_desde' => '2026-10-01',
            'servicio_hasta' => '2026-10-31',
        ]))->assertSuccessful();

        $compra->refresh();

        $this->assertSame('2026-10-01', $compra->servicio_desde->format('Y-m-d'));
        $this->assertSame('2026-10-31', $compra->servicio_hasta->format('Y-m-d'));
    }

    public function test_editar_no_pierde_el_periodo_que_ya_tenia_la_venta(): void
    {
        // La contracara del autocompletado: en edición el front NO precarga, así que si el
        // comprobante ya tenía un período, tiene que sobrevivir a un guardado que no lo toca.
        $this->postJson(route('ventas.store'), $this->payloadVenta([
            'servicio_desde' => '2026-08-05',
            'servicio_hasta' => '2026-08-05',
        ]))->assertSuccessful();

        $venta = Venta::latest('id')->first();

        $this->putJson(route('ventas.update', $venta), $this->payloadVenta([
            'cliente_id' => $venta->cliente_id,
            'deposito_id' => $venta->deposito_id,
            'servicio_desde' => '2026-08-05',
            'servicio_hasta' => '2026-08-05',
        ]))->assertSuccessful();

        $venta->refresh();

        $this->assertSame('2026-08-05', $venta->servicio_desde->format('Y-m-d'),
            'día 05: si algo invirtiera día y mes daría 2026-05-08 sin fallar');
    }
}
