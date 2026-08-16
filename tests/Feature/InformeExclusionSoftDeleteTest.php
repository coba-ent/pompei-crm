<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CondicionIva;
use App\Models\CuentaTesoreria;
use App\Models\Gasto;
use App\Models\OtroIngreso;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SC-006: los informes excluyen documentos soft-deleted/anulados de forma
 * transversal (D-008). Ventas/Compras ya lo cubren InformeVentasTest y
 * InformeComprasTest; este archivo cierra la cobertura sobre Gastos, Otros
 * Ingresos (Reporte Final) e IVA Compras (T046).
 */
class InformeExclusionSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Estas rutas están detrás del middleware `permiso:`, y el usuario que autentica
        // `Tests\TestCase` no trae ningún rol. Es el mismo `setUp` que ya usan
        // CompraVencidoTest y otros 140 archivos. No se centraliza en TestCase: varios tests
        // cuentan administradores o prueban la denegación, y adjuntarlo a todos rompe el
        // pivote `rol_usuario` y convierte esos asserts en falsos verdes. `syncWithoutDetaching`
        // y no `attach` porque algunos tests de estos mismos archivos ya lo adjuntan aparte.
        auth()->user()->roles()->syncWithoutDetaching(Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id);
    }

    public function test_informe_de_gastos_excluye_gastos_soft_deleted(): void
    {
        $categoria = Categoria::factory()->create();
        $gasto = Gasto::factory()->create(['categoria_id' => $categoria->id, 'fecha' => '2026-06-05', 'importe' => 500]);
        $gasto->delete();

        $resp = $this->getJson(route('informes.gastos.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(0, $resp['data']);
        $this->assertEquals(0.0, $resp['total_general']);
    }

    public function test_reporte_final_excluye_gastos_y_otros_ingresos_soft_deleted(): void
    {
        $cuenta = CuentaTesoreria::factory()->create(['nombre' => 'Caja Soft Delete']);
        $categoria = Categoria::factory()->create();

        $gasto = Gasto::factory()->create([
            'cuenta_tesoreria_id' => $cuenta->id, 'fecha' => '2026-06-05', 'importe' => 300, 'estado' => 'pagado',
        ]);
        $gasto->delete();

        $otroIngreso = OtroIngreso::create([
            'categoria_id' => $categoria->id, 'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2026-06-06', 'monto' => 900, 'estado' => 'registrado',
        ]);
        $otroIngreso->delete();

        $resp = $this->getJson(route('informes.reporte-final.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertNull(collect($resp['cuentas'])->firstWhere('cuenta', 'Caja Soft Delete'));
    }

    public function test_reporte_final_excluye_otros_ingresos_anulados(): void
    {
        $cuenta = CuentaTesoreria::factory()->create(['nombre' => 'Caja Anulado']);
        $categoria = Categoria::factory()->create();

        OtroIngreso::create([
            'categoria_id' => $categoria->id, 'cuenta_tesoreria_id' => $cuenta->id,
            'fecha' => '2026-06-06', 'monto' => 500, 'estado' => 'anulado',
        ]);

        $resp = $this->getJson(route('informes.reporte-final.data', [
            'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertNull(collect($resp['cuentas'])->firstWhere('cuenta', 'Caja Anulado'));
    }

    public function test_iva_compras_excluye_compras_soft_deleted(): void
    {
        $condicionIva = CondicionIva::create(['nombre' => 'Responsable Inscripto']);
        $proveedor = Proveedor::factory()->create(['condicion_iva_id' => $condicionIva->id]);
        $compra = Compra::factory()->create([
            'proveedor_id' => $proveedor->id, 'fecha_emision' => '2026-06-05', 'subtotal' => 1000, 'total' => 1210,
        ]);
        $compra->delete();

        $resp = $this->getJson(route('informes.contador.data', [
            'tipo' => 'compras', 'fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-30',
        ]))->assertOk()->json();

        $this->assertCount(0, $resp['lineas']);
    }
}
