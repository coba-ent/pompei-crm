<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\Tesoreria\CuentaCorriente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/**
 * Informe de Cuenta Corriente (Clientes): pantalla de sólo lectura con dos
 * tabs — "Saldos Clientes" (aging por cliente, spec 029 FR-002) y
 * "Movimientos" (Venta/Cobro/Nota de Crédito-Débito combinados, FR-005).
 * Proveedores queda fuera de alcance (FR-010).
 */
class CuentaCorrienteController extends Controller
{
    /** Operaciones que expone el filtro "Operación" del tab Movimientos. */
    private const OPERACIONES_DISPONIBLES = ['venta', 'cobro', 'nota_credito', 'nota_debito', 'saldo_inicial'];

    /**
     * Shell de la pantalla. `?cliente_id=` (deep-link desde "Cta Cte" en el
     * menú de fila de Clientes) precarga el filtro Cliente y abre directo en
     * el tab "Movimientos" — el detalle accionable de ese cliente puntual.
     */
    public function index(Request $request)
    {
        $CurrentPage = 'informe-cuenta-corriente';

        $clienteId = $request->input('cliente_id');
        $clientePreseleccionado = $clienteId ? Cliente::find($clienteId, ['id', 'nombre']) : null;

        return view('informes.cuenta-corriente.index', compact('CurrentPage', 'clientePreseleccionado'));
    }

    /** Datos server-side para el tab "Saldos Clientes" (Collection en memoria, research.md R1). */
    public function saldosData(Request $request): JsonResponse
    {
        $porCliente = app(CuentaCorriente::class)->porCliente('cliente');

        if ($request->filled('cliente_id')) {
            $porCliente = $porCliente->where('cliente_id', (int) $request->input('cliente_id'))->values();
        }

        return DataTables::collection($porCliente)
            ->toJson();
    }

    /** Datos server-side para el tab "Movimientos" (UNION Venta/Cobro/Nota, research.md R2). */
    public function movimientosData(Request $request): JsonResponse
    {
        $query = $this->queryMovimientos();
        $this->aplicarFiltrosMovimientos($query, $request);

        return DataTables::of($query)
            ->order(function ($query) {
                $query->orderBy('mov.fecha_emision', 'desc')->orderBy('mov.id', 'desc');
            })
            ->toJson();
    }

    /**
     * UNION de Venta + Cobro + Nota de Crédito/Débito proyectando las columnas
     * de data-model.md ("Vista derivada: fila de Movimientos"), con `NULL` en
     * las columnas no aplicables por tipo de operación.
     */
    private function queryMovimientos(): \Illuminate\Database\Query\Builder
    {
        $ventas = DB::table('ventas')
            ->leftJoin('categorias', 'categorias.id', '=', 'ventas.categoria_id')
            ->whereNull('ventas.deleted_at')
            ->selectRaw(
                "ventas.id as id, ventas.fecha_emision as fecha_emision, ventas.cliente_id as cliente_id, ".
                "'venta' as operacion, categorias.nombre as categoria, ventas.total as total_venta, ".
                'COALESCE((SELECT SUM(c.monto) FROM cobros c WHERE c.venta_id = ventas.id AND c.deleted_at IS NULL), 0) as cobrado, '.
                '(ventas.total '.
                "+ COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.venta_id = ventas.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0) ".
                "- COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.venta_id = ventas.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0) ".
                '- COALESCE((SELECT SUM(c.monto) FROM cobros c WHERE c.venta_id = ventas.id AND c.deleted_at IS NULL), 0) '.
                ') as a_cobrar, '.
                'ventas.nro_comprobante as nro_comprobante, '.
                'NULL as medio_cobro, '.
                'NULL as descripcion'
            );

        $cobros = DB::table('cobros')
            ->join('ventas', 'ventas.id', '=', 'cobros.venta_id')
            ->leftJoin('cuentas_tesoreria', 'cuentas_tesoreria.id', '=', 'cobros.cuenta_tesoreria_id')
            ->whereNull('cobros.deleted_at')
            ->selectRaw(
                "cobros.id as id, cobros.fecha as fecha_emision, ventas.cliente_id as cliente_id, ".
                "'cobro' as operacion, NULL as categoria, NULL as total_venta, NULL as cobrado, NULL as a_cobrar, ".
                'NULL as nro_comprobante, cuentas_tesoreria.nombre as medio_cobro, cobros.nota as descripcion'
            );

        $notas = DB::table('notas_credito_debito')
            ->join('ventas', 'ventas.id', '=', 'notas_credito_debito.venta_id')
            ->whereNull('notas_credito_debito.deleted_at')
            ->whereNotNull('notas_credito_debito.venta_id')
            ->selectRaw(
                'notas_credito_debito.id as id, notas_credito_debito.fecha_emision as fecha_emision, '.
                "ventas.cliente_id as cliente_id, CASE notas_credito_debito.tipo WHEN 'credito' THEN 'nota_credito' ELSE 'nota_debito' END as operacion, ".
                'NULL as categoria, NULL as total_venta, NULL as cobrado, NULL as a_cobrar, NULL as nro_comprobante, '.
                'NULL as medio_cobro, notas_credito_debito.descripcion as descripcion'
            );

        $saldosIniciales = DB::table('clientes')
            ->where('clientes.saldo_inicial', '!=', 0)
            ->selectRaw(
                'clientes.id as id, clientes.saldo_inicial_fecha as fecha_emision, clientes.id as cliente_id, '.
                "'saldo_inicial' as operacion, NULL as categoria, NULL as total_venta, NULL as cobrado, ".
                'clientes.saldo_inicial as a_cobrar, NULL as nro_comprobante, NULL as medio_cobro, NULL as descripcion'
            );

        $union = $ventas->unionAll($cobros)->unionAll($notas)->unionAll($saldosIniciales);

        return DB::query()->fromSub($union, 'mov');
    }

    /** Aplica los filtros externos de pantalla (nunca dentro de la UNION). */
    private function aplicarFiltrosMovimientos(\Illuminate\Database\Query\Builder $query, Request $request): void
    {
        if ($request->filled('cliente_id')) {
            $query->where('mov.cliente_id', $request->input('cliente_id'));
        }

        if ($request->filled('operacion') && in_array($request->input('operacion'), self::OPERACIONES_DISPONIBLES, true)) {
            $query->where('mov.operacion', $request->input('operacion'));
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('mov.fecha_emision', '>=', $request->input('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('mov.fecha_emision', '<=', $request->input('fecha_hasta'));
        }
    }
}
