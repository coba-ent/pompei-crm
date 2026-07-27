<?php

namespace App\Exports;

use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación "a Excel" del listado de saldos y del detalle de movimientos
 * como CSV UTF-8 con BOM (research.md D-003) — abre directo en Excel con
 * acentos correctos, sin agregar una dependencia de xlsx sólo para esto.
 */
class CuentaCorrienteCsvExport
{
    /** Exporta el listado de saldos (clientes o proveedores). */
    public function saldos(Builder $query, string $nombreEntidad, string $nombreArchivo): StreamedResponse
    {
        $encabezados = [$nombreEntidad, 'CUIT / Documento', 'Saldo'];

        return response()->streamDownload(function () use ($query, $encabezados) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, ';');

            foreach ($query->orderBy('nombre')->get() as $fila) {
                fputcsv($salida, [$fila->nombre, $fila->documento, number_format((float) $fila->saldo, 2, ',', '')], ';');
            }

            fclose($salida);
        }, $nombreArchivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Exporta el detalle de movimientos de una entidad.
     *
     * @param  array<int, array<string, mixed>>  $filas  Filas ya formateadas (mismo shape que la respuesta JSON del detalle).
     */
    public function movimientos(array $filas, string $nombreArchivo): StreamedResponse
    {
        $encabezados = ['Fecha', 'Tipo', 'Comprobante', 'Debe', 'Haber', 'Acumulado'];

        return response()->streamDownload(function () use ($filas, $encabezados) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, ';');

            foreach ($filas as $fila) {
                fputcsv($salida, [
                    $fila['fecha'],
                    $fila['tipo_label'],
                    $fila['comprobante'],
                    $fila['debe'] !== null ? number_format((float) $fila['debe'], 2, ',', '') : '',
                    $fila['haber'] !== null ? number_format((float) $fila['haber'], 2, ',', '') : '',
                    number_format((float) $fila['acumulado'], 2, ',', ''),
                ], ';');
            }

            fclose($salida);
        }, $nombreArchivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
