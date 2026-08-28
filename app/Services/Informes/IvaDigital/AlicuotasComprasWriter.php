<?php

namespace App\Services\Informes\IvaDigital;

use App\Services\Arca\MapeadorComprobante;
use App\Support\ArchivosFiscales\RegistroAnchoFijo;

/**
 * Archivo "Alícuotas Compras" del régimen RG 3685 (spec 086): 84 caracteres.
 *
 * Ojo (research §1, plan §Arquitectura): lleva código y número de documento del vendedor entre el
 * número de comprobante y el neto gravado — 22 caracteres que no tiene {@see AlicuotasVentasWriter}.
 */
class AlicuotasComprasWriter
{
    private const ANCHO_LINEA = 84;

    public function __construct(
        private RegistroAnchoFijo $r,
        private MapeadorComprobante $mapeador,
    ) {}

    /**
     * @param  object  $fila  fila de {@see \App\Services\Informes\LibroIvaComprasQuery::detalle()}
     * @param  array<string, float>  $netoPorAlicuota  ver {@see AlicuotasVentasWriter::escribir()}.
     * @return int cantidad de líneas de alícuota escritas — consumida por {@see ComprobantesComprasWriter}.
     */
    public function escribir($handle, object $fila, string $cbteTipo, string $puntoVenta, string $numero, string $docTipoVendedor, string $docNroVendedor, array $netoPorAlicuota = []): int
    {
        $cantidad = 0;

        foreach (\App\Services\Informes\DesgloseImpositivoCompra::ALICUOTAS as $alicuotaPct => $columna) {
            $iva = (float) $fila->{'iva_'.str_replace('.', '_', $alicuotaPct)};

            if (round($iva, 2) <= 0) {
                continue;
            }

            $neto = round($netoPorAlicuota[$alicuotaPct] ?? ($iva / ((float) $alicuotaPct / 100)), 2);
            $codigoAlicuota = $this->mapeador->codigoAlicuotaIva((float) $alicuotaPct)
                ?? throw new \InvalidArgumentException("Alícuota de IVA no soportada por ARCA: {$alicuotaPct}%");

            $linea = $this->r->linea([
                $cbteTipo,
                $puntoVenta,
                $numero,
                $docTipoVendedor,
                $docNroVendedor,
                $this->r->importe($neto, 15),
                $this->r->alicuota($codigoAlicuota),
                $this->r->importe($iva, 15),
            ], self::ANCHO_LINEA);

            fwrite($handle, $linea."\r\n");
            $cantidad++;
        }

        return $cantidad;
    }
}
