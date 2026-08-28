<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * spec 077, US2 — las 4 ramas de precedencia del desglose de una NC/ND (FR-022d, data-model §4):
 * el punto más delicado de la feature. Un test dedicado por rama.
 */
class LibroIvaNotasDesgloseTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function request(): Request
    {
        // arca+manuales=true: estos tests versan sobre el DESGLOSE impositivo de la nota, no sobre
        // la partición ARCA/Manuales (eso lo cubre LibroIvaArcaManualesTest). Las notas de acá no
        // tienen comprobante fiscal, así que con el default (sólo ARCA) quedarían fuera del universo.
        return Request::create('/informes/contador/ventas/data', 'POST', ['mes' => 8, 'anio' => 2026, 'arca' => true, 'manuales' => true]);
    }

    private function filas()
    {
        return app(LibroIvaVentasQuery::class)->detalle($this->request())->get();
    }

    private function ventaGravada(array $alicuotasYNetos): Venta
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-01']);

        foreach ($alicuotasYNetos as $alicuota => $neto) {
            VentaItem::create([
                'venta_id' => $venta->id, 'descripcion' => 'Ítem',
                'cantidad' => 1, 'precio_unitario' => $neto, 'iva_pct' => $alicuota,
                'subtotal' => $neto, 'subtotal_con_iva' => round($neto * (1 + ((float) $alicuota) / 100), 2),
            ]);
        }

        return $venta;
    }

    /** Rama 1: la nota tiene entradas de IVA propias en su JSON `impuestos`. */
    public function test_rama_1_usa_las_entradas_de_iva_propias(): void
    {
        $venta = $this->ventaGravada(['21' => 1000.0]);

        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01', 'fecha_emision' => '2026-08-05',
            'monto' => 605.0, 'tipo_comprobante' => 'A', 'descripcion' => 'Nota con IVA propio',
            // Alícuota distinta de la de la venta origen (10,5%): rama 1 tiene que ganarle a la 2.
            'impuestos' => [['tipo' => 'iva', 'alicuota' => '10.5', 'neto' => 547.5, 'iva' => 57.5]],
        ]);

        $nota = $this->filas()->firstWhere('tipo', 'NCA');

        $this->assertNotNull($nota);
        $this->assertEqualsWithDelta(-547.5, (float) $nota->neto_gravado, 0.01);
        $this->assertEqualsWithDelta(-57.5, (float) $nota->iva_10_5, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $nota->iva_21, 0.01, 'La 21% (heredada) NO se usa: rama 1 gana.');
    }

    /** Rama 2: sin entradas propias, el comprobante ajustado tiene UNA sola alícuota. */
    public function test_rama_2_hereda_la_alicuota_unica(): void
    {
        $venta = $this->ventaGravada(['21' => 1000.0]);

        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01', 'fecha_emision' => '2026-08-05',
            'monto' => 121.0, 'tipo_comprobante' => 'A', 'descripcion' => 'Nota sin impuestos propios',
        ]);

        $nota = $this->filas()->firstWhere('tipo', 'NCA');

        $this->assertNotNull($nota);
        // neto = 121 / 1.21 = 100; iva = 21.
        $this->assertEqualsWithDelta(-100.0, (float) $nota->neto_gravado, 0.01);
        $this->assertEqualsWithDelta(-21.0, (float) $nota->iva_21, 0.01);
    }

    /** Rama 3: el comprobante ajustado combina varias alícuotas — se prorratea. */
    public function test_rama_3_prorratea_entre_varias_alicuotas(): void
    {
        // Venta con 1000 gravado a 21% (neto) y 1000 gravado a 10,5% (neto): total facturado
        // 1210 + 1105 = 2315.
        $venta = $this->ventaGravada(['21' => 1000.0, '10.5' => 1000.0]);

        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01', 'fecha_emision' => '2026-08-05',
            'monto' => 231.5, 'tipo_comprobante' => 'A', 'descripcion' => 'Nota parcial, ambas alícuotas',
        ]);

        $nota = $this->filas()->firstWhere('tipo', 'NCA');

        $this->assertNotNull($nota);
        // Reparto 50/50 (mismo neto en cada alícuota): 115.75 a cada rama del monto.
        // 21%: neto = 115.75/1.21 ≈ 95.66, iva ≈ 20.09. 10,5%: neto ≈ 104.75, iva ≈ 11.00.
        $totalNeto = abs((float) $nota->neto_gravado);
        $totalIva = abs((float) $nota->iva_21) + abs((float) $nota->iva_10_5);
        $this->assertEqualsWithDelta(231.5, $totalNeto + $totalIva, 0.05, 'El desglose reconstruye el monto de la nota.');
        $this->assertGreaterThan(0, abs((float) $nota->iva_21));
        $this->assertGreaterThan(0, abs((float) $nota->iva_10_5));
    }

    /** Rama 4: sin comprobante ajustado identificable — todo a No Gravado. */
    public function test_rama_4_sin_comprobante_ajustado_va_a_no_gravado(): void
    {
        // Venta sin ítems gravados (sólo exento/no_gravado): no hay alícuota que heredar.
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-01']);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem exento', 'cantidad' => 1,
            'precio_unitario' => 500, 'iva_pct' => 'exento', 'subtotal' => 500, 'subtotal_con_iva' => 500,
        ]);

        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01', 'fecha_emision' => '2026-08-05',
            'monto' => 200.0, 'tipo_comprobante' => 'A', 'descripcion' => 'Nota sin alícuota que heredar',
        ]);

        $nota = $this->filas()->firstWhere('tipo', 'NCA');

        $this->assertNotNull($nota);
        $this->assertEqualsWithDelta(-200.0, (float) $nota->neto_no_gravado, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $nota->neto_gravado, 0.01);
    }
}
