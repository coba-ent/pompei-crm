<?php

namespace App\Services\Informes\Contador;

use App\Models\DatosEmpresa;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * ZIP con los PDF de las facturas de venta del período (spec 087, FR-012/FR-012b). Reutiliza
 * exactamente la misma vista y el mismo armado que `VentaController::pdf()` — no un formato de PDF
 * nuevo (spec Assumptions) — sólo cambia `stream()` por `output()` para obtener los bytes en vez de
 * una respuesta HTTP, y los agrega al ZIP con `addFromString` en vez de escribir un handle.
 */
class PdfsFacturasVentaPaquete
{
    /**
     * @param  iterable<int, Venta>  $ventas  ya cargadas o no — se relacionan acá igual que en `VentaController::pdf()`.
     * @return string ruta al ZIP temporal generado; el llamador es responsable de borrarlo.
     */
    public function generar(iterable $ventas): string
    {
        $ventas = is_array($ventas) ? $ventas : iterator_to_array($ventas);

        $datosEmpresa = DatosEmpresa::instancia();
        $ruta = tempnam(sys_get_temp_dir(), 'pdfs_facturas_');

        $zip = new \ZipArchive;
        $zip->open($ruta, \ZipArchive::OVERWRITE);

        // Edge case documentado (spec.md "Mes sin PDFs de facturas"): sin ventas, ZipArchive::close()
        // sobre un archivo sin entradas BORRA el temporal en vez de dejar un ZIP vacío — se agrega un
        // placeholder para que el ZIP exista siempre y el envío no se rompa por un adjunto faltante.
        if ($ventas === []) {
            $zip->addFromString('.vacio', '');
        }

        foreach ($ventas as $venta) {
            $venta->loadMissing(['items', 'conceptos', 'cliente.condicionIva', 'categoria', 'listaPrecio', 'vendedor', 'comprobanteFiscal.puntoVenta', 'cobros.cuentaTesoreria']);

            $qrDataUri = null;
            if ($url = $venta->comprobanteFiscal?->urlQrAfip()) {
                $qrDataUri = (new \Endroid\QrCode\Builder\Builder)
                    ->build(data: $url, size: 150)
                    ->getDataUri();
            }

            $pdf = Pdf::loadView('ventas.pdf', compact('venta', 'qrDataUri', 'datosEmpresa'));

            $zip->addFromString('venta-'.$venta->nro_comprobante.'.pdf', $pdf->output());
        }

        $zip->close();

        return $ruta;
    }
}
