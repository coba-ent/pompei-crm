<?php

namespace App\Services\Informes\IvaDigital;

use App\Services\Arca\MapeadorComprobante;
use App\Support\ArchivosFiscales\RegistroAnchoFijo;

/**
 * Archivo "Alícuotas Ventas" del régimen RG 3685 (spec 086): 62 caracteres, una línea por cada
 * alícuota distinta presente en un comprobante de venta (research §1).
 *
 * A diferencia de {@see AlicuotasComprasWriter}, este registro no lleva código ni número de
 * documento del vendedor — 22 caracteres de diferencia (plan §Arquitectura). Confundir ambos
 * layouts corre el archivo entero.
 */
class AlicuotasVentasWriter
{
    private const ANCHO_LINEA = 62;

    public function __construct(
        private RegistroAnchoFijo $r,
        private MapeadorComprobante $mapeador,
    ) {}

    /**
     * Escribe una línea por alícuota con IVA > 0 en el comprobante, a $handle.
     *
     * @param  object  $fila  fila de {@see \App\Services\Informes\LibroIvaVentasQuery::detalle()}
     * @param  array<string, float>  $netoPorAlicuota  neto real por alícuota (`iva_pct` => neto),
     *              de {@see DatosFiscalesComprobante::netoPorAlicuotaVenta()}. Si una alícuota no
     *              está (caso NC/ND, que no tiene ítems propios), se deriva del IVA — único caso
     *              donde el doble redondeo de research Decisión 4 es inevitable, por bajo volumen.
     * @return int cantidad de líneas de alícuota escritas para este comprobante — consumida por
     *              {@see ComprobantesVentasWriter} para que "Cantidad de alícuotas" se cumpla por
     *              construcción (FR-016).
     */
    public function escribir($handle, object $fila, string $cbteTipo, string $puntoVenta, string $numero, array $netoPorAlicuota = []): int
    {
        $cantidad = 0;

        foreach (\App\Services\Informes\DesgloseImpositivoVenta::ALICUOTAS as $alicuotaPct => $columna) {
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
