<?php

namespace App\Http\Controllers\Informes;

use App\Exports\Informes\InformeGastosExport;
use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\DatosEmpresa;
use App\Models\User;
use App\Services\Informes\GastosInformeQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\Facades\DataTables;

/**
 * Informe de Gastos (spec 067, US2): en qué se está yendo la plata, agrupado
 * Categoría → Subcategoría. Sólo lectura.
 */
class InformeGastosController extends Controller
{
    /** Ver {@see InformeComprasController::TOPE_FILAS_PDF}. */
    public const TOPE_FILAS_PDF = 800;

    public function __construct(private GastosInformeQuery $informe) {}

    public function index()
    {
        $CurrentPage = 'informe-gastos';

        $categorias = Categoria::gasto()->activas()->orderBy('nombre')->get(['id', 'nombre', 'categoria_padre_id']);

        return view('informes.gastos.index', [
            'CurrentPage' => $CurrentPage,
            'categoriasRaiz' => $categorias->whereNull('categoria_padre_id')->values(),
            'subcategorias' => $categorias->whereNotNull('categoria_padre_id')->values(),
            'cuentas' => CuentaTesoreria::orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return DataTables::query($this->informe->detalle($request))
            // El orden es parte del contrato, no una preferencia: RowGroup agrupa filas
            // **contiguas**, así que si el detalle no llega ordenado por Categoría →
            // Subcategoría → Fecha, la misma categoría aparecería partida en varios grupos.
            ->order(fn ($query) => $query
                ->orderBy('categoria')
                ->orderBy('subcategoria')
                ->orderBy('fecha')
                ->orderBy('id'))
            ->toJson();
    }

    public function stats(Request $request): JsonResponse
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return response()->json($this->informe->subtotales($request));
    }

    /**
     * Filas de una subcategoría concreta, para el despliegue en pantalla.
     *
     * El árbol se dibuja colapsado a partir de `stats`: sin esto habría que traer el detalle
     * completo del período para mostrar los primeros totales.
     */
    public function grupo(Request $request): JsonResponse
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        $datos = $request->validate([
            'categoria' => ['required', 'string'],
            'subcategoria' => ['required', 'string'],
        ]);

        return response()->json([
            'filas' => $this->informe->filasDeGrupo($request, $datos['categoria'], $datos['subcategoria']),
        ]);
    }

    public function exportar(Request $request)
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return Excel::download(
            new InformeGastosExport($this->informe, $request),
            'Informe de Gastos '.now()->format('d-m-Y Hi').' Hs.xlsx'
        );
    }

    public function pdf(Request $request)
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return Pdf::loadView('informes.pdf.gastos', [
            'empresa' => DatosEmpresa::instancia(),
            'stats' => $this->informe->subtotales($request),
            'filas' => $this->informe->detalle($request)
                ->orderBy('categoria')->orderBy('subcategoria')->orderBy('fecha')
                ->limit(self::TOPE_FILAS_PDF + 1)
                ->get(),
            'topeFilas' => self::TOPE_FILAS_PDF,
        ])->setPaper('a4')->stream('informe-gastos.pdf');
    }

    private function rangoInvalido(Request $request): ?JsonResponse
    {
        $rango = $this->informe->rango($request);

        if ($rango['desde'] > $rango['hasta']) {
            return response()->json(['message' => 'La fecha "Desde" no puede ser posterior a la fecha "Hasta".'], 422);
        }

        return null;
    }
}
