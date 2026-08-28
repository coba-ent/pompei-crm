<?php

namespace Tests\Feature\Informes;

use App\Models\Cliente;
use App\Models\ComprobanteFiscal;
use App\Models\NotaCreditoDebito;
use App\Models\Proveedor;
use App\Models\Compra;
use App\Models\Venta;
use App\Services\Informes\LibroIvaComprasQuery;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * spec 077, US3 — ARCA vs. Manuales (sólo IVA Ventas): FR-014/016/017/018/019.
 *
 * El test del incidente Venta 24447 (T040) es el más importante de esta suite: si alguien
 * reintroduce el `morphOne` `comprobanteFiscal()` en vez del `EXISTS`, falla acá.
 */
class LibroIvaArcaManualesTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autenticarConPermisoInformes();
    }

    private function request(array $extra = []): Request
    {
        return Request::create('/informes/contador/ventas/data', 'POST', array_merge(['mes' => 3, 'anio' => 2026], $extra));
    }

    private function ventaFirme(): Venta
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-03-10']);
        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class, 'comprobantable_id' => $venta->id,
            'tipo_comprobante' => 'A', 'estado' => 'aprobado', 'cae' => '12345678901234',
            'cae_vencimiento' => '2026-04-01', 'numero' => '0001-00001234',
        ]);

        return $venta;
    }

    private function ventaManual(): Venta
    {
        return Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-03-11']);
    }

    /** FR-017/SC-004: exhaustiva y sin solapamiento. */
    public function test_particion_es_exhaustiva_y_sin_solapamiento(): void
    {
        $this->ventaFirme();
        $this->ventaManual();

        $ambas = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => true, 'manuales' => true]))->get();
        $soloArca = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => true, 'manuales' => false]))->get();
        $soloManuales = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => false, 'manuales' => true]))->get();

        $this->assertCount(2, $ambas);
        $this->assertCount(1, $soloArca);
        $this->assertCount(1, $soloManuales);
        $this->assertSame($ambas->pluck('id')->sort()->values()->all(), $soloArca->concat($soloManuales)->pluck('id')->sort()->values()->all());
    }

    /** FR-018: incidente Venta 24447 — un rechazo y un aprobado cuentan UNA sola vez y como firme. */
    public function test_venta_con_rechazo_y_luego_aprobado_cuenta_una_vez_y_firme(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-03-10']);

        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class, 'comprobantable_id' => $venta->id,
            'tipo_comprobante' => 'A', 'estado' => 'rechazado', 'motivo_rechazo' => 'Error de prueba',
        ]);
        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class, 'comprobantable_id' => $venta->id,
            'tipo_comprobante' => 'A', 'estado' => 'aprobado', 'cae' => '12345678901234',
            'cae_vencimiento' => '2026-09-01', 'numero' => '0001-00001234',
        ]);

        $soloArca = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => true, 'manuales' => false]))->get();

        $this->assertCount(1, $soloArca, 'Una venta con un rechazo y un aprobado es UNA fila.');
        $this->assertSame($venta->id, $soloArca->first()->id);
    }

    /** FR-016: una venta rechazada cae en manuales. */
    public function test_venta_rechazada_cae_en_manuales(): void
    {
        $venta = Venta::factory()->create(['cliente_id' => Cliente::factory(), 'fecha_emision' => '2026-03-10']);
        ComprobanteFiscal::create([
            'comprobantable_type' => Venta::class, 'comprobantable_id' => $venta->id,
            'tipo_comprobante' => 'A', 'estado' => 'rechazado', 'motivo_rechazo' => 'Error',
        ]);

        $manuales = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => false, 'manuales' => true]))->get();
        $arca = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => true, 'manuales' => false]))->get();

        $this->assertCount(1, $manuales);
        $this->assertCount(0, $arca);
    }

    /** FR-019: ambas destildadas, vacío sin error. */
    public function test_ambas_casillas_destildadas_da_vacio_sin_error(): void
    {
        $this->ventaFirme();

        $filas = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => false, 'manuales' => false]))->get();
        $totales = app(LibroIvaVentasQuery::class)->totales($this->request(['arca' => false, 'manuales' => false]));

        $this->assertCount(0, $filas);
        $this->assertSame(0.0, $totales['total_facturado']);
    }

    /**
     * Las NC/ND participan de la misma partición que los comprobantes: una nota emitida
     * electrónicamente (con su `comprobantes_fiscales` aprobado) es "ARCA", una sin él es "Manual".
     *
     * Antes caían SIEMPRE en las dos vistas, así que el total con una sola casilla tildada nunca
     * cerraba contra Contagram — que sí las distingue (NCEB electrónica vs NCMB manual).
     */
    public function test_nota_firme_cae_en_arca_y_nota_sin_comprobante_en_manuales(): void
    {
        $venta = $this->ventaManual();

        $notaFirme = NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-03-01', 'fecha_emision' => '2026-03-15',
            'monto' => 100, 'tipo_comprobante' => 'B', 'descripcion' => 'Electrónica',
        ]);
        ComprobanteFiscal::create([
            'comprobantable_type' => NotaCreditoDebito::class, 'comprobantable_id' => $notaFirme->id,
            'tipo_comprobante' => 'B', 'estado' => 'aprobado', 'cae' => '12345678901234',
            'cae_vencimiento' => '2026-04-01', 'numero' => '0001-00000001',
        ]);

        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-03-01', 'fecha_emision' => '2026-03-16',
            'monto' => 50, 'tipo_comprobante' => 'B', 'descripcion' => 'Manual',
        ]);

        $arca = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => true, 'manuales' => false]))->get();
        $manuales = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => false, 'manuales' => true]))->get();

        $notasArca = $arca->filter(fn ($f) => str_starts_with($f->tipo, 'NC'));
        $notasManuales = $manuales->filter(fn ($f) => str_starts_with($f->tipo, 'NC'));

        $this->assertCount(1, $notasArca, 'Sólo la nota con comprobante fiscal aprobado es "ARCA".');
        $this->assertSame($notaFirme->id, (int) $notasArca->first()->id);
        $this->assertCount(1, $notasManuales, 'La nota sin comprobante fiscal cae en "Manuales".');
    }

    /** La partición sigue siendo exhaustiva y sin solapamiento cuando hay notas en juego. */
    public function test_particion_con_notas_es_exhaustiva_y_sin_solapamiento(): void
    {
        $venta = $this->ventaManual();
        NotaCreditoDebito::create([
            'venta_id' => $venta->id, 'tipo' => 'credito', 'afecta_stock' => false,
            'mes_imputacion' => '2026-03-01', 'fecha_emision' => '2026-03-15',
            'monto' => 100, 'tipo_comprobante' => 'B', 'descripcion' => 'Nota',
        ]);

        $ambas = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => true, 'manuales' => true]))->get();
        $soloArca = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => true, 'manuales' => false]))->get();
        $soloManuales = app(LibroIvaVentasQuery::class)->detalle($this->request(['arca' => false, 'manuales' => true]))->get();

        $this->assertCount(2, $ambas, 'La venta manual y su nota.');
        $this->assertSame(
            $ambas->count(),
            $soloArca->count() + $soloManuales->count(),
            'Cada fila cae en exactamente una de las dos vistas, nunca en las dos ni en ninguna.'
        );
    }

    /** FR-014a: IVA Compras ignora `arca`/`manuales` y siempre trae todo. */
    public function test_iva_compras_ignora_arca_manuales(): void
    {
        Compra::factory()->create(['proveedor_id' => Proveedor::factory(), 'fecha_emision' => '2026-08-10']);

        $request = Request::create('/informes/contador/compras/data', 'POST', [
            'mes' => 8, 'anio' => 2026, 'arca' => false, 'manuales' => false,
        ]);

        $filas = app(LibroIvaComprasQuery::class)->detalle($request)->get();

        $this->assertCount(1, $filas, 'Los parámetros arca/manuales no existen para Compras: siempre trae todo.');
    }
}
