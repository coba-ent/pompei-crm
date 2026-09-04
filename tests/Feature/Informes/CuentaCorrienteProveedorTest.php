<?php

namespace Tests\Feature\Informes;

use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Pago;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * spec 067 US3 — Cuenta Corriente Proveedores: aging, invariante Saldos ↔ Movimientos y
 * carácter de sólo lectura del informe.
 */
class CuentaCorrienteProveedorTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
        $this->autenticarConPermisoInformes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function saldos(array $params = []): array
    {
        return $this->getJson(route('informes.cuenta-corriente-proveedores.saldos.data', array_merge(
            ['draw' => 1, 'start' => 0, 'length' => 50], $params
        )))->assertOk()->json('data');
    }

    private function movimientos(array $params = []): array
    {
        return $this->getJson(route('informes.cuenta-corriente-proveedores.movimientos.data', array_merge(
            ['draw' => 1, 'start' => 0, 'length' => 50], $params
        )))->assertOk()->json('data');
    }

    /** Los cinco tramos del aging, uno por cada antigüedad de vencimiento. */
    public function test_buckets_de_aging(): void
    {
        $casos = [
            'a_vencer' => Carbon::today()->addDays(10),
            'vencido_0_30' => Carbon::today()->subDays(15),
            'vencido_31_60' => Carbon::today()->subDays(45),
            'vencido_61_90' => Carbon::today()->subDays(75),
            'vencido_mas_90' => Carbon::today()->subDays(120),
        ];

        foreach ($casos as $bucket => $vencimiento) {
            $proveedor = Proveedor::factory()->create(['nombre' => $bucket]);
            Compra::factory()->create([
                'proveedor_id' => $proveedor->id,
                'total' => 1000,
                'fecha_vto_pago' => $vencimiento->toDateString(),
            ]);
        }

        $filas = collect($this->saldos())->keyBy('proveedor_nombre');

        foreach (array_keys($casos) as $bucket) {
            $this->assertEquals(1000.0, $filas[$bucket][$bucket], "El documento tiene que caer en {$bucket}.");
            $this->assertEquals(1000.0, $filas[$bucket]['total']);
        }
    }

    /** FR-031: un saldo a favor (por NC) se lista con signo negativo, no se esconde. */
    public function test_saldo_negativo_se_lista(): void
    {
        $proveedor = Proveedor::factory()->create();
        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'total' => 1000,
            'fecha_vto_pago' => Carbon::today()->addDays(5)->toDateString(),
        ]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'credito', 'monto' => 1500,
        ]);

        $filas = $this->saldos();

        $this->assertCount(1, $filas);
        $this->assertEquals(-500.0, $filas[0]['total']);
    }

    public function test_saldo_dentro_de_tolerancia_no_se_lista(): void
    {
        $proveedor = Proveedor::factory()->create();
        $compra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 1000]);
        Pago::factory()->create(['compra_id' => $compra->id, 'monto' => 1000]);

        $this->assertCount(0, $this->saldos(), 'Una compra saldada no ocupa una fila del aging.');
    }

    /**
     * Un rezago de centavos tampoco se lista.
     *
     * Quedaron 7 ventas de 2021-2023 migradas de Contagram cobradas por centavos de más —la venta
     * tenía el total redondeado y el cobro el importe real—, con un desvío de $4,08 entre las 7 en
     * cinco años. Los datos no se corrigen: los cobros tienen movimiento de tesorería y la caja ya
     * concilió. Lo que sobra es mostrar como saldo vivo una diferencia de $0,02.
     */
    public function test_un_rezago_de_centavos_no_se_lista(): void
    {
        $proveedor = Proveedor::factory()->create();
        $compra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 1531.00]);
        Pago::factory()->create(['compra_id' => $compra->id, 'monto' => 1531.86]);

        $this->assertCount(0, $this->saldos(), 'Los $0,86 de más no son un saldo a mostrar.');
    }

    /** Pero un peso y medio SÍ: el umbral separa el ruido de un saldo real, por chico que sea. */
    public function test_un_saldo_de_mas_de_un_peso_si_se_lista(): void
    {
        $proveedor = Proveedor::factory()->create();
        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'total' => 1000,
            'fecha_vto_pago' => Carbon::today()->addDays(5)->toDateString(),
        ]);
        Pago::factory()->create(['compra_id' => $compra->id, 'monto' => 998.50]);

        $this->assertCount(1, $this->saldos());
    }

    /** FR-032: el saldo inicial crea la fila aunque el proveedor no tenga ninguna compra. */
    public function test_saldo_inicial_sin_compras_crea_fila(): void
    {
        $proveedor = Proveedor::factory()->create([
            'saldo_inicial' => 2500,
            'saldo_inicial_fecha' => Carbon::today()->subDays(40)->toDateString(),
        ]);

        $filas = $this->saldos();

        $this->assertCount(1, $filas);
        $this->assertEquals($proveedor->id, $filas[0]['proveedor_id']);
        $this->assertEquals(2500.0, $filas[0]['vencido_31_60']);
        $this->assertEquals(2500.0, $filas[0]['total']);
    }

    /**
     * FR-036: para cada proveedor, Σ `a_pagar` de las filas 'compra' más la de 'saldo_inicial'
     * tiene que dar su Total en Saldos. Es la prueba de que las dos tabs cuentan lo mismo.
     */
    public function test_saldos_coincide_con_movimientos(): void
    {
        $proveedor = Proveedor::factory()->create(['saldo_inicial' => 500, 'saldo_inicial_fecha' => '2026-01-10']);

        $compra1 = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 3000]);
        Pago::factory()->create(['compra_id' => $compra1->id, 'monto' => 1000]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $compra1->id, 'tipo' => 'credito', 'monto' => 200,
        ]);

        Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 1500]);

        $saldo = collect($this->saldos())->firstWhere('proveedor_id', $proveedor->id);
        $movimientos = collect($this->movimientos(['proveedor_id' => $proveedor->id]));

        $desdeMovimientos = $movimientos
            ->whereIn('operacion', ['compra', 'saldo_inicial'])
            ->sum(fn ($m) => (float) $m['a_pagar']);

        $this->assertEqualsWithDelta(
            (float) $saldo['total'],
            round($desdeMovimientos, 2),
            0.01,
            'Saldos y Movimientos tienen que contar exactamente la misma deuda (FR-036).'
        );
        $this->assertEqualsWithDelta(3800.0, (float) $saldo['total'], 0.01, '3000 − 1000 − 200 + 1500 + 500');
    }

    public function test_movimientos_proyecta_las_cinco_operaciones(): void
    {
        $proveedor = Proveedor::factory()->create(['saldo_inicial' => 100, 'saldo_inicial_fecha' => '2026-01-10']);
        $compra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 1000]);
        Pago::factory()->create(['compra_id' => $compra->id, 'monto' => 300]);
        NotaCreditoDebito::factory()->create(['venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'credito', 'monto' => 50]);
        NotaCreditoDebito::factory()->create(['venta_id' => null, 'compra_id' => $compra->id, 'tipo' => 'debito', 'monto' => 70]);

        $operaciones = collect($this->movimientos())->pluck('operacion')->unique()->sort()->values()->all();

        $this->assertSame(['compra', 'nota_credito', 'nota_debito', 'pago', 'saldo_inicial'], $operaciones);

        $movimientos = collect($this->movimientos())->keyBy('operacion');
        // NC resta y ND suma con una sola expresión, sin ramas por tipo (FR-016).
        $this->assertEquals(-50.0, $movimientos['nota_credito']['a_pagar']);
        $this->assertEquals(70.0, $movimientos['nota_debito']['a_pagar']);
        // La fila del pago lleva el importe en `pagado` y el comprobante de la compra cancelada.
        $this->assertEquals(300.0, $movimientos['pago']['pagado']);
        $this->assertNotNull($movimientos['pago']['nro_comprobante']);
    }

    public function test_compra_eliminada_no_aparece_en_movimientos(): void
    {
        $proveedor = Proveedor::factory()->create();
        Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 1000])->delete();

        $this->assertCount(0, $this->movimientos());
        $this->assertCount(0, $this->saldos());
    }

    public function test_filtro_de_operacion_acota_el_listado(): void
    {
        $proveedor = Proveedor::factory()->create();
        $compra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 1000]);
        Pago::factory()->create(['compra_id' => $compra->id, 'monto' => 300]);

        $soloPagos = $this->movimientos(['operacion' => 'pago']);

        $this->assertCount(1, $soloPagos);
        $this->assertSame('pago', $soloPagos[0]['operacion']);
    }

    public function test_ficha_de_proveedor_es_solo_lectura(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'Distribuidora SRL', 'nota' => 'Entrega los martes']);

        $ficha = $this->getJson(route('informes.cuenta-corriente-proveedores.proveedor.show', $proveedor))
            ->assertOk()->json();

        $this->assertSame('Distribuidora SRL', $ficha['proveedor']);
        $this->assertSame('Entrega los martes', $ficha['nota']);

        // FR-037: el informe entero no expone un solo verbo de escritura.
        $verbosDeEscritura = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'informes/cuenta-corriente-proveedores'))
            ->flatMap(fn ($r) => $r->methods())
            ->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])
            ->values()
            ->all();

        $this->assertSame([], $verbosDeEscritura, 'Un informe no puede ser una puerta lateral para editar el maestro.');
    }

    public function test_ficha_de_proveedor_inexistente_devuelve_404(): void
    {
        $this->getJson(route('informes.cuenta-corriente-proveedores.proveedor.show', ['proveedor' => 999999]))
            ->assertNotFound();
    }

    /** FR-038: `?proveedor_id=` precarga el filtro y abre directo en Movimientos. */
    public function test_deep_link_precarga_el_proveedor(): void
    {
        $proveedor = Proveedor::factory()->create(['nombre' => 'Distribuidora SRL']);

        $this->get(route('informes.cuenta-corriente-proveedores.index', ['proveedor_id' => $proveedor->id]))
            ->assertOk()
            // El <option selected> ya viene renderizado del servidor, y el id viaja en la config
            // del bundle para que el JS abra el tab Movimientos sin una segunda vuelta al server.
            ->assertSee('Distribuidora SRL')
            ->assertSee('proveedorId: '.$proveedor->id, false);
    }
}
