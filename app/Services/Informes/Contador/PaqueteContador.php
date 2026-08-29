<?php

namespace App\Services\Informes\Contador;

use App\Exports\Informes\LibroIvaExport;
use App\Models\Venta;
use App\Services\Informes\IvaDigital\IvaDigitalPaquete;
use App\Services\Informes\LibroIvaComprasQuery;
use App\Services\Informes\LibroIvaQuery;
use App\Services\Informes\LibroIvaVentasQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Única fuente de verdad de "qué archivos corresponden" a un período y unas opciones dadas (spec
 * 087, plan §Arquitectura, research Decisión 3). `listar()` alimenta el panel en vivo del modal sin
 * generar nada; `generar()` produce los mismos archivos, con los mismos nombres, al enviar. Ambos
 * DEBEN coincidir siempre (SC-004) — por eso las reglas de qué corresponde viven acá una sola vez,
 * no repetidas en el JS del panel y en el envío.
 *
 * No recalcula ningún número (FR-026): delega en `LibroIvaExport`/`LibroIvaVentasQuery`/
 * `LibroIvaComprasQuery` (spec 077) y en `IvaDigitalPaquete` (spec 086), sin tocarlos.
 */
class PaqueteContador
{
    public function __construct(
        private LibroIvaVentasQuery $ventasQuery,
        private LibroIvaComprasQuery $comprasQuery,
        private IvaDigitalPaquete $ivaDigitalPaquete,
        private PdfsFacturasVentaPaquete $pdfsPaquete,
    ) {}

    /**
     * Nombres de archivo previstos, sin generar nada (FR-009 a FR-012a). `null` cuando no hay
     * período elegido — panel vacío (FR-009).
     *
     * @return array<int, string>
     */
    public function listar(?Periodo $periodo, OpcionesEnvio $opciones): array
    {
        if ($periodo === null) {
            return [];
        }

        $nombres = [$periodo->nombreIvaVentas(), $periodo->nombreIvaCompras()];

        if ($periodo->esMensual()) {
            $nombres[] = $periodo->nombreIvaDigital();

            if ($opciones->incluyePdfs) {
                $nombres[] = $periodo->nombrePdfsFacturas();
            }
        }

        return $nombres;
    }

    /**
     * Los archivos reales, con los mismos nombres que `listar()` (SC-004) — test de coherencia en
     * T011. Devuelve `[nombre => ruta temporal]`; el llamador borra los temporales tras adjuntar.
     *
     * `$alEmpezarEtapa` es opcional y sólo **anuncia** en qué paso va (para la barra de progreso del
     * envío): no participa de ningún cálculo ni cambia qué archivos se generan, así que los importes
     * verificados contra Contagram quedan intactos. Sin callback, el método se comporta igual que antes.
     *
     * @param  callable(string): void|null  $alEmpezarEtapa
     * @return array<string, string>
     */
    public function generar(Periodo $periodo, OpcionesEnvio $opciones, ?callable $alEmpezarEtapa = null): array
    {
        $etapa = $alEmpezarEtapa ?? static fn () => null;

        $requestVentas = $this->requestVentas($periodo, $opciones);
        $requestCompras = $this->requestCompras($periodo);

        $etapa('informes');

        $archivos = [
            $periodo->nombreIvaVentas() => $this->exportarXlsx($this->ventasQuery, $requestVentas, 'Libro IVA Ventas'),
            $periodo->nombreIvaCompras() => $this->exportarXlsx($this->comprasQuery, $requestCompras, 'Libro IVA Compras'),
        ];

        if ($periodo->esMensual()) {
            $archivos[$periodo->nombreIvaDigital()] = $this->ivaDigitalPaquete->generar($requestVentas, $periodo->mes, $periodo->anio);

            if ($opciones->incluyePdfs) {
                // Los PDFs son de lejos lo más lento (~0,2 s por factura, cientos por período), así
                // que merecen etapa propia: sin esto la barra se queda quieta varios minutos.
                $etapa('pdfs');
                $archivos[$periodo->nombrePdfsFacturas()] = $this->pdfsPaquete->generar($this->ventasDelPeriodo($requestVentas));
            }
        }

        return $archivos;
    }

    /** FR-025: el libro IVA Ventas (y por lo tanto el IVA Digital, que reusa la misma query) respeta las casillas. */
    private function requestVentas(Periodo $periodo, OpcionesEnvio $opciones): Request
    {
        return Request::create('/', 'POST', [
            'mes' => $periodo->mes,
            'anio' => $periodo->anio,
            'arca' => $opciones->incluyeElectronicas,
            'manuales' => $opciones->incluyeManuales,
        ]);
    }

    private function requestCompras(Periodo $periodo): Request
    {
        return Request::create('/', 'POST', ['mes' => $periodo->mes, 'anio' => $periodo->anio]);
    }

    private function exportarXlsx(LibroIvaQuery $query, Request $request, string $titulo): string
    {
        $bytes = Excel::raw(new LibroIvaExport($query, $request, $titulo), \Maatwebsite\Excel\Excel::XLSX);

        $ruta = tempnam(sys_get_temp_dir(), 'contador_xlsx_');
        file_put_contents($ruta, $bytes);

        return $ruta;
    }

    /** @return Collection<int, Venta> */
    private function ventasDelPeriodo(Request $requestVentas): Collection
    {
        $idsVenta = $this->ventasQuery->detalle($requestVentas)->get()
            ->filter(fn ($f) => ! str_starts_with((string) $f->tipo, 'NC') && ! str_starts_with((string) $f->tipo, 'ND'))
            ->pluck('id');

        return Venta::whereIn('id', $idsVenta)->get();
    }
}
