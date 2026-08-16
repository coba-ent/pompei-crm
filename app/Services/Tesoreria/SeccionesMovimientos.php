<?php

namespace App\Services\Tesoreria;

use Illuminate\Support\Collection;

/**
 * Arma las secciones Cobros y Pagos del Informe de Movimientos como las muestra Contagram.
 *
 * Vive acá y no dentro del export porque el XLSX y el PDF tienen que mostrar exactamente lo
 * mismo: si estas reglas quedaran duplicadas en los dos, el día que aparezca otra cuenta como
 * "Cheque de Terceros" se arreglaría uno y el otro quedaría mintiendo.
 *
 * LAS REGLAS, relevadas del XLSX y del PDF reales de Contagram (16/08/2026):
 *
 * - No se listan sólo las cuentas con movimiento: se listan TODAS las que aplican a la sección,
 *   y las que no tuvieron nada van en 0. Por eso el informe muestra 18 y 13 filas donde
 *   `Tesoreria::flujo()` devuelve 7 y 6.
 * - Cobros = cuentas de tipo `efectivo` + `banco` + `a_cobrar`.
 * - Pagos  = `efectivo` + `banco` + `a_pagar`, **más "Cheque de Terceros"**, que es `a_cobrar` y
 *   aun así aparece. Tiene sentido de negocio —un cheque recibido se endosa para pagar— pero la
 *   excepción está calibrada contra un solo archivo: no se pudo derivar de los datos (16 cuentas
 *   registran pagos alguna vez y el informe lista 13). Si aparece otra igual, va en PAGOS_EXTRA.
 * - Los importes de Pagos se muestran en NEGATIVO; `flujo()` los devuelve en valor absoluto.
 *
 * PENDIENTE: el criterio de ORDEN de las filas no se pudo deducir. No es alfabético, ni por id,
 * ni por la columna `orden`. Acá se ordena por tipo y alfabéticamente dentro de cada tipo, que se
 * aproxima pero no calca — hace falta otro export de un período distinto para cerrarlo. Además
 * los nombres de cuenta de Contagram y los de la base importada difieren ("Galicia" vs "Banco
 * Galicia"), lo que arrastra diferencias de orden aunque el criterio fuera el mismo.
 */
class SeccionesMovimientos
{
    private const TIPOS_COBROS = ['efectivo', 'banco', 'a_cobrar'];

    private const TIPOS_PAGOS = ['efectivo', 'banco', 'a_pagar'];

    private const PAGOS_EXTRA = ['Cheque de Terceros'];

    /** Cuentas que Contagram manda al final de su sección, fuera del orden alfabético. */
    private const AL_FINAL = ['Cheque de Terceros'];

    /**
     * @param  array{cobros: list<array{nombre: string, monto: float}>, pagos: list<array{nombre: string, monto: float}>}  $flujo
     * @param  Collection<int, \App\Models\CuentaTesoreria>  $cuentas
     * @return array{cobros: list<array{nombre: string, monto: float}>, pagos: list<array{nombre: string, monto: float}>}
     */
    public function armar(array $flujo, Collection $cuentas): array
    {
        return [
            'cobros' => $this->seccion($cuentas, self::TIPOS_COBROS, [], $flujo['cobros'], 1),
            'pagos' => $this->seccion($cuentas, self::TIPOS_PAGOS, self::PAGOS_EXTRA, $flujo['pagos'], -1),
        ];
    }

    /**
     * @param  Collection<int, \App\Models\CuentaTesoreria>  $cuentas
     * @param  list<string>  $tipos
     * @param  list<string>  $extra
     * @param  list<array{nombre: string, monto: float}>  $conMovimiento
     * @param  int  $signo  1 para Cobros, -1 para Pagos
     * @return list<array{nombre: string, monto: float}>
     */
    private function seccion(Collection $cuentas, array $tipos, array $extra, array $conMovimiento, int $signo): array
    {
        $montos = [];
        foreach ($conMovimiento as $fila) {
            $montos[$fila['nombre']] = (float) $fila['monto'];
        }

        return $cuentas
            ->filter(fn ($c) => in_array($c->tipo, $tipos, true) || in_array($c->nombre, $extra, true))
            ->sortBy([
                fn ($a, $b) => in_array($a->nombre, self::AL_FINAL, true) <=> in_array($b->nombre, self::AL_FINAL, true),
                fn ($a, $b) => array_search($a->tipo, $tipos, true) <=> array_search($b->tipo, $tipos, true),
                fn ($a, $b) => strcasecmp($a->nombre, $b->nombre),
            ])
            ->map(fn ($c) => [
                'nombre' => $c->nombre,
                'monto' => round(($montos[$c->nombre] ?? 0.0) * $signo, 2),
            ])
            ->values()
            ->all();
    }
}
