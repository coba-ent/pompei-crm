<?php

namespace App\Support;

/**
 * Contexto explícito del origen de un cambio de precio (spec 074, FR-008/FR-009).
 *
 * `PrecioProductoObserver` es el único punto por el que pasan todas las escrituras de
 * `precios_producto`, pero desde ahí no hay forma de saber *qué* las originó. Cada punto
 * de entrada declara su origen envolviendo su trabajo en `durante()`, y el observer lo
 * lee con `actual()` para armar el rótulo del detalle.
 *
 * El default es deliberado: un camino de escritura nuevo que se olvide de declarar su
 * origen igual queda auditado, con rótulo "origen no identificado". Falla hacia el lado
 * seguro — se pierde precisión del rótulo, nunca el registro.
 */
class OrigenCambioPrecio
{
    public const IMPORTACION = 'importacion';

    public const DESHACER_IMPORT = 'deshacer_import';

    public const MANUAL = 'manual';

    public const EDICION_MASIVA = 'edicion_masiva';

    public const COPIA = 'copia';

    public const DESCONOCIDO = 'desconocido';

    /** Rótulo legible de cada origen, tal como aparece en el `detalle` del evento. */
    private const ROTULOS = [
        self::IMPORTACION => 'importación',
        self::DESHACER_IMPORT => 'deshacer import',
        self::MANUAL => 'edición manual',
        self::EDICION_MASIVA => 'edición masiva',
        self::COPIA => 'copia de producto',
        self::DESCONOCIDO => 'origen no identificado',
    ];

    private static string $actual = self::DESCONOCIDO;

    /**
     * Ejecuta `$fn` declarando `$origen` como el origen vigente, y restaura el anterior
     * al terminar. El `finally` es obligatorio: sin él, una excepción del callable dejaría
     * el origen contaminado para el resto de la request.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public static function durante(string $origen, callable $fn): mixed
    {
        $previo = self::$actual;
        self::$actual = $origen;

        try {
            return $fn();
        } finally {
            self::$actual = $previo;
        }
    }

    /** Origen vigente; `DESCONOCIDO` si nadie lo declaró. */
    public static function actual(): string
    {
        return self::$actual;
    }

    /** Rótulo legible del origen vigente (o del que se le pase). */
    public static function rotulo(?string $origen = null): string
    {
        return self::ROTULOS[$origen ?? self::$actual] ?? self::ROTULOS[self::DESCONOCIDO];
    }
}
