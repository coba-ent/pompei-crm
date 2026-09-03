<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Services\AjustesPendientesNotaCreditoDebito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 099 — La validación de NC/ND de compra valida por línea, no por producto agregado.
 *
 * Bug real, compra 2478 (MERCADO LIBRE, $9.713.943): 3 líneas del mismo producto comodín — +1, −1
 * y +1 — con una nota ya emitida sobre la tercera. Al intentar la segunda nota, el alta se rechaza
 * con "la cantidad máxima disponible para ajustar es 0", pese a que la línea de $4.616.354 nunca
 * fue ajustada.
 *
 * La validación llamaba a `pendiente()`, que suma TODAS las líneas del producto: +1 − 1 + 1 = 1.
 * La línea negativa —un ajuste de Mercado Libre— se comía una de las positivas.
 *
 * El sistema se contradecía a sí mismo, verificado ejecutando en producción:
 *
 *     itemsDisponibles()  → línea 12022, pendiente 1, precio $4.616.354   ← la pantalla la ofrece
 *     pendiente(100000)   → 0                                            ← la validación la rechaza
 *
 * Es la factura mensual de comisiones de ML: 20 compras con ese patrón, una por mes sin faltar
 * ninguno desde octubre 2025. El cliente lo reportó como "todos los meses me pasa lo mismo".
 */
class NotaCreditoDebitoValidacionPorLineaTest extends TestCase
{
    use RefreshDatabase;

