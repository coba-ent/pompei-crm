<?php

namespace App\Http\Controllers\Informes;

use App\Exports\Informes\InformeComprasExport;
use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\DatosEmpresa;
use App\Models\Etiqueta;
use App\Models\TipoProducto;
use App\Models\User;
use App\Services\Informes\ComprasInformeQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\Facades\DataTables;

/**
 * Informe de Compras (spec 067, US1): pantalla de **sólo lectura** que responde "cuánto compré,
 * a quién y con qué composición impositiva" sin tener que abrir compra por compra.
 *
 * Divergencia deliberada respecto de Contagram: el desglose impositivo AFIP se puede ver **en
 * pantalla** con el selector de columnas; Contagram sólo lo vuelca al Excel.
 */
class InformeComprasController extends Controller
{
    public function __construct(private ComprasInformeQuery $informe) {}

    public function index()
    {
        $CurrentPage = 'informe-compras';

        return view('informes.compras.index', [
            'CurrentPage' => $CurrentPage,
            'categoriasCompra' => Categoria::compra()->activas()->orderBy('nombre')->get(['id', 'nombre']),
            'tiposProducto' => TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'etiquetas' => Etiqueta::orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return DataTables::query($this->informe->detalle($request))
            ->order(fn ($query) => $query->orderBy('detalle.fecha', 'desc')->orderBy('detalle.id', 'desc'))
            ->toJson();
    }

    public function stats(Request $request): JsonResponse
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return response()->json($this->informe->kpis($request));
    }

    /**
     * Dataset del motor de tablas dinámicas (spec 069).
     *
     * Aplica exactamente los mismos filtros que `data()` y `stats()`: el cruce se calcula sobre el
     * conjunto filtrado completo y no sobre una página del detalle (FR-017).
     */
    public function pivotDataset(Request $request, \App\Services\Informes\ComprasPivotDataset $dataset): JsonResponse
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        // Se corta ANTES de materializar: el dataset entero viaja al navegador.
        if ($dataset->excedeTope($request)) {
            return response()->json([
                'message' => 'El período tiene demasiados movimientos para armar el cruce (más de '
                    .number_format(\App\Services\Informes\PivotDataset::TOPE_FILAS, 0, ',', '.')
                    .'). Acotá el rango o los filtros.',
            ], 422);
        }

        return response()->json($dataset->armar($request));
    }

    /**
     * Excel del cruce visible (spec 069).
     *
     * Recibe la matriz **ya calculada por el cliente** y no la recalcula: lo exportado tiene que
     * ser exactamente lo que el usuario ve, con sus exclusiones y su orden de dimensiones (R3).
     */
    public function pivotExportar(Request $request)
    {
        // Los arrays van con `sometimes`, no con `present`: el botón manda un `<form>` POST, y un
        // form no tiene forma de expresar "array vacío" — cuando el cruce no tiene dimensión de
        // Filas, `filas` simplemente no viaja. Se completan con `[]` más abajo.
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:120'],
            'encabezados_fila' => ['sometimes', 'array'],
            'encabezados_columna' => ['sometimes', 'array'],
            'niveles_columna' => ['sometimes', 'array'],
            'niveles_columna.*.etiqueta' => ['present'],
            'niveles_columna.*.valores' => ['present', 'array'],
            'filas' => ['sometimes', 'array', 'max:50000'],
            'filas.*.etiqueta' => ['present', 'array'],
            'filas.*.valores' => ['present', 'array'],
            'totales_columna' => ['sometimes', 'array'],
            'total_general' => ['sometimes'],
        ]);

        $datos['encabezados_fila'] ??= [];
        $datos['encabezados_columna'] ??= [];
        $datos['niveles_columna'] ??= [];
        $datos['filas'] ??= [];
        $datos['totales_columna'] ??= [];
        $datos['total_general'] ??= null;

        if ($datos['filas'] === [] && $datos['totales_columna'] === []) {
            return response()->json(['message' => 'No hay nada para exportar.'], 422);
        }

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\Informes\PivotExport($datos),
            $datos['titulo'].' '.now()->format('d-m-Y Hi').' Hs.xlsx'
        );
    }
    /** Excel de dos hojas (formateada + plana), con los mismos filtros que la pantalla. */
    public function exportar(Request $request)
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return Excel::download(
            new InformeComprasExport($this->informe, $request),
            'Informe de Compras '.now()->format('d-m-Y Hi').' Hs.xlsx'
        );
    }

    /** PDF `inline` para que lo renderice el `<iframe>` del modal compartido (regla #4). */
    public function pdf(Request $request)
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        $rango = $this->informe->rango($request);

        return Pdf::loadView('informes.pdf.compras', [
            'empresa' => DatosEmpresa::instancia(),
            'rango' => $rango,
            'kpis' => $this->informe->kpis($request),
            'filas' => $this->informe->detalle($request)
                ->orderBy('detalle.fecha')
                ->limit(self::TOPE_FILAS_PDF + 1)
                ->get(),
            'topeFilas' => self::TOPE_FILAS_PDF,
        ])->setPaper('a4', 'landscape')->stream('informe-compras.pdf');
    }

    /**
     * Tope de filas de detalle del PDF. Un período grande con el detalle completo revienta la
     * memoria de dompdf; pasado el tope el PDF corta y avisa que el listado íntegro está en el
     * Excel, que no tiene ese límite (research R5).
     */
    public const TOPE_FILAS_PDF = 500;

    /** Rango dado vuelta: 422 con mensaje, que el front muestra por Toastr (contrato §5). */
    private function rangoInvalido(Request $request): ?JsonResponse
    {
        $rango = $this->informe->rango($request);

        if ($rango['desde'] > $rango['hasta']) {
            return response()->json(['message' => 'La fecha "Desde" no puede ser posterior a la fecha "Hasta".'], 422);
        }

        return null;
    }
}
