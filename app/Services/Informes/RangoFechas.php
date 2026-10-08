<?php

namespace App\Services\Informes;

use Illuminate\Http\Request;

/**
 * Rango de emisión de los informes con default "Mes actual" (Ventas, Compras, Gastos, Reporte
 * Final).
 *
 * Tres casos:
 * - el request trae fechas → ese rango;
 * - no trae la clave (links, exports viejos, tests) → mes calendario en curso (FR-004b);
 * - trae la clave **vacía** → el usuario borró el filtro con la X o "Borrar filtro": sin límite
 *   de fechas. Antes esto también caía en el mes actual, así que la X "no vaciaba".
 *
 * El rango abierto se expresa con fechas extremas para que las consultas sigan usando su
 * `whereBetween` sin ramas nuevas; las vistas lo muestran con {@see self::etiqueta()}.
 */
final class RangoFechas
{
    public const DESDE_ABIERTO = '1900-01-01';

    public const HASTA_ABIERTO = '2999-12-31';

    /**
     * @param  list<string>  $clavesDesde  nombres aceptados, en orden de prioridad
     * @param  list<string>  $clavesHasta
     * @return array{desde: string, hasta: string}
     */
    public static function resolver(Request $request, array $clavesDesde = ['fecha_desde'], array $clavesHasta = ['fecha_hasta']): array
    {
        return [
            'desde' => self::extremo($request, $clavesDesde, now()->startOfMonth()->toDateString(), self::DESDE_ABIERTO),
            'hasta' => self::extremo($request, $clavesHasta, now()->endOfMonth()->toDateString(), self::HASTA_ABIERTO),
        ];
    }

    /** "01/08/2026 – 31/08/2026", o "Todo el período" si el filtro está borrado. */
    public static function etiqueta(string $desde, string $hasta, string $separador = ' – '): string
    {
        return self::formatear($desde).$separador.self::formatear($hasta);
    }

    /** dd/mm/aaaa, o "Sin límite" para un extremo abierto. */
    public static function formatear(string $fecha): string
    {
        if (in_array(substr($fecha, 0, 10), [self::DESDE_ABIERTO, self::HASTA_ABIERTO], true)) {
            return 'Sin límite';
        }

        return implode('/', array_reverse(explode('-', substr($fecha, 0, 10))));
    }

    /** @param  list<string>  $claves */
    private static function extremo(Request $request, array $claves, string $porDefecto, string $abierto): string
    {
        foreach ($claves as $clave) {
            if ($request->filled($clave)) {
                return (string) $request->input($clave);
            }
        }

        foreach ($claves as $clave) {
            if ($request->exists($clave)) {
                return $abierto;
            }
        }

        return $porDefecto;
    }
}