    private AjustesPendientesNotaCreditoDebito $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        $this->servicio = app(AjustesPendientesNotaCreditoDebito::class);
    }

    private function deposito(): Deposito
    {
        return Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);
    }

    /**
     * Réplica de la compra 2478: 3 líneas del mismo producto, una negativa.
     *
     * @return array{0: Compra, 1: CompraItem, 2: CompraItem, 3: CompraItem}
     */
    private function compraDeMercadoLibre(): array
    {
        $compra = Compra::factory()->create(['proveedor_id' => Proveedor::factory(), 'total' => 9713943]);
        $producto = Producto::factory()->create(['nombre' => '99999']);

        $linea = fn (float $cantidad, float $precio) => $compra->items()->create([
            'producto_id' => $producto->id, 'descripcion' => '99999',
            'cantidad' => $cantidad, 'precio_unitario' => $precio, 'iva_pct' => '21',
            'subtotal' => $cantidad * $precio, 'subtotal_con_iva' => $cantidad * $precio * 1.21,
        ]);

        // El orden importa: reproduce el de la compra real (negativa primero).
        $negativa = $linea(-1, 343008.14);
        $libre = $linea(1, 4616354.23);
        $ajustada = $linea(1, 2167324.95);

        return [$compra->fresh(), $negativa, $libre, $ajustada];
    }

    /** Emite la primera nota sobre una línea, como ya existe en la compra real. */
    private function emitirNotaSobre(Compra $compra, $linea, float $precio): void
    {
        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $this->deposito()->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => round($precio * 1.21, 2),
            'items' => [[
                'producto_id' => $linea->producto_id, 'cantidad' => 1,
                'precio' => $precio, 'item_origen_id' => $linea->id,
            ]],
        ])->assertCreated();
    }

    /**
     * SC-003: la contradicción que originó la spec. La pantalla ofrece una línea que la validación
     * rechaza. Este test la fija para que no vuelvan a divergir.
     */
    public function test_la_pantalla_y_la_validacion_coinciden(): void
    {
        [$compra, , $libre, $ajustada] = $this->compraDeMercadoLibre();
        $this->emitirNotaSobre($compra, $ajustada, 2167324.95);

        $ofrecidas = collect($this->servicio->itemsDisponibles($compra->fresh()))
            ->pluck('item_origen_id')->all();

        $this->assertContains($libre->id, $ofrecidas, 'La línea libre se ofrece en pantalla.');
        $this->assertNotContains($ajustada->id, $ofrecidas, 'La ya ajustada no se ofrece.');
    }

    /**
     * SC-001: EL CASO DEL CLIENTE. Antes del fix esto falla con "la cantidad máxima disponible para
     * ajustar es 0", porque la línea negativa se come una unidad en la suma agregada.
     */
    public function test_se_puede_ajustar_la_linea_que_nunca_fue_ajustada(): void
    {
        [$compra, , $libre, $ajustada] = $this->compraDeMercadoLibre();
        $this->emitirNotaSobre($compra, $ajustada, 2167324.95);

        $this->postJson(route('compras.notas.store', $compra->fresh()), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $this->deposito()->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 5585788.62,
            'items' => [[
                'producto_id' => $libre->producto_id, 'cantidad' => 1,
                'precio' => 4616354.23, 'item_origen_id' => $libre->id,
            ]],
        ])->assertCreated();

        $this->assertCount(2, $compra->fresh()->notasCreditoDebito);
    }

    /**
     * SC-002 ⚠️ EL TEST QUE DISTINGUE "arreglé el bug" DE "rompí la validación".
     *
     * Los dos hacen que la compra 2478 deje de dar error. Sólo éste detecta el segundo: ajustar
     * DE NUEVO una línea ya cubierta tiene que seguir rechazado. Es lo único que impide emitir una
     * nota de crédito por más de lo facturado sobre un comprobante fiscal.
     */
    public function test_no_se_puede_ajustar_dos_veces_la_misma_linea(): void
    {
        [$compra, , , $ajustada] = $this->compraDeMercadoLibre();
        $this->emitirNotaSobre($compra, $ajustada, 2167324.95);

        $this->postJson(route('compras.notas.store', $compra->fresh()), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $this->deposito()->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 2622463.19,
            'items' => [[
                'producto_id' => $ajustada->producto_id, 'cantidad' => 1,
                'precio' => 2167324.95, 'item_origen_id' => $ajustada->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');

        $this->assertCount(1, $compra->fresh()->notasCreditoDebito);
    }

    /** FR-009: tampoco se puede pedir más cantidad de la que la línea tiene facturada. */
    public function test_no_se_puede_ajustar_mas_de_lo_facturado_en_la_linea(): void
    {
        [$compra, , $libre] = $this->compraDeMercadoLibre();

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $this->deposito()->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'items' => [[
                'producto_id' => $libre->producto_id, 'cantidad' => 5,
                'precio' => 4616354.23, 'item_origen_id' => $libre->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
    }

    /**
     * FR-003: un `item_origen_id` de OTRA compra no puede usarse para saltear el tope. Cae al
     * cálculo agregado, que es el más restrictivo, y no lanza excepción.
     */
    public function test_una_linea_de_otra_compra_no_saltea_el_tope(): void
    {
        [$compra, , , $ajustada] = $this->compraDeMercadoLibre();
        $this->emitirNotaSobre($compra, $ajustada, 2167324.95);

        [, , $lineaAjena] = $this->compraDeMercadoLibre();

        $this->postJson(route('compras.notas.store', $compra->fresh()), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $this->deposito()->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'items' => [[
                'producto_id' => $ajustada->producto_id, 'cantidad' => 1,
                'precio' => 2167324.95, 'item_origen_id' => $lineaAjena->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
    }

    /**
     * FR-002: sin `item_origen_id` se mantiene el cálculo agregado, que es el camino que hoy usa el
     * 99% de las notas (723 items contra 8 en producción). No puede cambiar de comportamiento.
     */
    public function test_sin_item_origen_id_se_valida_por_el_agregado(): void
    {
        [$compra, , $libre] = $this->compraDeMercadoLibre();

        // Agregado del producto: +1 − 1 + 1 = 1. Pedir 2 supera el tope.
        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $this->deposito()->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 100,
            'items' => [['producto_id' => $libre->producto_id, 'cantidad' => 2, 'precio' => 1000]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.cantidad');
    }

    /** FR-008: un comprobante sin producto repetido valida exactamente igual que antes. */
    public function test_un_comprobante_sin_producto_repetido_no_cambia(): void
    {
        $compra = Compra::factory()->create(['proveedor_id' => Proveedor::factory(), 'total' => 12100]);
        $producto = Producto::factory()->create();
        $linea = $compra->items()->create([
            'producto_id' => $producto->id, 'descripcion' => 'Insumo', 'cantidad' => 2,
            'precio_unitario' => 5000, 'iva_pct' => '21', 'subtotal' => 10000, 'subtotal_con_iva' => 12100,
        ]);

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $this->deposito()->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 12100,
            'items' => [[
                'producto_id' => $producto->id, 'cantidad' => 2,
                'precio' => 5000, 'item_origen_id' => $linea->id,
            ]],
        ])->assertCreated();

        // Y pasarse sigue rechazado.
        $this->postJson(route('compras.notas.store', $compra->fresh()), [
            'tipo' => 'credito', 'afecta_stock' => true, 'deposito_id' => $this->deposito()->id,
            'mes_imputacion' => now()->toDateString(), 'fecha_emision' => now()->toDateString(),
            'monto' => 6050,
            'items' => [[
                'producto_id' => $producto->id, 'cantidad' => 1,
                'precio' => 5000, 'item_origen_id' => $linea->id,
            ]],
        ])->assertStatus(422);
    }
}
