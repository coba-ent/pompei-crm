<?php

namespace App\Console\Commands;

use App\Models\Cobro;
use App\Models\Venta;
use App\Services\Migracion\ComprobantesContagram;
use App\Services\Migracion\CuentasDeTesoreria;
use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carga los cobros de las ventas históricas ya migradas por `migracion:ventas`.
 *
 * Va en un comando aparte a propósito: las ventas tienen que estar bien antes de imputarles plata,
 * y así los cobros se pueden recalcular sin volver a tocar 23.563 ventas.
 *
 * **No genera movimientos de tesorería**, por el mismo motivo por el que las ventas no mueven stock:
 * son cobros que ya ocurrieron y cuyo efecto está consolidado en el saldo actual de cada cuenta.
 * Generarlos sumaría seis años de ingresos sin sus egresos —las compras y gastos se importan
 * después— y dejaría la tesorería inflada mientras tanto. Lo que sí queda correcto es el
 * **cobrado / a cobrar de cada venta**, que es lo que hace cerrar la caja de Ventas.
 *
 * Idempotente: una venta que ya tiene cobros se saltea.
 *
 * Limitaciones asumidas (documentadas en docs/importacion_2021_2026_plan_tecnico.md §5):
 * - **Un cobro por venta**, por el `Cobrado` total. El export no trae el desglose de los parciales.
 * - **Fecha = la de emisión**: el `c/ cobro` no trae fecha de cobro. La real está en `Cuentas/`.
 * - **Cuenta = el primer medio** de la lista. Sólo el 2,4% de las ventas mezcla medios distintos.
 */
class MigrarCobrosContagram extends Command
{
    protected $signature = 'migracion:cobros
        {--dry-run : No escribe nada; sólo reporta}
        {--anio= : Procesar un solo año}';

    protected $description = 'Carga los cobros de las ventas históricas ya migradas (no toca tesorería)';

    private CuentasDeTesoreria $cuentas;

    public function handle(LectorExcelContagram $lector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $base = public_path('imports');
        $servicio = new ComprobantesContagram($lector, $base);
        $anios = $this->option('anio') ? [$this->option('anio')] : ComprobantesContagram::ANIOS;

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— IMPORTANDO COBROS —');
        $this->cuentas = new CuentasDeTesoreria();

        $headerCanonico = $lector->leer($base.'/Ventas/Ventas 2022.xlsx')['header'];

        $stats = array_fill_keys([
            'cobros_creados', 'ya_tenian_cobros', 'venta_no_migrada', 'sin_cobro', 'sin_medio',
        ], 0);
        $monto = 0.0;

        foreach ($anios as $anio) {
            $this->line("Procesando {$anio}…");
            $comprobantes = $servicio->delAnio($anio, $anio === '2023' ? $headerCanonico : null);

            $barra = $this->output->createProgressBar(count($comprobantes));
            foreach ($comprobantes as $legacyId => $c) {
                $barra->advance();

                if ($c['familia'] !== 'FC' || ! $servicio->dentroDelCorte($c['fecha_emision'])) {
                    continue;
                }
                if (abs($c['cobrado']) < 0.005) {
                    $stats['sin_cobro']++;

                    continue;
                }

                // Se busca la venta en vez de asumirla: si `migracion:ventas` la excluyó (duplicado
                // de Mercado Libre, borrada), su cobro tampoco va — imputarlo a nada duplicaría plata.
                $venta = Venta::where('legacy_id', $legacyId)->first(['id', 'created_at']);
                if ($venta === null) {
                    $stats['venta_no_migrada']++;

                    continue;
                }
                if (Cobro::where('venta_id', $venta->id)->exists()) {
                    $stats['ya_tenian_cobros']++;

                    continue;
                }

                $medio = $c['medios_cobro'][0] ?? null;
                if ($medio === null) {
                    $stats['sin_medio']++;
                }

                $stats['cobros_creados']++;
                $monto += $c['cobrado'];

                if ($dryRun) {
                    continue;
                }

                DB::transaction(function () use ($venta, $c, $medio) {
                    $cobro = new Cobro([
                        'venta_id' => $venta->id,
                        'fecha' => $c['fecha_emision'],
                        'cuenta_tesoreria_id' => $this->cuentas->resolver($medio),
                        'monto' => $c['cobrado'],
                        'nota' => 'Migrado de Contagram'.($medio ? " ({$medio})" : ''),
                    ]);
                    // Mismo criterio que las ventas: el cobro ocurrió entonces, no hoy. Si no, los
                    // listados de cobranzas ordenados por creación muestran seis años de golpe.
                    $cobro->created_at = $venta->created_at;
                    $cobro->updated_at = $venta->created_at;
                    $cobro->save();
                });
            }
            $barra->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], collect($stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());
        $this->table(['Importe', 'Migrado', 'Control (plan §6)'],
            [['Cobrado', number_format($monto, 2), '1.506.014.720,12']]);

        if ($dryRun) {
            $this->info('DRY RUN: no se escribió nada.');
        }

        return self::SUCCESS;
    }

}
