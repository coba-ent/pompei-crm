<?php

namespace Tests\Feature\Creditos;

use App\Models\AplicacionCredito;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Models\NotaCreditoDebito;
use App\Models\Pago;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Services\Ingresos\CreditoCliente;
use App\Services\Tesoreria\CuentaCorriente;
use App\Services\Tesoreria\Tesoreria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **Test de invariante de Tesorería** (FR-017/018/019, SC-003, plan.md §Riesgo principal barrera 2).
 *
 * Mide los siete totales que el negocio ya tiene cuadrados, aplica y anula un crédito, y falla ante
 * cualquier diferencia. Es el test que tiene que romper el build si un cambio futuro hace que
 * aplicar saldo a favor termine tocando una caja.
 */
class TesoreriaIntactaTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplicar_y_anular_credito_no_mueve_ningun_total_de_tesoreria(): void
    {
        [$destinoVenta, $destinoCompra] = $this->escenarioConDineroReal();

        $antes = $this->totales();

        $aplicacionesVenta = app(CreditoCliente::class)->aplicar($destinoVenta, 400.0, now());
        $aplicacionesCompra = app(CreditoCliente::class)->aplicar($destinoCompra, 300.0, now());

        $this->assertSame($antes, $this->totales(), 'Aplicar crédito movió un total de Tesorería.');

        app(CreditoCliente::class)->anular($aplicacionesVenta->first());
        app(CreditoCliente::class)->anular($aplicacionesCompra->first());

        $this->assertSame($antes, $this->totales(), 'Anular una aplicación movió un total de Tesorería.');
    }

    public function test_aplicar_credito_no_crea_ningun_movimiento_de_tesoreria(): void
    {
        [$destinoVenta] = $this->escenarioConDineroReal();

        $movimientosAntes = MovimientoTesoreria::withTrashed()->count();

        app(CreditoCliente::class)->aplicar($destinoVenta, 400.0, now());

        $this->assertSame($movimientosAntes, MovimientoTesoreria::withTrashed()->count());
        // Ni un cobro encubierto: la aplicación vive en su propia tabla.
        $this->assertSame(1, AplicacionCredito::count());
    }

    /** El medio "Saldo a favor" no existe como cuenta de tesorería (FR-019). */
    public function test_no_se_crea_ninguna_cuenta_de_tesoreria_saldo_a_favor(): void
    {
        [$destinoVenta] = $this->escenarioConDineroReal();

        $cuentasAntes = CuentaTesoreria::count();

        app(CreditoCliente::class)->aplicar($destinoVenta, 400.0, now());

        $this->assertSame($cuentasAntes, CuentaTesoreria::count());
        $this->assertSame(0, CuentaTesoreria::where('nombre', 'like', '%aldo a favor%')->count());
    }

    /**
     * Escenario con plata de verdad ya movida: cobros, pagos y saldos de cuentas distintos de cero,
     * para que una diferencia se note. Devuelve el comprobante destino de Ventas y el de Compras.
     *
     * @return array{0: Venta, 1: Compra}
     */
    private function escenarioConDineroReal(): array
    {
        $cuenta = CuentaTesoreria::factory()->create(['saldo_inicial' => 0]);

        $cliente = Cliente::factory()->create();
        $origenVenta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 1000, 'fecha_emision' => '2026-08-10']);
        Cobro::factory()->create(['venta_id' => $origenVenta->id, 'monto' => 1000, 'cuenta_tesoreria_id' => $cuenta->id]);
        NotaCreditoDebito::factory()->create(['venta_id' => $origenVenta->id, 'tipo' => 'credito', 'monto' => 1000]);
        $destinoVenta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 700, 'fecha_emision' => '2026-08-20']);

        // Otra venta con cobro real, ajena al crédito: si el cambio tocara Tesorería en general,
        // sus saldos también se moverían.
        $otraVenta = Venta::factory()->create(['cliente_id' => $cliente->id, 'total' => 5000, 'fecha_emision' => '2026-08-15']);
        Cobro::factory()->create(['venta_id' => $otraVenta->id, 'monto' => 2500, 'cuenta_tesoreria_id' => $cuenta->id]);

        $proveedor = Proveedor::factory()->create();
        $origenCompra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 800, 'fecha_emision' => '2026-08-10']);
        Pago::factory()->create(['compra_id' => $origenCompra->id, 'monto' => 800, 'cuenta_tesoreria_id' => $cuenta->id]);
        NotaCreditoDebito::factory()->create([
            'venta_id' => null, 'compra_id' => $origenCompra->id, 'tipo' => 'credito', 'monto' => 800,
        ]);
        $destinoCompra = Compra::factory()->create(['proveedor_id' => $proveedor->id, 'total' => 600, 'fecha_emision' => '2026-08-20']);

        return [$destinoVenta, $destinoCompra];
    }

    /**
     * Los siete totales que no pueden moverse.
     *
     * **Por qué no se llama a `Tesoreria::saldos()` ni a `CuentaCorriente::aging()`**: los dos pasan
     * por `bucketsEnSql()`, que usa `DATEDIFF` — una función de MySQL que SQLite no tiene, así que
     * bajo la suite de tests revientan (limitación previa a esta feature, verificada el 21/08/2026
     * contra `main`). Se miden en cambio las mismas magnitudes por el camino que sí corre acá:
     * `porCliente()` clasifica en los mismos buckets con la misma `clasificarBucket()`, y los
     * bloques de A Cobrar / A Pagar / Disponible de la pantalla son la suma de los saldos de las
     * cuentas de cada tipo más el total de cuenta corriente. El escenario 2 de quickstart.md cubre
     * `saldos()` real contra MySQL.
     *
     * @return array<string, mixed>
     */
    private function totales(): array
    {
        $ctaCte = app(CuentaCorriente::class);
        $agingClientes = $ctaCte->porCliente('cliente')->toArray();
        $agingProveedores = $ctaCte->porCliente('proveedor')->toArray();

        $porTipo = fn (array $tipos) => round((float) CuentaTesoreria::visibles()
            ->whereIn('tipo', $tipos)->get()
            ->sum(fn (CuentaTesoreria $c) => $c->saldoA(null)), 2);

        return [
            'a_cobrar' => round($porTipo(['a_cobrar']) + collect($agingClientes)->sum('total'), 2),
            'a_pagar' => round($porTipo(['a_pagar']) + collect($agingProveedores)->sum('total'), 2),
            'disponible' => $porTipo(['efectivo', 'banco']),
            'aging_clientes' => $agingClientes,
            'aging_proveedores' => $agingProveedores,
            'movimientos_cantidad' => (int) DB::table('movimientos_tesoreria')->whereNull('deleted_at')->count(),
            'movimientos_suma' => round((float) DB::table('movimientos_tesoreria')->whereNull('deleted_at')->sum('monto'), 2),
            // Invariantes 5 del contrato: la plata registrada como cobrada/pagada no cambia.
            'cobros_suma' => round((float) DB::table('cobros')->whereNull('deleted_at')->sum('monto'), 2),
            'pagos_suma' => round((float) DB::table('pagos')->whereNull('deleted_at')->sum('monto'), 2),
        ];
    }
}
