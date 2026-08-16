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
