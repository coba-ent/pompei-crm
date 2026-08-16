<?php

namespace App\Http\Controllers\Informes;

use App\Exports\Informes\ReporteFinalExport;
use App\Http\Controllers\Controller;
use App\Models\DatosEmpresa;
use App\Services\Informes\ReporteFinalQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte Final (spec 068, US3): el resultado del período en dos lecturas —devengado y caja— con
 * el desglose por categoría, el nivel de Cuenta de Tesorería en la vista caja y el simulador
 * "Activo".
 *
 * A diferencia del resto de los listados del CRM, esta pantalla **no usa DataTables**: no es un
 * listado sino un árbol agregado de decenas de filas con subtotales y checkboxes que tienen que
 * recalcular en el cliente sin ir a la red (FR-034). Es la única desviación de la regla #1 de
 * CLAUDE.md y está registrada en `plan.md` §Complexity Tracking.
 */
class ReporteFinalController extends Controller
{
    public function __construct(private ReporteFinalQuery $informe) {}

    public function index()
    {
        return view('informes.reporte-final.index', ['CurrentPage' => 'reporte-final']);
    }

    /** Árbol completo de la vista pedida: el front lo tiene entero en memoria para simular. */
    public function data(Request $request): JsonResponse
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return response()->json($this->informe->arbol($request));
    }

    /** Excel de dos hojas, sobre el escenario simulado que el usuario está viendo (FR-006). */
    public function exportar(Request $request)
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        return Excel::download(
            new ReporteFinalExport($this->informe, $request),
            'Informe Final '.now()->format('d-m-Y Hi').' Hs.xlsx'
        );
    }

    /** PDF `inline` para el modal compartido, con los signos de **pantalla** (egresos en positivo). */
    public function pdf(Request $request)
    {
        if ($error = $this->rangoInvalido($request)) {
            return $error;
        }

        $arbol = $this->informe->arbol($request);
        $excluidas = $this->informe->excluidas($request);

        return Pdf::loadView('informes.pdf.reporte-final', [
            'empresa' => DatosEmpresa::instancia(),
            'arbol' => $arbol,
            'excluidas' => $excluidas,
            'titulo' => $arbol['vista'] === 'caja' ? 'Cobros Vs Pagos' : 'Ventas Vs. Compras',
            'subtotales' => $this->subtotales($arbol, $excluidas),
        ])->setPaper('a4', 'portrait')->stream('reporte-final.pdf');
    }

    /**
     * Subtotal por bloque ya descontadas las categorías destildadas.
     *
     * @return array<string, float>
     */
    private function subtotales(array $arbol, array $excluidas): array
    {
        $subtotales = [];

        foreach ($arbol['bloques'] as $bloque) {
            $subtotales[$bloque['clave']] = $this->informe->totalBloque($bloque, $excluidas);
        }

        return $subtotales;
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
