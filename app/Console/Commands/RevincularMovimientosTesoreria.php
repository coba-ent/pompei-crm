<?php

namespace App\Console\Commands;

use App\Models\Cobro;
use App\Models\MovimientoTesoreria;
use App\Models\Pago;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repone el vínculo polimórfico entre pagos/cobros importados de Contagram y sus movimientos de
 * tesorería.
 *
 * La importación cargó las dos puntas —el pago y su movimiento— pero no `origen_type`/`origen_id`,
 * así que quedaron sin conocerse. El id de Contagram vive sólo en el `legacy_id` del movimiento
 * (`TES-{cuenta}-PAG-{id}-{fecha}--{monto}`) y las tablas `pagos`/`cobros` no lo guardan, así que
 * el apareo se hace por (cuenta, fecha, monto).
 *
 * Cuando varios movimientos calzan con el mismo pago son indistinguibles entre sí —mismo día,
 * misma cuenta, mismo importe—, así que se aparean en orden de id: cualquier asignación da el
 * mismo resultado contable. Cada movimiento se consume una sola vez.
 */
class RevincularMovimientosTesoreria extends Command
{
    protected $signature = 'tesoreria:revincular-movimientos
                            {--aplicar : Escribe los vínculos. Sin este flag sólo informa qué haría.}';

    protected $description = 'Re-vincula pagos/cobros importados con sus movimientos de tesorería huérfanos';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        if (! $aplicar) {
            $this->warn('Modo simulación: no se escribe nada. Usá --aplicar para guardar los vínculos.');
        }

        $totales = [];

        foreach ([
            ['modelo' => Pago::class, 'tipo' => 'pago', 'signo' => -1, 'etiqueta' => 'Pagos'],
            ['modelo' => Cobro::class, 'tipo' => 'cobro', 'signo' => 1, 'etiqueta' => 'Cobros'],
        ] as $caso) {
            $this->newLine();
            $this->info($caso['etiqueta']);
            $totales[$caso['etiqueta']] = $this->procesar($caso, $aplicar);
        }

        $this->newLine();
        $this->table(
            ['', 'Vinculados', 'Sin movimiento'],
            collect($totales)->map(fn ($t, $k) => [$k, $t['vinculados'], $t['sin_match']])->values()->all(),
        );

        if (! $aplicar) {
            $this->newLine();
            $this->warn('No se escribió nada. Repetí con --aplicar (con backup de la base hecho).');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{modelo: class-string, tipo: string, signo: int, etiqueta: string}  $caso
     * @return array{vinculados: int, sin_match: int}
     */
    private function procesar(array $caso, bool $aplicar): array
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelo */
        $modelo = $caso['modelo'];

        $pendientes = $modelo::query()
            ->whereNotExists(function ($q) use ($caso) {
                $q->select(DB::raw(1))
                    ->from('movimientos_tesoreria as m')
                    ->whereColumn('m.origen_id', 'id')
                    ->where('m.origen_type', (new $caso['modelo'])->getMorphClass())
                    ->whereNull('m.deleted_at');
            })
            ->orderBy('id')
            ->get();

        $this->line('  sin vínculo: '.$pendientes->count());

        // Los movimientos huérfanos se indexan una sola vez y se van consumiendo: sin eso, dos
        // pagos idénticos se llevarían el MISMO movimiento y el segundo quedaría igual de roto.
        $disponibles = MovimientoTesoreria::query()
            ->where('tipo', $caso['tipo'])
            ->whereNull('origen_type')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (MovimientoTesoreria $m) => $this->clave(
                (int) $m->cuenta_tesoreria_id,
                $m->fecha->toDateString(),
                (float) $m->monto,
            ))
            ->map(fn ($grupo) => $grupo->values()->all());

        $indices = [];
        $vinculos = [];
        $sinMatch = 0;

        $barra = $this->output->createProgressBar($pendientes->count());
        $barra->start();

        foreach ($pendientes as $registro) {
            $clave = $this->clave(
                (int) $registro->cuenta_tesoreria_id,
                $registro->fecha->toDateString(),
                $caso['signo'] * (float) $registro->monto,
            );

            $grupo = $disponibles->get($clave, []);
            $i = $indices[$clave] ?? 0;

            if (! isset($grupo[$i])) {
                $sinMatch++;
                $barra->advance();

                continue;
            }

            $indices[$clave] = $i + 1;
            $vinculos[] = [
                'movimiento_id' => $grupo[$i]->id,
                'origen_type' => $registro->getMorphClass(),
                'origen_id' => $registro->getKey(),
            ];
            $barra->advance();
        }

        $barra->finish();
        $this->newLine();
        $this->line('  se vinculan:    '.count($vinculos));
        $this->line('  sin movimiento: '.$sinMatch);

        if ($aplicar && $vinculos !== []) {
            DB::transaction(function () use ($vinculos) {
                foreach (array_chunk($vinculos, 500) as $lote) {
                    foreach ($lote as $v) {
                        DB::table('movimientos_tesoreria')
                            ->where('id', $v['movimiento_id'])
                            ->update([
                                'origen_type' => $v['origen_type'],
                                'origen_id' => $v['origen_id'],
                            ]);
                    }
                }
            });
            $this->info('  vínculos escritos.');
        }

        return ['vinculados' => count($vinculos), 'sin_match' => $sinMatch];
    }

    /** El monto entra a la clave redondeado a 2 decimales: `decimal:2` en un lado y float en el otro. */
    private function clave(int $cuentaId, string $fecha, float $monto): string
    {
        return $cuentaId.'|'.$fecha.'|'.number_format($monto, 2, '.', '');
    }
}
