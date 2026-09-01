<?php

namespace App\Services\Stock;

/**
 * Verifica la lectura del export contra el `Saldo Stock` que traía el propio Contagram (spec 094,
 * FR-016).
 *
 * QUÉ ES `Saldo Stock`. No es el saldo del producto, como parecería por el nombre: es el **total
 * del inventario** después de cada movimiento. Se ve en cualquier tramo del archivo — filas
 * consecutivas de productos distintos traen 1368, 1369, 1370, valores contiguos que no pueden ser
 * el stock de tres productos diferentes. Interpretarlo como saldo por producto reporta 28.391
 * discrepancias sobre 28.853 comparaciones, o sea ruido puro.
 *
 * CÓMO SE VERIFICA. El export viene ordenado del movimiento más nuevo al más viejo, así que entre
 * dos filas consecutivas vale:
 *
 *     saldo[i-1] - saldo[i] == cantidad[i-1]
 *
 * Verificado sobre el archivo 2024 completo: 14.004 pares, 14.004 correctos, cero excepciones.
 *
 * QUÉ DETECTA. Es una verificación **independiente** del resto del comando: no comprueba que el
 * código hizo lo que dice, sino que la lectura del archivo reconstruye la misma aritmética que
 * Contagram registró. Si una fila se leyó con la cantidad, el signo o el orden equivocados, la
 * cadena de saldos se rompe justo ahí y el informe lo señala con su número de fila.
 *
 * IMPORTANTE: se alimenta de TODAS las filas del export, incluidas las de cantidad 0 que el lector
 * descarta — son parte de la cadena de saldos y salteárselas la rompería.
 */
class VerificadorSaldosContagram
{
    /**
     * @param  array<int, array{cantidad: float, saldo: float|null, fila: int, operacion: string, codigo: string}>  $filas
     *                                                                                                                      en el orden original del export (del más nuevo al más viejo)
     * @return array{comparados: int, discrepancias: array<int, array<string, mixed>>}
     */
    public function verificar(array $filas): array
    {
        $comparados = 0;
        $discrepancias = [];
        $anterior = null;

        foreach ($filas as $fila) {
            if ($fila['saldo'] === null) {
                continue;
            }

            if ($anterior !== null) {
                $comparados++;

                $delta = $anterior['saldo'] - $fila['saldo'];

                // Tolerancia de milésima: las cantidades son decimal(14,3) y el saldo llega como
                // float desde el Excel.
                if (abs($delta - $anterior['cantidad']) >= 0.001) {
                    $discrepancias[] = [
                        'fila' => $anterior['fila'],
                        'codigo' => $anterior['codigo'],
                        'operacion' => $anterior['operacion'],
                        'cantidad_leida' => $anterior['cantidad'],
                        'delta_del_saldo' => round($delta, 3),
                    ];
                }
            }

            $anterior = $fila;
        }

        return ['comparados' => $comparados, 'discrepancias' => $discrepancias];
    }
}
