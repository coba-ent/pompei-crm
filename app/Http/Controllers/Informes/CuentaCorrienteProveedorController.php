<?php

namespace App\Http\Controllers\Informes;

use App\Exports\Informes\CuentaCorrienteProveedorExport;
use App\Exports\Informes\MovimientosProveedoresExport;
use App\Http\Controllers\Controller;
use App\Models\DatosEmpresa;
use App\Models\Proveedor;
use App\Services\Informes\MovimientosProveedoresQuery;
use App\Services\Tesoreria\CuentaCorriente;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\Facades\DataTables;

/**
 * Informe de Cuenta Corriente de **Proveedores** (spec 067, US3) — espejo estructural del de
 * Clientes (spec 029): tabs "Saldos Proveedores" y "Movimientos", ficha de proveedor en modal
 * y deep-link desde el listado de Proveedores.
 *
 * Cierra la brecha que `documentacion_principal_crm.md` §4.3 y §6.4 declaraban abierta.
 *
 * **Sólo lectura**: no hay ni un verbo de escritura en este controlador (FR-037). El aging sale
 * de `CuentaCorriente::porCliente('proveedor')`, que ya soportaba el caso: el servicio **no se
 * modifica**, porque lo comparten el Dashboard y el informe de clientes, ambos en producción
 * (research R7).
 */
class CuentaCorrienteProveedorController extends Controller
{
    /** Operaciones que expone el filtro "Operación" del tab Movimientos. */
    private const OPERACIONES_DISPONIBLES = ['compra', 'pago', 'nota_credito', 'nota_debito', 'saldo_inicial'];

    /**
     * Shell con los dos tabs. `?proveedor_id=` (deep-link desde "Cta Cte" en el menú de fila de
     * Proveedores) precarga el filtro y abre directo en Movimientos, igual que `?cliente_id=` en
     * el informe de clientes (FR-038).
     */
    public function index(Request $request)
    {
        $CurrentPage = 'informe-cuenta-corriente-proveedores';

        $proveedorId = $request->input('proveedor_id');
        $proveedorPreseleccionado = $proveedorId ? Proveedor::find($proveedorId, ['id', 'nombre']) : null;

        return view('informes.cuenta-corriente-proveedores.index', compact('CurrentPage', 'proveedorPreseleccionado'));
    }

