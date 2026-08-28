<?php

namespace App\Services\Informes\IvaDigital;

use App\Services\Arca\MapeadorComprobante;
use App\Support\ArchivosFiscales\RegistroAnchoFijo;
use Illuminate\Support\Collection;

/**
 * Archivo "Comprobantes Ventas" del régimen RG 3685 (spec 086): 266 caracteres por línea
 * (research §1).
 *
 * Orquesta también {@see AlicuotasVentasWriter} para el comprobante: "Cantidad de alícuotas" sale
 * del conteo real de líneas escritas en Alícuotas Ventas, no de un cálculo paralelo (FR-016,
 * research Decisión 5) — así la corrección del defecto de origen (comprobantes de MercadoLibre con
 * `0` alícuotas pese a tener IVA) es imposible de romper por accidente.
 */
class ComprobantesVentasWriter
{
    private const ANCHO_LINEA = 266;

    public function __construct(
        private RegistroAnchoFijo $r,
        private MapeadorComprobante $mapeador,
        private AlicuotasVentasWriter $alicuotasWriter,
        private DatosFiscalesComprobante $datosFiscales,
    ) {}

    /**
     * @param  Collection<int, object>  $filas  de {@see \App\Services\Informes\LibroIvaVentasQuery::detalle()}, ya ordenadas
     */
    public function escribir($handleComprobantes, $handleAlicuotas, Collection $filas): void
    {
        $mapaDatos = $this->datosFiscales->resolverVentas($filas);

        foreach ($filas as $fila) {
            $datos = $mapaDatos->get($this->datosFiscales->clave($fila), ['total' => 0.0, 'tipo_documento' => null, 'documento' => null]);

            [$puntoVenta, $numero] = $this->separarNroComprobante((string) $fila->nro_comprobante);
            $letra = $this->tipoLetra((string) $fila->tipo);
            $cbteTipo = $this->r->numerico($this->mapeador->cbteTipo($letra, $this->tipoNota((string) $fila->tipo)), 3);
            $puntoVentaFmt = $this->r->numerico($puntoVenta, 5);
            $numeroFmt = $this->r->numerico($numero, 20);

            $netoPorAlicuota = ($this->esNota((string) $fila->tipo) || $this->datosFiscales->esHistorico($fila))
                ? []
                : $this->datosFiscales->netoPorAlicuotaVenta((int) $fila->id);
            $cantidadAlicuotas = $this->alicuotasWriter->escribir($handleAlicuotas, $fila, $cbteTipo, $puntoVentaFmt, $numeroFmt, $netoPorAlicuota);

            [$docTipo, $docNro] = $this->mapeador->documentoReceptor([
                'tipo_documento' => $datos['tipo_documento'],
                'documento' => $datos['documento'],
                'cuit' => $fila->cuit,
            ]);

            $linea = $this->r->linea([
                $this->r->fecha((string) $fila->emision),
                $cbteTipo,
                $puntoVentaFmt,
                $numeroFmt,
                $numeroFmt,
                $this->r->numerico($docTipo, 2),
                $this->r->numerico((int) $docNro, 20),
                $this->r->alfanumerico((string) $fila->contraparte, 30),
                $this->r->importe((float) $datos['total'], 15),
                $this->r->importe(0, 15),
                $this->r->importe(0, 15),
                $this->r->importe((float) $fila->neto_exento, 15),
                $this->r->importe((float) $fila->perc_iva, 15),
                $this->r->importe((float) $fila->perc_iibb, 15),
                $this->r->importe(0, 15),
                $this->r->importe((float) $fila->imp_internos, 15),
                $this->r->alfanumerico('PES', 3),
                $this->r->numerico(1000000, 10),
                $this->r->numerico($cantidadAlicuotas, 1),
                $this->r->alfanumerico('0', 1),
                $this->r->importe(0, 15),
                $this->r->fecha((string) $fila->emision),
            ], self::ANCHO_LINEA);

            fwrite($handleComprobantes, $linea."\r\n");
        }
    }

    /**
     * `nro_comprobante` se guarda como `"PPPP-NNNNNNNN"` (research §1, verificado contra el fixture
     * y la base real: `ventas.nro_comprobante` / `comprobantes_fiscales.numero` usan el mismo
     * formato). Sin guión (dato legado) se trata todo como número, sin punto de venta.
     *
     * @return array{0: int, 1: int}
     */
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
