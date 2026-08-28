<?php

namespace App\Support\ArchivosFiscales;

/**
 * Único lugar del sistema que sabe convertir un valor de dominio en una tira de caracteres
 * posicionados para los archivos de ancho fijo del régimen RG 3685 (spec 086).
 *
 * Todos los métodos trabajan en **bytes**, no en caracteres: el ancho del registro que exige ARCA
 * se mide en bytes, y la conversión a latin-1 sucede **antes** del padding (research.md Decisión 2)
 * porque un carácter multibyte en UTF-8 corre todo lo que sigue si se convierte después.
 */
class RegistroAnchoFijo
{
    /** Entero alineado a derecha con ceros a la izquierda. */
    public function numerico(int $valor, int $ancho): string
    {
        return str_pad((string) $valor, $ancho, '0', STR_PAD_LEFT);
    }

    /** Importe en centavos: multiplica por 100, redondea, padea con ceros. Sin signo ni separador. */
    public function importe(float $valor, int $ancho): string
    {
        $centavos = (int) round($valor * 100);

        return str_pad((string) $centavos, $ancho, '0', STR_PAD_LEFT);
    }

    /**
     * Alineado a izquierda, espacios a derecha, truncado al ancho. La conversión a latin-1 va
     * primero: si se truncara sobre UTF-8, un carácter multibyte cortado a la mitad produciría
     * bytes inválidos.
     */
    public function alfanumerico(?string $valor, int $ancho): string
    {
        $latin1 = mb_convert_encoding((string) $valor, 'ISO-8859-1', 'UTF-8');
        $truncado = substr($latin1, 0, $ancho);

        return str_pad($truncado, $ancho, ' ', STR_PAD_RIGHT);
    }

    /** `AAAAMMDD`. */
    public function fecha(string $valor): string
    {
        return \Carbon\Carbon::parse($valor)->format('Ymd');
    }

    /** Código de alícuota, 4 dígitos. */
    public function alicuota(int $codigo): string
    {
        return $this->numerico($codigo, 4);
    }

    /**
     * Concatena los campos y verifica el ancho total, lanzando si no coincide. Deliberado
     * (plan.md §Componentes 1): convierte el modo de falla más peligroso — un archivo corrido que
     * ARCA rechaza recién en la presentación — en un error inmediato y ruidoso al generar.
     *
     * @param  array<int, string>  $campos
     */
    public function linea(array $campos, int $anchoEsperado): string
    {
        $linea = implode('', $campos);
        $anchoReal = strlen($linea);

        if ($anchoReal !== $anchoEsperado) {
            throw new \RuntimeException(
                "Línea de ancho fijo mal formada: se esperaban {$anchoEsperado} bytes, se generaron {$anchoReal}."
            );
        }

        return $linea;
    }
}