    /** Tab "Saldos Proveedores": aging por proveedor, vía el servicio compartido sin tocarlo. */
    public function saldosData(Request $request): JsonResponse
    {
        return DataTables::collection($this->saldos($request))->toJson();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function saldos(Request $request)
    {
        $porProveedor = app(CuentaCorriente::class)->porCliente('proveedor')
            // Los proveedores de ajuste de conciliación suman en el total de Tesorería
            // (a propósito) pero no se listan: no son proveedores con los que se opere.
            ->reject(fn (array $f) => str_starts_with((string) ($f['proveedor_nombre'] ?? ''), 'AJUSTE CONCILIACION CONTAGRAM'))
            ->values();

        if ($request->filled('proveedor_id')) {
            $porProveedor = $porProveedor->where('proveedor_id', (int) $request->input('proveedor_id'))->values();
        }

        return $porProveedor;
    }

    public function movimientosData(Request $request): JsonResponse
    {
        $query = $this->queryMovimientos();
        $this->aplicarFiltrosMovimientos($query, $request);

        return DataTables::of($query)
            // Enlace de la columna Id a la compra del movimiento, como en Contagram. Un saldo
            // inicial no es un documento: no enlaza a ninguna parte.
            ->addColumn('compra_url', fn ($fila) => $fila->compra_id ? route('compras.show', $fila->compra_id) : null)
            ->order(fn ($query) => $query->orderBy('mov.fecha_emision', 'desc')->orderBy('mov.id', 'desc'))
            ->toJson();
    }

    /**
     * UNION de Compra + Pago + NC/ND + saldo inicial sintético, con `NULL` en las columnas que
     * no aplican a cada tipo de fila (data-model.md §6.2). Espejo de
     * {@see CuentaCorrienteController::queryMovimientos()} del lado de clientes.
     */
    private function queryMovimientos(): Builder
    {
        $compras = DB::table('compras')
            ->leftJoin('categorias', 'categorias.id', '=', 'compras.categoria_id')
            ->whereNull('compras.deleted_at')
            ->selectRaw(
                'compras.id as id, compras.id as compra_id, compras.fecha_emision as fecha_emision, compras.proveedor_id as proveedor_id, '.
                "'compra' as operacion, categorias.nombre as categoria, compras.total as total_compra, ".
                'COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0) as pagado, '.
                '(compras.total '.
                "+ COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0) ".
                "- COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0) ".
                '- COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0) '.
                \App\Services\Ingresos\SqlCredito::terminos('compras').' '.
                ') as a_pagar, '.
                'compras.nro_comprobante as nro_comprobante, '.
                'NULL as medio_pago, '.
                'NULL as descripcion'
            );

        $pagos = DB::table('pagos')
            ->join('compras', 'compras.id', '=', 'pagos.compra_id')
            ->leftJoin('cuentas_tesoreria', 'cuentas_tesoreria.id', '=', 'pagos.cuenta_tesoreria_id')
            ->whereNull('pagos.deleted_at')
            ->whereNull('compras.deleted_at')
            ->selectRaw(
                'pagos.id as id, pagos.compra_id as compra_id, pagos.fecha as fecha_emision, compras.proveedor_id as proveedor_id, '.
                // El importe va en `pagado`, y `nro_comprobante` trae el de la compra cancelada:
                // sin eso la fila del pago sale en blanco y no se puede seguir la plata (mismo
                // arreglo que ya se le hizo al informe de clientes).
                "'pago' as operacion, NULL as categoria, NULL as total_compra, ".
                'pagos.monto as pagado, NULL as a_pagar, '.
                'compras.nro_comprobante as nro_comprobante, '.
                'cuentas_tesoreria.nombre as medio_pago, pagos.nota as descripcion'
            );

        $notas = DB::table('notas_credito_debito')
            ->join('compras', 'compras.id', '=', 'notas_credito_debito.compra_id')
            ->whereNull('notas_credito_debito.deleted_at')
            ->whereNull('compras.deleted_at')
            ->whereNotNull('notas_credito_debito.compra_id')
            ->selectRaw(
                'notas_credito_debito.id as id, notas_credito_debito.compra_id as compra_id, notas_credito_debito.fecha_emision as fecha_emision, '.
                "compras.proveedor_id as proveedor_id, CASE notas_credito_debito.tipo WHEN 'credito' THEN 'nota_credito' ELSE 'nota_debito' END as operacion, ".
                'NULL as categoria, NULL as total_compra, NULL as pagado, '.
                // La NC resta y la ND suma **con una sola expresión**, sin ramas por tipo (FR-016).
                "(CASE notas_credito_debito.tipo WHEN 'credito' THEN -1 ELSE 1 END) * notas_credito_debito.monto as a_pagar, ".
                'notas_credito_debito.nro_comprobante as nro_comprobante, '.
                'NULL as medio_pago, notas_credito_debito.descripcion as descripcion'
            );

        $saldosIniciales = DB::table('proveedores')
            ->where('proveedores.saldo_inicial', '!=', 0)
            // Los proveedores de ajuste de conciliación no se listan (suman en el total
            // de Tesorería, pero no son movimientos que el negocio deba ver).
            ->where('proveedores.nombre', 'not like', Proveedor::PREFIJO_AJUSTE)
            ->selectRaw(
                'proveedores.id as id, NULL as compra_id, proveedores.saldo_inicial_fecha as fecha_emision, proveedores.id as proveedor_id, '.
                "'saldo_inicial' as operacion, NULL as categoria, NULL as total_compra, NULL as pagado, ".
                'proveedores.saldo_inicial as a_pagar, NULL as nro_comprobante, NULL as medio_pago, NULL as descripcion'
            );

        $union = $compras->unionAll($pagos)->unionAll($notas)->unionAll($saldosIniciales);

        return DB::query()->fromSub($union, 'mov');
    }

    /** Filtros de pantalla, siempre por fuera de la UNION. */
    private function aplicarFiltrosMovimientos(Builder $query, Request $request): void
    {
        if ($request->filled('proveedor_id')) {
            $query->where('mov.proveedor_id', $request->input('proveedor_id'));
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

    /**
     * Ficha del proveedor para el modal — **sólo lectura**. No se reutiliza el modal de edición
     * de Proveedores a propósito (research R9): un informe no puede ser una puerta lateral para
     * modificar el maestro.
     */
    public function showProveedor(Proveedor $proveedor): JsonResponse
    {
        $proveedor->loadMissing('condicionIva');

        return response()->json([
            'proveedor' => $proveedor->nombre,
            'nombre' => $proveedor->nombre_pila,
            'apellido' => $proveedor->apellido,
            'email' => $proveedor->email,
            'telefono' => $proveedor->telefono,
            'celular' => $proveedor->telefono_celular,
            'pagina_web' => $proveedor->pagina_web,
            'domicilio' => $proveedor->domicilio,
            'localidad' => $proveedor->localidad,
            'provincia' => $proveedor->provincia,
            'cp' => $proveedor->cp,
            'condicion_iva' => optional($proveedor->condicionIva)->nombre,
            'comprobante_defecto' => $proveedor->tipo_comprobante_defecto,
            'nota' => $proveedor->nota,
        ]);
    }

    public function exportar(Request $request)
    {
        return Excel::download(
            new CuentaCorrienteProveedorExport($this->saldos($request), $this->movimientosFiltrados($request)),
            'Informe de Cuenta Corriente Proveedores '.now()->format('d-m-Y Hi').' Hs.xlsx'
        );
    }

    public function pdf(Request $request)
    {
        return Pdf::loadView('informes.pdf.cuenta-corriente-proveedores', [
            'empresa' => DatosEmpresa::instancia(),
            'saldos' => $this->saldos($request),
            'movimientos' => $this->movimientosFiltrados($request)
                ->orderBy('mov.fecha_emision')
                ->limit(self::TOPE_FILAS_PDF + 1)
                ->get(),
            'topeFilas' => self::TOPE_FILAS_PDF,
            'filtros' => $request->only(['proveedor_id', 'operacion', 'fecha_desde', 'fecha_hasta']),
        ])->setPaper('a4', 'landscape')->stream('informe-cuenta-corriente-proveedores.pdf');
    }

    /** Ver {@see InformeComprasController::TOPE_FILAS_PDF}. */
    public const TOPE_FILAS_PDF = 500;

    private function movimientosFiltrados(Request $request): Builder
    {
        $query = $this->queryMovimientos();
        $this->aplicarFiltrosMovimientos($query, $request);

        return $query;
    }

    /**
     * Exportar / PDF de la pestaña "Movimientos" (spec 080, US1/US2) — endpoints nuevos y
     * separados de {@see self::exportar()}/{@see self::pdf()} (que siguen siendo el botón de la
     * pestaña Saldos, sin tocar). Reutiliza el motor fiscal del Libro IVA del Contador (spec 077)
     * vía {@see MovimientosProveedoresQuery}.
     */
    public function exportarMovimientos(Request $request)
    {
        return Excel::download(
            new MovimientosProveedoresExport(app(MovimientosProveedoresQuery::class)->obtener($request)),
            'Informe Cuentas Corrientes Movimientos de Proveedores '.now()->format('d-m-Y His').' Hs.xlsx'
        );
    }

    public function pdfMovimientos(Request $request)
    {
        $movimientos = app(MovimientosProveedoresQuery::class)->obtener($request);

        return Pdf::loadView('informes.pdf.movimientos-cuenta-corriente-proveedores', [
            'movimientos' => $movimientos,
            'topeFilas' => self::TOPE_FILAS_PDF,
        ])->setPaper('a4', 'landscape')->stream('Informe Cuentas Corrientes Movimientos de Proveedores '.now()->format('d-m-Y').'.pdf');
    }
}
