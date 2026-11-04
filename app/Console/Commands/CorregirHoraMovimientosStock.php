<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pasa a hora argentina la `fecha` de los movimientos de stock que el CRM grabó en UTC.
 *
 * Hasta el 04/11/2026 `StockService` grababa `now()` —UTC— en `movimientos_stock.fecha`, mientras
 * el histórico importado de Contagram y las fechas de documento estaban en hora argentina. El
 * Informe de Stock muestra la columna tal cual, así que esos movimientos aparecían 3 horas
 * adelantados (una venta de Mercado Libre de las 11:19 figuraba a las 14:19, y en Auditoría —que sí
 * convierte— no se la encontraba). Ver `StockService::fechaMovimiento()`.
 *
 * Corrige dos casos, ambos reconocibles sin ambigüedad:
 *
 * A. **Grabados con `now()`**: hora distinta de 00:00 y `fecha` igual a `created_at` (±5 s). Sólo
 *    `now()` produce esa coincidencia: el histórico importado tiene una `fecha` de años atrás y un
 *    `created_at` del día de la importación. Se reemplaza por `created_at` en hora argentina.
 *
 * B. **Fecha sin hora cargada el mismo día** (ajuste o transferencia del modal, compra fechada
 *    hoy): hora 00:00, y el día de `created_at` en hora argentina coincide con el de `fecha`. Se
 *    recupera la hora real desde `created_at`. Sólo desde el corte de la migración (13/08/2026):
 *    antes de esa fecha los movimientos son del histórico importado.
 *
 * Idempotente: una fila corregida deja de cumplir ambos criterios. Escribe con el query builder,
 * no con Eloquent, a propósito: un `save()` dispararía la auditoría ("Modificó") y la
 * sincronización de stock con Mercado Libre/Tiendanube por cada fila, cuando el stock no cambia.
 */
class CorregirHoraMovimientosStock extends Command
{
    private const CORTE_MIGRACION = '2026-08-13';

    protected $signature = 'stock:corregir-hora-movimientos
                            {--aplicar : Escribe las correcciones. Sin este flag sólo informa qué haría.}';

    protected $description = 'Pasa a hora argentina la fecha de los movimientos de stock grabados en UTC';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $zona = config('app.display_timezone');

        if (! $aplicar) {
            $this->warn('Modo simulación: no se escribe nada. Usá --aplicar para guardar.');
        }

        $conteo = ['utc' => 0, 'medianoche' => 0];
        $ejemplos = [];

        DB::table('movimientos_stock')
            ->whereNotNull('created_at')
            ->where('created_at', '>=', self::CORTE_MIGRACION)
            ->orderBy('id')
            ->select(['id', 'fecha', 'created_at', 'tipo'])
            ->chunkById(1000, function ($filas) use ($aplicar, $zona, &$conteo, &$ejemplos) {
                foreach ($filas as $fila) {
                    $fecha = Carbon::parse($fila->fecha, 'UTC');
                    $creado = Carbon::parse($fila->created_at, 'UTC');
                    $creadoLocal = $creado->copy()->setTimezone($zona);

                    $caso = null;

                    if ($fecha->format('H:i:s') !== '00:00:00' && abs($fecha->diffInSeconds($creado, false)) <= 5) {
                        $caso = 'utc';
                    } elseif (
                        $fecha->format('H:i:s') === '00:00:00'
                        && $fecha->toDateString() >= self::CORTE_MIGRACION
                        && $creadoLocal->toDateString() === $fecha->toDateString()
                    ) {
                        $caso = 'medianoche';
                    }

                    if ($caso === null) {
                        continue;
                    }

                    $nueva = $creadoLocal->format('Y-m-d H:i:s');
                    $conteo[$caso]++;

                    if (count($ejemplos) < 10) {
                        $ejemplos[] = [$fila->id, $fila->tipo, $caso, $fecha->format('Y-m-d H:i:s'), $nueva];
                    }

                    if ($aplicar) {
                        DB::table('movimientos_stock')->where('id', $fila->id)->update(['fecha' => $nueva]);
                    }
                }
            });

        $this->table(['Id', 'Tipo', 'Caso', 'Fecha guardada', 'Fecha corregida'], $ejemplos);
        $this->table(['Caso', 'Movimientos'], [
            ['A — grabados en UTC (hora corrida 3 h)', $conteo['utc']],
            ['B — cargados sin hora el mismo día (00:00)', $conteo['medianoche']],
        ]);

        if (! $aplicar) {
            $this->warn('No se escribió nada. Repetí con --aplicar (con backup de la base hecho).');
        }

        return self::SUCCESS;
    }
}
