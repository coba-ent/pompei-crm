<?php

namespace App\Services\Informes\IvaDigital;

use App\Services\Arca\MapeadorComprobante;
use App\Support\ArchivosFiscales\RegistroAnchoFijo;
use Illuminate\Support\Collection;

/**
 * Archivo "Comprobantes Compras" del régimen RG 3685 (spec 086): 325 caracteres por línea
 * (research §1) — más largo que Ventas por el despacho de importación (16), el bloque de emisor
 * por cuenta de terceros (11+30) y el IVA comisión (15) al final; ninguno de los cuales usa el
 * negocio, así que van siempre en blanco/cero (data-model §4).
 */
class ComprobantesComprasWriter
{
    private const ANCHO_LINEA = 325;

    public function __construct(
        private RegistroAnchoFijo $r,
        private MapeadorComprobante $mapeador,
        private AlicuotasComprasWriter $alicuotasWriter,
        private DatosFiscalesComprobante $datosFiscales,
    ) {}

    /**
     * @param  Collection<int, object>  $filas  de {@see \App\Services\Informes\LibroIvaComprasQuery::detalle()}, ya ordenadas
     */
    public function escribir($handleComprobantes, $handleAlicuotas, Collection $filas): void
    {
        $mapaDatos = $this->datosFiscales->resolverCompras($filas);

        foreach ($filas as $fila) {
            $datos = $mapaDatos->get($this->datosFiscales->clave($fila), ['total' => 0.0, 'cuit' => null]);

            [$puntoVenta, $numero] = $this->separarNroComprobante((string) $fila->nro_comprobante);
            $letra = $this->tipoLetra((string) $fila->tipo);
            $cbteTipo = $this->r->numerico($this->mapeador->cbteTipo($letra, $this->tipoNota((string) $fila->tipo)), 3);
            $puntoVentaFmt = $this->r->numerico($puntoVenta, 5);
            $numeroFmt = $this->r->numerico($numero, 20);

            [$docTipo, $docNro] = $this->mapeador->documentoVendedor($datos['cuit']);
            $docTipoFmt = $this->r->numerico($docTipo, 2);
            $docNroFmt = $this->r->numerico((int) $docNro, 20);

            $netoPorAlicuota = $this->esNota((string) $fila->tipo) ? [] : $this->datosFiscales->netoPorAlicuotaCompra((int) $fila->id);
            $cantidadAlicuotas = $this->alicuotasWriter->escribir(
                $handleAlicuotas, $fila, $cbteTipo, $puntoVentaFmt, $numeroFmt, $docTipoFmt, $docNroFmt, $netoPorAlicuota
            );

            $creditoFiscal = $this->creditoFiscalComputable($fila);

            $linea = $this->r->linea([
                $this->r->fecha((string) $fila->emision),
                $cbteTipo,
                $puntoVentaFmt,
                $numeroFmt,
                $this->r->alfanumerico(null, 16),
                $docTipoFmt,
                $docNroFmt,
                $this->r->alfanumerico((string) $fila->contraparte, 30),
                $this->r->importe((float) $datos['total'], 15),
                $this->r->importe(0, 15),
                $this->r->importe((float) $fila->neto_exento, 15),
                $this->r->importe((float) $fila->perc_iva, 15),
                $this->r->importe(0, 15),
                $this->r->importe((float) $fila->perc_iibb, 15),
                $this->r->importe(0, 15),
                $this->r->importe((float) $fila->imp_internos, 15),
                $this->r->alfanumerico('PES', 3),
                $this->r->numerico(1000000, 10),
                $this->r->numerico($cantidadAlicuotas, 1),
                $this->r->alfanumerico('0', 1),
                $this->r->importe($creditoFiscal, 15),
                $this->r->importe(0, 15),
                $this->r->numerico(0, 11),
                $this->r->alfanumerico(null, 30),
                $this->r->importe(0, 15),
            ], self::ANCHO_LINEA);

            fwrite($handleComprobantes, $linea."\r\n");
        }
    }

    /** FR-018: suma del IVA de las alícuotas del comprobante, no un total paralelo. */
    private function creditoFiscalComputable(object $fila): float
    {
        $total = 0.0;

        foreach (\App\Services\Informes\DesgloseImpositivoCompra::ALICUOTAS as $alicuotaPct => $columna) {
            $total += (float) $fila->{'iva_'.str_replace('.', '_', $alicuotaPct)};
        }

        return round($total, 2);
    }

    /** @return array{0: int, 1: int} */
    private function separarNroComprobante(string $nro): array
    {
        if (! str_contains($nro, '-')) {
            return [0, (int) preg_replace('/\D/', '', $nro)];
        }

        [$puntoVenta, $numero] = explode('-', $nro, 2);

        return [(int) $puntoVenta, (int) $numero];
    }

    private function tipoLetra(string $tipo): string
    {
        return substr($tipo, -1);
    }

    private function tipoNota(string $tipo): ?string
    {
        return match (true) {
            str_starts_with($tipo, 'NC') => 'credito',
            str_starts_with($tipo, 'ND') => 'debito',
            default => null,
        };
    }

    private function esNota(string $tipo): bool
    {
        return str_starts_with($tipo, 'NC') || str_starts_with($tipo, 'ND');
    }
}
