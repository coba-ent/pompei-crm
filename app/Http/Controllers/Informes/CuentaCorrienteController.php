<?php

namespace App\Http\Controllers\Informes;

use App\Exports\Informes\CuentaCorrienteExport;
use App\Exports\Informes\MovimientosClientesExport;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\Informes\MovimientosClientesQuery;
use App\Services\Tesoreria\CuentaCorriente;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
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
        return DataTables::collection($this->saldos($request))
            ->toJson();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function saldos(Request $request): Collection
    {
        $porCliente = app(CuentaCorriente::class)->porCliente('cliente');

        $clientes = array_filter(array_map('intval', (array) $request->input('cliente_id', [])));
        if ($clientes !== []) {
            $porCliente = $porCliente->whereIn('cliente_id', $clientes)->values();
        }

        return $porCliente;
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
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'ventas.categoria_id')
            ->whereNull('ventas.deleted_at')
            ->selectRaw(
                "ventas.id as id, ventas.fecha_emision as fecha_emision, ventas.cliente_id as cliente_id, ".
                'clientes.nombre as cliente, '.
                "'venta' as operacion, categorias.nombre as categoria, ventas.total as total_venta, ".
                'COALESCE((SELECT SUM(c.monto) FROM cobros c WHERE c.venta_id = ventas.id AND c.deleted_at IS NULL), 0) as cobrado, '.
                '(ventas.total '.
                "+ COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.venta_id = ventas.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0) ".
                "- COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.venta_id = ventas.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0) ".
                '- COALESCE((SELECT SUM(c.monto) FROM cobros c WHERE c.venta_id = ventas.id AND c.deleted_at IS NULL), 0) '.
                \App\Services\Ingresos\SqlCredito::terminos('ventas').' '.
                ') as a_cobrar, '.
                'ventas.nro_comprobante as nro_comprobante, '.
                'NULL as medio_cobro, '.
                'NULL as descripcion'
            );

        $cobros = DB::table('cobros')
            ->join('ventas', 'ventas.id', '=', 'cobros.venta_id')
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->leftJoin('cuentas_tesoreria', 'cuentas_tesoreria.id', '=', 'cobros.cuenta_tesoreria_id')
            ->whereNull('cobros.deleted_at')
            ->selectRaw(
                "cobros.id as id, cobros.fecha as fecha_emision, ventas.cliente_id as cliente_id, ".
                'clientes.nombre as cliente, '.
                // El monto va en `cobrado`: una fila de cobro sin importe no dice nada. Estaba en
                // NULL y la cuenta corriente mostraba el cobro en blanco, así que no se podía
                // seguir el movimiento de la plata. `nro_comprobante` trae el de la venta cobrada,
                // para poder relacionar el cobro con su factura de un vistazo.
                "'cobro' as operacion, NULL as categoria, NULL as total_venta, ".
                'cobros.monto as cobrado, NULL as a_cobrar, '.
                'ventas.nro_comprobante as nro_comprobante, '.
                'cuentas_tesoreria.nombre as medio_cobro, cobros.nota as descripcion'
            );

        // Las notas salían con toda la fila en blanco salvo la fecha. Contagram muestra la
        // categoría de la venta afectada, el importe de la nota en "Cobrado" y el Nº de
        // comprobante propio de la nota (no el de la venta: mostrar el de la venta haría
        // pasar el comprobante de la factura por el de la nota). Cuando la nota se emitió por
        // ARCA el número no vive en `nro_comprobante` sino en su comprobante fiscal aprobado
        // (mismo caso que Compras, commit 723b7a24), así que se lee de ahí como fallback.
        $notas = DB::table('notas_credito_debito')
            ->join('ventas', 'ventas.id', '=', 'notas_credito_debito.venta_id')
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'ventas.categoria_id')
            ->whereNull('notas_credito_debito.deleted_at')
            ->whereNotNull('notas_credito_debito.venta_id')
            ->selectRaw(
                'notas_credito_debito.id as id, notas_credito_debito.fecha_emision as fecha_emision, '.
                "ventas.cliente_id as cliente_id, clientes.nombre as cliente, CASE notas_credito_debito.tipo WHEN 'credito' THEN 'nota_credito' ELSE 'nota_debito' END as operacion, ".
                'categorias.nombre as categoria, NULL as total_venta, notas_credito_debito.monto as cobrado, '.
                'NULL as a_cobrar, '.
                "COALESCE(NULLIF(notas_credito_debito.nro_comprobante, ''), (".
                '    SELECT cf.numero FROM comprobantes_fiscales cf '.
                '    WHERE cf.comprobantable_type = ? AND cf.comprobantable_id = notas_credito_debito.id '.
                "      AND cf.estado = 'aprobado' AND cf.deleted_at IS NULL ".
                '    ORDER BY cf.id DESC LIMIT 1'.
                ')) as nro_comprobante, '.
                'NULL as medio_cobro, notas_credito_debito.descripcion as descripcion',
                [\App\Models\NotaCreditoDebito::class]
            );

        $saldosIniciales = DB::table('clientes')
            ->where('clientes.saldo_inicial', '!=', 0)
            ->selectRaw(
                'clientes.id as id, clientes.saldo_inicial_fecha as fecha_emision, clientes.id as cliente_id, '.
                'clientes.nombre as cliente, '.
                "'saldo_inicial' as operacion, NULL as categoria, NULL as total_venta, NULL as cobrado, ".
                'clientes.saldo_inicial as a_cobrar, NULL as nro_comprobante, NULL as medio_cobro, NULL as descripcion'
            );

        $union = $ventas->unionAll($cobros)->unionAll($notas)->unionAll($saldosIniciales);

        return DB::query()->fromSub($union, 'mov');
    }

    /** Aplica los filtros externos de pantalla (nunca dentro de la UNION). */
    private function aplicarFiltrosMovimientos(\Illuminate\Database\Query\Builder $query, Request $request): void
    {
        // Cliente y Operación son multi-select (llegan como array), pero se sigue
        // aceptando un valor suelto por el deep-link `?cliente_id=N`.
        $clientes = array_filter(array_map('intval', (array) $request->input('cliente_id', [])));
        if ($clientes !== []) {
            $query->whereIn('mov.cliente_id', $clientes);
        }

        $operaciones = array_values(array_intersect(
            array_filter((array) $request->input('operacion', [])),
            self::OPERACIONES_DISPONIBLES
        ));
        if ($operaciones !== []) {
            $query->whereIn('mov.operacion', $operaciones);
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('mov.fecha_emision', '>=', $request->input('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('mov.fecha_emision', '<=', $request->input('fecha_hasta'));
        }
    }

    /**
     * Exportar / PDF de la pestaña "Saldos Clientes" — sólo la tabla de saldos, igual que
     * Contagram (el detalle de Movimientos no se incluye en este botón).
     */
    public function exportar(Request $request)
    {
        return Excel::download(
            new CuentaCorrienteExport($this->saldos($request)),
            'Informe Cuentas Corrientes Saldos de Clientes '.now()->format('d-m-Y His').' Hs.xlsx'
        );
    }

    public function pdf(Request $request)
    {
        return Pdf::loadView('informes.pdf.cuenta-corriente', [
            'saldos' => $this->saldos($request),
        ])->stream('Informe Cuentas Corrientes Saldos de Clientes '.now()->format('d-m-Y').'.pdf');
    }

    /** Ver {@see \App\Http\Controllers\Informes\InformeComprasController::TOPE_FILAS_PDF} (FR-011, spec 080). */
    public const TOPE_FILAS_PDF = 500;

    /**
     * Exportar / PDF de la pestaña "Movimientos" (spec 080, US1/US2) — respeta los mismos filtros
     * (`cliente_id[]`, `operacion[]`, `fecha_desde`/`fecha_hasta`) que `movimientosData()`, y
     * reutiliza el motor fiscal del Libro IVA del Contador (spec 077) vía {@see MovimientosClientesQuery}.
     */
    public function exportarMovimientos(Request $request)
    {
        return Excel::download(
            new MovimientosClientesExport(app(MovimientosClientesQuery::class)->obtener($request)),
            'Informe Cuentas Corrientes Movimientos de Clientes '.now()->format('d-m-Y His').' Hs.xlsx'
        );
    }

    public function pdfMovimientos(Request $request)
    {
        $movimientos = app(MovimientosClientesQuery::class)->obtener($request);

        return Pdf::loadView('informes.pdf.movimientos-cuenta-corriente', [
            'movimientos' => $movimientos,
            'topeFilas' => self::TOPE_FILAS_PDF,
        ])->setPaper('a4', 'landscape')->stream('Informe Cuentas Corrientes Movimientos de Clientes '.now()->format('d-m-Y').'.pdf');
    }
}
