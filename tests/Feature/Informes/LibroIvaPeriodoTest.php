<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Services\Informes\LibroIvaComprasQuery;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * spec 077 — resolución del período fiscal (FR-008, FR-009, FR-009a): distinta expresión según
 * el origen de la fila (data-model.md §2).
 */
class LibroIvaPeriodoTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function request(string $ruta, int $mes, int $anio): Request
    {
        // arca+manuales=true en Ventas: estos tests versan sobre período, no sobre la partición
        // ARCA/Manuales (default arca=true) — se pide el universo completo. Compras ignora estos
        // dos parámetros (FR-014a), así que no afecta esa rama.
        return Request::create($ruta, 'POST', ['mes' => $mes, 'anio' => $anio, 'arca' => true, 'manuales' => true]);
    }

    /** FR-008: una venta se ubica por su `fecha_emision`. */
    public function test_venta_se_ubica_por_fecha_emision(): void
    {
        Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-08-20']);

        $agosto = app(LibroIvaVentasQuery::class)->detalle($this->request('/informes/contador/ventas/data', 8, 2026))->get();
        $julio = app(LibroIvaVentasQuery::class)->detalle($this->request('/informes/contador/ventas/data', 7, 2026))->get();

        $this->assertCount(1, $agosto);
        $this->assertCount(0, $julio);
    }

    /** FR-009/SC-003: compra imputada a un mes distinto del de emisión cae en el imputado. */
    public function test_compra_con_mes_imputacion_cae_en_el_mes_imputado(): void
    {
        Compra::factory()->create([
            'proveedor_id' => Proveedor::factory(),
            'fecha_emision' => '2026-07-28',
            'mes_imputacion_iva' => '2026-08-01',
        ]);

        $agosto = app(LibroIvaComprasQuery::class)->detalle($this->request('/informes/contador/compras/data', 8, 2026))->get();
        $julio = app(LibroIvaComprasQuery::class)->detalle($this->request('/informes/contador/compras/data', 7, 2026))->get();

        $this->assertCount(1, $agosto, 'Cae SÓLO en agosto, el mes imputado.');
        $this->assertCount(0, $julio);
    }

    /** FR-009: sin `mes_imputacion_iva`, la compra cae en el período de su `fecha_emision`. */
    public function test_compra_sin_mes_imputacion_cae_en_fecha_emision(): void
    {
        Compra::factory()->create([
            'proveedor_id' => Proveedor::factory(),
            'fecha_emision' => '2026-08-05',
            'mes_imputacion_iva' => null,
        ]);

        $agosto = app(LibroIvaComprasQuery::class)->detalle($this->request('/informes/contador/compras/data', 8, 2026))->get();

        $this->assertCount(1, $agosto);
    }

    /** FR-009a: la NC/ND cae en SU PROPIO `mes_imputacion`, no en el de la venta que ajusta. */
    public function test_nota_cae_en_su_propio_mes_imputacion(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-07-10']);

        NotaCreditoDebito::create([
            'venta_id' => $venta->id,
            'tipo' => 'credito',
            'afecta_stock' => false,
            'mes_imputacion' => '2026-08-01',
            'fecha_emision' => '2026-08-02',
            'monto' => 100,
            'tipo_comprobante' => 'A',
            'descripcion' => 'Nota',
        ]);

        $agosto = app(LibroIvaVentasQuery::class)->detalle($this->request('/informes/contador/ventas/data', 8, 2026))->get();
        $julio = app(LibroIvaVentasQuery::class)->detalle($this->request('/informes/contador/ventas/data', 7, 2026))->get();

        // Agosto: la venta NO cae acá (es de julio), pero la nota sí — dos filas distintas de
        // "julio venta" vs "agosto nota" demuestran que cada una usa su propio período.
        $this->assertCount(1, $agosto, 'Sólo la nota, imputada a agosto.');
        $this->assertCount(1, $julio, 'Sólo la venta, emitida en julio.');
    }
}
