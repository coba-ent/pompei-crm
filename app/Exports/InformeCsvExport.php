<?php

namespace App\Exports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación "a Excel" genérica de los informes del módulo (research.md
 * D-002): CSV UTF-8 con BOM y separador `;`, mismo patrón que
 * CuentaCorrienteCsvExport. Un único método sirve para los siete informes:
 * cada controlador arma sus propios encabezados y filas ya formateadas.
 */
class InformeCsvExport
{
    /**
     * @param  array<int, string>  $encabezados
     * @param  iterable<array<int, mixed>>  $filas  Filas ya formateadas (una entrada por columna, en el mismo orden que $encabezados).
     */
    public function stream(array $encabezados, iterable $filas, string $nombreArchivo): StreamedResponse
    {
        return response()->streamDownload(function () use ($encabezados, $filas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, ';');

            foreach ($filas as $fila) {
                fputcsv($salida, $fila, ';');
            }

            fclose($salida);
        }, $nombreArchivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
