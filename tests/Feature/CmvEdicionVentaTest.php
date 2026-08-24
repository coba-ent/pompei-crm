<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Stock;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * spec 075 FR-009 — la edición de una venta NO recongela el costo.
 *
 * `VentaController::update()` borra y recrea todas las líneas, así que sin la conservación
 * explícita bastaría con editar la fecha de vencimiento para que el CMV de esa venta cambiara.
 * Es el punto más filoso de la feature (`research.md §R5`), y por eso tiene archivo propio.
 */
class CmvEdicionVentaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private Deposito $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        $this->cliente = Cliente::factory()->create();
        $this->deposito = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    private function producto(float $costo): Producto
    {
        $producto = Producto::factory()->create(['tipo' => 'producto', 'costo' => $costo]);
        Stock::create([
            'producto_id' => $producto->id, 'deposito_id' => $this->deposito->id, 'cantidad' => 1000,
        ]);

        return $producto;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function crearVenta(array $items): Venta
    {
        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) Str::uuid(),
            'cliente_id' => $this->cliente->id,
            'deposito_id' => $this->deposito->id,
            'fecha_emision' => '2026-08-10',
            'tipo_comprobante' => 'B',
            'items' => $items,
        ])->assertCreated();

        return Venta::latest('id')->firstOrFail();
    }

    /** @param  list<array<string, mixed>>  $items */
    private function editarVenta(Venta $venta, array $items, array $extra = []): void
    {
        $this->putJson(route('ventas.update', $venta), array_merge([
            'cliente_id' => $this->cliente->id,
            'deposito_id' => $this->deposito->id,
            'fecha_emision' => '2026-08-10',
            'tipo_comprobante' => 'B',
            'items' => $items,
        ], $extra))->assertOk();
    }

    /** @return list<float|null> */
    private function costos(Venta $venta): array
    {
        return VentaItem::where('venta_id', $venta->id)->orderBy('id')
            ->pluck('costo_unitario')
            ->map(fn ($c) => $c === null ? null : (float) $c)
            ->all();
    }

    // -----------------------------------------------------------------------------------

    public function test_el_alta_congela_el_costo_vigente_del_producto(): void
    {
        $producto = $this->producto(150);

        $venta = $this->crearVenta([
            ['producto_id' => $producto->id, 'descripcion' => 'P', 'cantidad' => 2, 'precio_unitario' => 500, 'iva_pct' => '21'],
        ]);

        $this->assertSame([150.0], $this->costos($venta));
    }

    public function test_una_linea_sin_producto_congela_cero_y_no_null(): void
    {
        // `0` = "costo congelado que vale cero"; `null` sería "sin congelar" y mandaría la línea
        // al promedio de compras, que para un concepto libre no significa nada (FR-007).
        $venta = $this->crearVenta([
            ['descripcion' => 'Flete', 'cantidad' => 1, 'precio_unitario' => 800, 'iva_pct' => '21'],
        ]);

        $this->assertSame([0.0], $this->costos($venta));
    }

    public function test_editar_solo_la_cabecera_conserva_el_costo_congelado(): void
    {
        $producto = $this->producto(150);
        $linea = ['producto_id' => $producto->id, 'descripcion' => 'P', 'cantidad' => 2, 'precio_unitario' => 500, 'iva_pct' => '21'];
        $venta = $this->crearVenta([$linea]);

        $producto->update(['costo' => 900]);

        $this->editarVenta($venta, [$linea], ['nota_interna' => 'Retoque de cabecera']);

        $this->assertSame([150.0], $this->costos($venta), 'la edición recongeló el costo del día');
    }

    public function test_una_linea_agregada_en_la_edicion_congela_el_costo_del_dia(): void
    {
        $viejo = $this->producto(150);
        $nuevo = $this->producto(700);
        $lineaVieja = ['producto_id' => $viejo->id, 'descripcion' => 'P', 'cantidad' => 2, 'precio_unitario' => 500, 'iva_pct' => '21'];
        $venta = $this->crearVenta([$lineaVieja]);

        $viejo->update(['costo' => 900]);

        $this->editarVenta($venta, [
            $lineaVieja,
            ['producto_id' => $nuevo->id, 'descripcion' => 'N', 'cantidad' => 1, 'precio_unitario' => 300, 'iva_pct' => '21'],
        ]);

        // La preexistente conserva su costo; la nueva congela el de hoy.
        $this->assertSame([150.0, 700.0], $this->costos($venta));
    }

    public function test_el_mismo_producto_en_dos_lineas_conserva_los_dos_costos(): void
    {
        // El caso que rompe una correspondencia ingenua por `producto_id`: si el costo anterior no
        // se consumiera una sola vez, la segunda línea repetiría el de la primera.
        $producto = $this->producto(150);
        $linea = ['producto_id' => $producto->id, 'descripcion' => 'P', 'cantidad' => 1, 'precio_unitario' => 500, 'iva_pct' => '21'];
        $venta = $this->crearVenta([$linea, $linea]);

        // Se altera a mano el costo de la segunda línea para poder distinguirlas.
        $items = VentaItem::where('venta_id', $venta->id)->orderBy('id')->get();
        $items[1]->update(['costo_unitario' => 250]);

        $producto->update(['costo' => 900]);

        $this->editarVenta($venta, [$linea, $linea]);

        $this->assertSame([150.0, 250.0], $this->costos($venta));
    }

    public function test_una_venta_historica_sin_costo_congelado_sigue_sin_costo_al_editarla(): void
    {
        // Editar una venta importada no puede inventarle un costo congelado con el precio de hoy:
        // eso la sacaría del fallback y falsearía su CMV (`data-model.md §1`).
        $producto = $this->producto(150);
        $linea = ['producto_id' => $producto->id, 'descripcion' => 'P', 'cantidad' => 1, 'precio_unitario' => 500, 'iva_pct' => '21'];
        $venta = $this->crearVenta([$linea]);

        VentaItem::where('venta_id', $venta->id)->update(['costo_unitario' => null]);
        $producto->update(['costo' => 900]);

        $this->editarVenta($venta, [$linea]);

        $this->assertSame([null], $this->costos($venta), 'la edición le inventó un costo congelado a una venta histórica');
    }
}
