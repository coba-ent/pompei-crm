<?php

namespace App\Console\Commands;

use App\Models\Cobro;
use App\Models\MovimientoTesoreria;
use App\Models\Venta;
use App\Services\Migracion\CuentasDeTesoreria;
use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige los cobros migrados usando el informe de Movimientos de Cuenta Corriente.
 *
 * La migración creó **un cobro por venta**, con la fecha de emisión de la venta y con la primera
 * cuenta de la lista de medios, porque el export de ventas traía el total cobrado pero no cuándo ni
 * cómo se cobró. El saldo de cada cliente quedó bien, pero:
 *
 * - cualquier corte por fecha —aging, saldo a una fecha, evolución mensual— da distinto;
 * - los saldos **por cuenta de tesorería** quedaron mal cuando la venta se cobró por otro medio.
 *
 * El informe de cuenta corriente sí trae la fecha y el medio de cada movimiento, así que se puede
 * reparar. Corrige el cobro **y su movimiento de tesorería**, que nacen juntos y con los mismos
 * datos por diseño (`Cobranzas::registrar()`): corregir uno sin el otro los desalinea.
 *
 * No toca importes ni ventas. Cuando hay más de un cobro de un lado o del otro, no se puede saber
 * qué fila del informe corresponde a cuál, así que se saltea y se reporta.
 */
class CorregirFechasCobrosContagram extends Command
{
    protected $signature = 'migracion:corregir-fechas-cobros
        {--dry-run : No escribe nada; sólo reporta qué cambiaría}
        {--solo-fechas : Corrige la fecha pero no la cuenta de tesorería}
        {--archivo= : Ruta del informe (por defecto, el de public/imports/cobros)}';

    protected $description = 'Corrige fecha y cuenta de los cobros migrados con el informe de Cuenta Corriente';

    private const ANIOS = ['2021', '2022', '2023', '2024', '2025', '2026'];

    public function handle(LectorExcelContagram $lector): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $archivo = $this->option('archivo') ?: $this->archivoPorDefecto();
        if ($archivo === null || ! is_file($archivo)) {
            $this->error('No se encontró el informe de Cuenta Corriente en public/imports/cobros.');

            return self::FAILURE;
        }

        $soloFechas = (bool) $this->option('solo-fechas');
        $cuentas = new CuentasDeTesoreria();

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— CORRIGIENDO COBROS —');
        $this->line('Informe: '.basename($archivo));
        $this->line($soloFechas ? 'Alcance: sólo fechas' : 'Alcance: fecha y cuenta de tesorería');

        // Fecha real de cada cobro, agrupada por el Id de venta de Contagram.
        $fechas = [];
        foreach ($lector->leer($archivo)['filas'] as $fila) {
            if (mb_strtolower($lector->texto($fila['Operación'] ?? null) ?? '') !== 'cobro') {
                continue;
            }

            $idVenta = $lector->texto($fila['Id Venta'] ?? null);
            $fecha = $lector->fecha($fila['Emisión'] ?? null)?->format('Y-m-d');

            if ($idVenta !== null && $fecha !== null) {
                $fechas[$idVenta][] = [
                    'fecha' => $fecha,
                    'medio' => $lector->texto($fila['Medio de Cobro'] ?? null),
                ];
            }
        }
        $this->line(sprintf('Cobros en el informe: %d ventas', count($fechas)));

        $stats = array_fill_keys([
            'fecha_corregida', 'cuenta_corregida', 'ya_estaban_bien', 'sin_venta',
            'varios_cobros', 'cuenta_no_resuelta', 'movimientos_corregidos',
        ], 0);
        $ejemplos = [];
        $movidoEntreCuentas = [];   // nombre de cuenta => saldo que entra o sale

        $barra = $this->output->createProgressBar(count($fechas));

