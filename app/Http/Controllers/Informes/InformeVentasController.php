<?php

namespace App\Http\Controllers\Informes;

use App\Exports\Informes\InformeVentasExport;
use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\DatosEmpresa;
use App\Models\Etiqueta;
use App\Models\TipoProducto;
use App\Models\Transportista;
use App\Models\User;
use App\Models\Vendedor;
use App\Services\Informes\VentasInformeQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\Facades\DataTables;

/**
 * Informe de Ventas (spec 068, US1/US2): pantalla de **sólo lectura** con los 3 bloques de KPIs y
 * el detalle línea por línea de las ventas, notas de crédito y notas de débito del período.
 *
 * Divergencias deliberadas respecto de Contagram, ya decididas en la spec: no se construyen las
 * pestañas "Rankings" ni "Arma tu Informe" (la pantalla es única, sin barra de pestañas), y el
 * Excel sale con **dos hojas** en vez de una, siguiendo el estándar del módulo fijado en la
 * Tanda 1.
 */
class InformeVentasController extends Controller
{
    /**
     * Tope de filas del detalle en el PDF: un período grande con el detalle completo revienta la
     * memoria de dompdf. Pasado el tope el PDF corta y avisa que el listado íntegro está en el
     * Excel, que no tiene ese límite. Mismo criterio que el Informe de Compras.
     */
    public const TOPE_FILAS_PDF = 500;

    public function __construct(private VentasInformeQuery $informe) {}

    public function index()
    {
        return view('informes.ventas.index', [
            'CurrentPage' => 'informe-ventas',
            'categoriasVenta' => Categoria::venta()->activas()->orderBy('nombre')->get(['id', 'nombre']),
            'tiposProducto' => TipoProducto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'etiquetas' => Etiqueta::orderBy('nombre')->get(['id', 'nombre']),
            'vendedores' => Vendedor::orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
            'transportistas' => Transportista::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return DataTables::query($this->informe->detalle($request))
            // Orden por defecto: lo más reciente arriba, y dentro de la misma fecha por Id
            // descendente para que el orden sea estable entre páginas (FR-017).
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

    /** "Exportar Resumen": Excel de dos hojas con los mismos filtros que la pantalla. */
    public function exportar(Request $request)
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return Excel::download(
            new InformeVentasExport($this->informe, $request),
            'Informe de Ventas Resumen '.now()->format('d-m-Y Hi').' Hs.xlsx'
        );
    }

    /** "Exportar a PDF": `inline` para que lo renderice el `<iframe>` del modal compartido (regla #4). */
    public function pdf(Request $request)
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return Pdf::loadView('informes.pdf.ventas', [
            'empresa' => DatosEmpresa::instancia(),
            'rango' => $this->informe->rango($request),
            'kpis' => $this->informe->kpis($request),
            'filas' => $this->informe->detalle($request)
                ->orderBy('detalle.fecha', 'desc')
                ->orderBy('detalle.id', 'desc')
                ->limit(self::TOPE_FILAS_PDF + 1)
                ->get(),
            'topeFilas' => self::TOPE_FILAS_PDF,
        ])->setPaper('a4', 'landscape')->stream('informe-ventas.pdf');
    }

    /** Rango dado vuelta: 422 con mensaje, que el front muestra por Toastr (contrato §Parámetros). */
    private function rangoInvalido(Request $request): ?JsonResponse
    {
        $rango = $this->informe->rango($request);

        if ($rango['desde'] > $rango['hasta']) {
            return response()->json(['message' => 'La fecha "Desde" no puede ser posterior a la fecha "Hasta".'], 422);
        }

        return null;
    }
}
