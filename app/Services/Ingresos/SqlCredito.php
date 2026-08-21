<?php

namespace App\Services\Ingresos;

use App\Models\Compra;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * Los dos términos de crédito de la fórmula de saldo, en SQL (spec 072).
 *
 * La fórmula `total + ND − NC − cobrado` está replicada a mano en varios lugares que no pueden
 * hidratar modelos (filtros del listado, KPIs, aging, informes). Cuando se agregó el efecto de las
 * Notas de Crédito en `estadoCobro()` sin tocar su réplica SQL, el badge decía "Cobrado" y el
 * filtro devolvía la venta en "Sin Cobrar" — 457 ventas por $43,3M (20/08/2026). Para no repetirlo,
 * los términos nuevos viven acá y **todas** las réplicas los toman de esta clase.
 *
 * El tipo del morph se escribe con `PDO::quote()` y no interpolado: en MySQL la barra invertida de
 * `App\Models\Venta` es carácter de escape dentro de un literal, así que un `'App\Models\Venta'`
 * pelado se compara contra `AppModelsVenta` y nunca matchea. SQLite no escapa, y PDO resuelve las
 * dos formas según el driver.
 */
class SqlCredito
{
    /** Σ crédito que el comprobante RECIBIÓ de otros: resta del A Cobrar / A Pagar. */
    public static function recibido(string $tabla): string
    {
        return self::suma($tabla, 'destino');
    }

    /** Σ crédito que el comprobante CEDIÓ a otros: suma al A Cobrar / A Pagar (evita el doble conteo). */
    public static function cedido(string $tabla): string
    {
        return self::suma($tabla, 'origen');
    }

    /** `− recibido + cedido`: el bloque completo, listo para pegar al final de la fórmula de saldo. */
    public static function terminos(string $tabla): string
    {
        return '- '.self::recibido($tabla).' + '.self::cedido($tabla);
    }

    private static function suma(string $tabla, string $extremo): string
    {
        $tipo = self::tipoMorph($tabla);

        return "COALESCE((SELECT SUM(ac.monto) FROM aplicaciones_credito ac
            WHERE ac.{$extremo}_id = {$tabla}.id AND ac.{$extremo}_type = {$tipo} AND ac.deleted_at IS NULL), 0)";
    }

    private static function tipoMorph(string $tabla): string
    {
        $clase = $tabla === 'ventas' ? Venta::class : Compra::class;

        return DB::getPdo()->quote($clase);
    }
}