        foreach ($fechas as $idVenta => $delInforme) {
            $barra->advance();

            $venta = $this->ventaPorIdDeContagram((string) $idVenta);
            if ($venta === null) {
                $stats['sin_venta']++;

                continue;
            }

            $cobros = Cobro::where('venta_id', $venta->id)->orderBy('id')->get();

            // Con más de un cobro de un lado o del otro no se puede saber qué fecha va con cuál:
            // se saltean y se reportan, en vez de asignar a ciegas.
            if ($cobros->count() !== 1 || count($delInforme) !== 1) {
                $stats['varios_cobros']++;

                continue;
            }

            $cobro = $cobros->first();
            $fechaCorrecta = $delInforme[0]['fecha'];
            $medio = $delInforme[0]['medio'];

            $cambios = [];

            if ($cobro->fecha?->format('Y-m-d') !== $fechaCorrecta) {
                $cambios['fecha'] = $fechaCorrecta;
                $stats['fecha_corregida']++;
            }

            if (! $soloFechas && $medio !== null && trim($medio) !== '') {
                $cuentaId = $cuentas->resolver($medio);

                if ($cuentaId === null) {
                    $stats['cuenta_no_resuelta']++;
                } elseif ((int) $cobro->cuenta_tesoreria_id !== (int) $cuentaId) {
                    $cambios['cuenta_tesoreria_id'] = $cuentaId;
                    $stats['cuenta_corregida']++;

                    // Se registra el desplazamiento para poder mostrar, al final, cuánto saldo se
                    // mueve de cada cuenta: es el número con el que se contrasta contra Contagram.
                    $monto = (float) $cobro->monto;
                    $origen = $cobro->cuentaTesoreria?->nombre ?? '(sin cuenta)';
                    $movidoEntreCuentas[$origen] = ($movidoEntreCuentas[$origen] ?? 0) - $monto;
                    $movidoEntreCuentas[$medio] = ($movidoEntreCuentas[$medio] ?? 0) + $monto;
                }
            }

            if ($cambios === []) {
                $stats['ya_estaban_bien']++;

                continue;
            }

            if (count($ejemplos) < 8) {
                $ejemplos[] = sprintf('%-16s %s', $venta->legacy_id, json_encode($cambios, JSON_UNESCAPED_UNICODE));
            }

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($cobro, $cambios, &$stats) {
                // `saveQuietly`: estos datos ya eran así en Contagram desde el día uno; no es un
                // cambio de negocio que deba disparar auditoría ni mover `updated_at`.
                $cobro->fill($cambios);
                $cobro->saveQuietly();

                // El movimiento de tesorería nace con la misma fecha y cuenta que el cobro
                // (`Cobranzas::registrar()`): corregir uno sin el otro los desalinea.
                $stats['movimientos_corregidos'] += MovimientoTesoreria::where('origen_type', Cobro::class)
                    ->where('origen_id', $cobro->id)
                    ->update(array_filter([
                        'fecha' => $cambios['fecha'] ?? null,
                        'cuenta_tesoreria_id' => $cambios['cuenta_tesoreria_id'] ?? null,
                    ]));
            });
        }

        $barra->finish();
        $this->newLine(2);

        $this->table(['Concepto', 'Cantidad'], collect($stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());

        if ($movidoEntreCuentas !== []) {
            $this->newLine();
            $this->line('Saldo que se mueve entre cuentas (contrastar contra Contagram):');
            arsort($movidoEntreCuentas);
            foreach ($movidoEntreCuentas as $cuenta => $delta) {
                if (abs($delta) > 0.005) {
                    $this->line(sprintf('   %-28s %+15s', mb_substr($cuenta, 0, 28), number_format($delta, 2)));
                }
            }
        }

        if ($ejemplos !== []) {
            $this->newLine();
            $this->line('Ejemplos:');
            foreach ($ejemplos as $e) {
                $this->line("   {$e}");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY RUN: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    /** El `legacy_id` es `{anio}-FC-{id}`; el informe sólo trae el id, así que se prueba por año. */
    private function ventaPorIdDeContagram(string $idVenta): ?Venta
    {
        foreach (self::ANIOS as $anio) {
            $venta = Venta::where('legacy_id', "{$anio}-FC-{$idVenta}")->first();
            if ($venta !== null) {
                return $venta;
            }
        }

        return null;
    }

    private function archivoPorDefecto(): ?string
    {
        $match = glob(public_path('imports/cobros/*.xlsx'));

        return $match === false || $match === [] ? null : $match[0];
    }
}
