<?php

namespace App\Http\Controllers\Informes;

use App\Exports\Informes\LibroIvaExport;
use App\Http\Controllers\Controller;
use App\Models\CondicionIva;
use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\DatosEmpresa;
use App\Models\Provincia;
use App\Models\Venta;
use App\Services\Informes\IvaDigital\IvaDigitalPaquete;
use App\Services\Informes\LibroIvaComprasQuery;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Yajra\DataTables\Facades\DataTables;

/**
 * "Información para tu Contador" (spec 077): Libro IVA Ventas / Compras, de sólo lectura.
 *
 * Fino a propósito (research §D5): toda la lógica de dinero vive en {@see LibroIvaVentasQuery} y
 * {@see LibroIvaComprasQuery}, para que los tests de la constitución (principio IV) ejerciten el
 * cálculo sin pasar por HTTP.
 */
class InformeContadorController extends Controller
{
    private const MENSAJE_SIN_PERIODO = 'Elegí un mes y un año para generar el informe.';

    public function __construct(
        private LibroIvaVentasQuery $ventasQuery,
        private LibroIvaComprasQuery $comprasQuery,
        private IvaDigitalPaquete $ivaDigitalPaquete,
    ) {}

    public function index()
    {
        $CurrentPage = 'informe-contador';

        $anios = collect([
            Venta::min('fecha_emision'),
            Compra::min('fecha_emision'),
        ])->filter()->map(fn ($f) => (int) substr((string) $f, 0, 4));

        $anioMinimo = $anios->isNotEmpty() ? $anios->min() : (int) now()->format('Y');
        $anioActual = (int) now()->format('Y');

        $datosEmpresa = DatosEmpresa::instancia();

        return view('informes.contador.index', [
            'CurrentPage' => $CurrentPage,
            'tiposComprobante' => ['FEA', 'FEB', 'FEC', 'FA', 'FB', 'FC', 'NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC'],
            'condicionesIva' => class_exists(CondicionIva::class) ? CondicionIva::orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'cuentasTesoreria' => class_exists(CuentaTesoreria::class) ? CuentaTesoreria::orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'provincias' => class_exists(Provincia::class) ? Provincia::orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'anios' => range($anioMinimo, max($anioActual, $anioMinimo)),
            // spec 087: precarga del modal de envío al contador.
            'mailContador' => $datosEmpresa->mail_contador ?? '',
            'nombreNegocio' => $datosEmpresa->razon_social ?? '',
        ]);
    }

    // -----------------------------------------------------------------------------------
    // IVA Ventas
    // -----------------------------------------------------------------------------------

    public function ventasData(Request $request): JsonResponse
    {
        if ($this->ventasQuery->periodoInvalido($request)) {
            return $this->errorSinPeriodo();
        }

        return DataTables::query($this->ventasQuery->detalle($request))
            ->order(fn ($query) => $query->orderBy('emision', 'asc')->orderBy('id', 'asc'))
            ->toJson();
    }

    public function ventasStats(Request $request): JsonResponse
    {
        if ($this->ventasQuery->periodoInvalido($request)) {
            return $this->errorSinPeriodo();
        }

        return response()->json($this->ventasQuery->totales($request));
    }

    public function ventasExportar(Request $request)
    {
        if ($this->ventasQuery->periodoInvalido($request)) {
            return $this->errorSinPeriodo();
        }

        $nombre = sprintf('Libro IVA Ventas %02d-%04d.xlsx', (int) $request->input('mes'), (int) $request->input('anio'));

        return Excel::download(new LibroIvaExport($this->ventasQuery, $request, 'Libro IVA Ventas'), $nombre);
    }

    // -----------------------------------------------------------------------------------
    // IVA Compras
    // -----------------------------------------------------------------------------------

    public function comprasData(Request $request): JsonResponse
    {
        if ($this->comprasQuery->periodoInvalido($request)) {
            return $this->errorSinPeriodo();
        }

        return DataTables::query($this->comprasQuery->detalle($request))
            ->order(fn ($query) => $query->orderBy('emision', 'asc')->orderBy('id', 'asc'))
            ->toJson();
    }

    public function comprasStats(Request $request): JsonResponse
    {
        if ($this->comprasQuery->periodoInvalido($request)) {
            return $this->errorSinPeriodo();
        }

        return response()->json($this->comprasQuery->totales($request));
    }

    public function comprasExportar(Request $request)
    {
        if ($this->comprasQuery->periodoInvalido($request)) {
            return $this->errorSinPeriodo();
        }

        $nombre = sprintf('Libro IVA Compras %02d-%04d.xlsx', (int) $request->input('mes'), (int) $request->input('anio'));

        return Excel::download(new LibroIvaExport($this->comprasQuery, $request, 'Libro IVA Compras'), $nombre);
    }

    // -----------------------------------------------------------------------------------
    // IVA Digital (spec 086)
    // -----------------------------------------------------------------------------------

    /** FR-004: 422 si falta mes o año, delegando en la misma validación que ventas/compras. */
    public function ivaDigital(Request $request): JsonResponse|BinaryFileResponse
    {
        if ($this->ventasQuery->periodoInvalido($request)) {
            return $this->errorSinPeriodo();
        }

        $mes = (int) $request->input('mes');
        $anio = (int) $request->input('anio');

        $ruta = $this->ivaDigitalPaquete->generar($request, $mes, $anio);
        $nombre = $this->ivaDigitalPaquete->nombreZip($mes, $anio);

        return response()->download($ruta, $nombre)->deleteFileAfterSend(true);
    }

    private function errorSinPeriodo(): JsonResponse
    {
        return response()->json(['message' => self::MENSAJE_SIN_PERIODO], 422);
    }
}
