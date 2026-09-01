<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\Stock\FilaInformeStock;
use App\Services\Stock\ResolvedorOperacionLegacy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El matcheo entre las filas del informe y las operaciones del CRM (spec 094).
 *
 * Un movimiento apuntando a la venta equivocada es peor que uno huérfano: el huérfano se ve, el mal
 * atribuido no. Por eso el resolvedor devuelve null antes que adivinar.
 */
class ResolvedorOperacionLegacyTest extends TestCase
{
    use RefreshDatabase;

    private function fila(array $sobreescribe = []): FilaInformeStock
    {
        return new FilaInformeStock(
            idOperacion: $sobreescribe['idOperacion'] ?? 15963,
            fecha: CarbonImmutable::parse($sobreescribe['fecha'] ?? '2024-12-30'),
            usuario: 'Info Pompei',
            operacion: $sobreescribe['operacion'] ?? 'Venta',
            descripcion: null,
            codigo: $sobreescribe['codigo'] ?? '28379 BAR-TP-005-BL TK',
            cantidad: $sobreescribe['cantidad'] ?? -1,
            deposito: 'Local',
            saldo: null,
            anio: $sobreescribe['anio'] ?? 2024,
            fila: 5,
        );
    }

    private function venta(string $legacyId): Venta
    {
        $venta = Venta::factory()->create();
        DB::table('ventas')->where('id', $venta->id)->update(['legacy_id' => $legacyId]);

        return $venta->refresh();
    }

    /** El formato de `legacy_id` verificado contra los datos reales: venta 15963 → `2024-FC-15963`. */
    public function test_matchea_una_venta_por_su_legacy_id(): void
    {
        $venta = $this->venta('2024-FC-15963');

        $operacion = (new ResolvedorOperacionLegacy)->operacion($this->fila());

        $this->assertSame([Venta::class, $venta->id], $operacion);
    }

    /** Las compras usan otro prefijo: compra 1883 de 2025 → `COMPRA-2025-FC-1883`. */
    public function test_matchea_una_compra_por_su_legacy_id(): void
    {
        $compra = Compra::factory()->create();
        DB::table('compras')->where('id', $compra->id)->update(['legacy_id' => 'COMPRA-2025-FC-1883']);

        $operacion = (new ResolvedorOperacionLegacy)->operacion(
            $this->fila(['idOperacion' => 1883, 'operacion' => 'Compra', 'anio' => 2025])
        );

        $this->assertSame([Compra::class, $compra->id], $operacion);
    }

    /** Un ID que no existe NO se fuerza a nada: devuelve null y la fila se carga sin origen (FR-003). */
    public function test_un_id_inexistente_no_resuelve_a_ninguna_operacion(): void
    {
        $this->venta('2024-FC-15963');

        $this->assertNull((new ResolvedorOperacionLegacy)->operacion($this->fila(['idOperacion' => 99999])));
    }

    /** El mismo número en otro año es otra operación: el año es parte de la clave. */
    public function test_el_mismo_numero_en_otro_anio_no_matchea(): void
    {
        $this->venta('2024-FC-15963');

        $this->assertNull((new ResolvedorOperacionLegacy)->operacion($this->fila(['anio' => 2025])));
    }

    /** El `codigo` del CRM tiene formato "{id} {sku}", igual que el del Excel. */
    public function test_matchea_el_producto_por_codigo_exacto(): void
    {
        $producto = Producto::create([
            'nombre' => 'TAPA ASIENTO', 'tipo' => 'producto', 'codigo' => '28379 BAR-TP-005-BL TK',
        ]);

        $resolvedor = new ResolvedorOperacionLegacy;

        $this->assertSame($producto->id, $resolvedor->producto($this->fila(), null));
    }

    /**
     * EL CASO QUE MOTIVÓ LA DESAMBIGUACIÓN POR OPERACIÓN.
     *
     * El comodín "99999" está duplicado en la base real: uno tiene el stock y el OTRO es el que
     * usan 273 ventas legacy. El Excel lo trae con un tercer código que no coincide con ninguno.
     * Elegir "el que tiene stock" habría asignado 687 movimientos al producto equivocado.
     *
     * La desambiguación correcta es por la operación: gana el que está en los items de esa venta.
     */
    public function test_desambigua_por_los_items_de_la_operacion_cuando_el_codigo_no_matchea(): void
    {
        $conStock = Producto::create(['nombre' => '99999', 'tipo' => 'producto', 'codigo' => '30622 99999']);
        $elQueUsanLasVentas = Producto::create(['nombre' => '99999', 'tipo' => 'producto', 'codigo' => '30622']);

        $venta = $this->venta('2024-FC-15963');
        $venta->items()->create([
            'producto_id' => $elQueUsanLasVentas->id, 'descripcion' => '99999',
            'cantidad' => 1, 'precio_unitario' => 100, 'subtotal' => 100, 'subtotal_con_iva' => 121,
        ]);

        $resolvedor = new ResolvedorOperacionLegacy;
        $fila = $this->fila(['codigo' => '30622 30622']);
        $operacion = $resolvedor->operacion($fila);

        $this->assertSame(
            $elQueUsanLasVentas->id,
            $resolvedor->producto($fila, $operacion),
            'Gana el producto que está en los items de la venta, no el que tiene el stock.'
        );
        $this->assertNotSame($conStock->id, $resolvedor->producto($fila, $operacion));
    }

    /** Sin operación que desambigüe y con varios candidatos, se prefiere no cargar antes que adivinar. */
    public function test_no_elige_entre_candidatos_ambiguos_sin_operacion(): void
    {
        Producto::create(['nombre' => 'A', 'tipo' => 'producto', 'codigo' => '30622 99999']);
        Producto::create(['nombre' => 'B', 'tipo' => 'producto', 'codigo' => '30622']);

        $resolvedor = new ResolvedorOperacionLegacy;

        $this->assertNull($resolvedor->producto($this->fila(['codigo' => '30622 30622']), null));
    }

    /** Con un único candidato no hay ambigüedad posible: el número inicial alcanza. */
    public function test_usa_el_numero_inicial_cuando_hay_un_solo_candidato(): void
    {
        $producto = Producto::create(['nombre' => 'A', 'tipo' => 'producto', 'codigo' => '40179 OTRO-SKU']);

        $resolvedor = new ResolvedorOperacionLegacy;

        $this->assertSame($producto->id, $resolvedor->producto($this->fila(['codigo' => '40179 AND-DP-014-BL']), null));
    }

    /** Un producto que ya no existe se saltea; no se crea nada (FR-005). */
    public function test_un_codigo_sin_producto_devuelve_null(): void
    {
        $this->assertNull((new ResolvedorOperacionLegacy)->producto($this->fila(['codigo' => '99999 INEXISTENTE']), null));
    }
}
