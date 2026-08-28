<?php

namespace App\Console\Commands;

use App\Models\ComprobanteFiscal;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Services\Migracion\ComprobantesContagram;
use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repone el `comprobantes_fiscales` "aprobado" de las ventas/notas migradas que en Contagram
 * habían sido emitidas electrónicamente ante ARCA.
 *
 * ## El problema que resuelve
 *
 * La migración histórica (`migracion:ventas`) trajo el tipo de comprobante como la letra sola
 * (`A`/`B`), pero Contagram distingue además el **canal**: `FEA`/`FEB` (electrónica, con CAE real)
 * vs `FMA`/`FMB` (manual), y ese dato no se persistió en ninguna columna del CRM. El Libro IVA
 * Ventas (spec 077) decide la partición "Aprobadas por ARCA" / "Manuales" mirando si la venta
 * tiene un `comprobantes_fiscales` con `estado = 'aprobado'` — y como ninguna migrada lo tiene,
 * **23.730 de 23.735 caen del lado "Manuales"** aunque muchas fueron electrónicas reales. Contra
 * Contagram eso da diferencias grandes en cualquier período histórico (medido el 28/08/2026:
 * Enero 2025 mostraba 437 filas / $38.9M en el CRM contra 382 / $32.4M en Contagram).
 *
 * ## De dónde sale el dato
 *
 * De la columna **`ARCA`** de los mismos Excel de origen que ya usa `migracion:ventas`
 * (`public/imports/Ventas/Ventas {año}.xlsx`), que `ComprobantesContagram` ya lee y expone como
 * `$c['arca']` sin que nadie la consumiera hasta ahora. Valores medidos en los 6 años:
 * `Aprobado` (electrónica aceptada por ARCA), `Sin Enviar`, `---` (sin comprobante fiscal, ver
 * {@see ComprobantesContagram} §3.11) y `Error` (rechazada). **Sólo `Aprobado` genera registro.**
 *
 * Se reusa `ComprobantesContagram` en vez de leer los Excel a mano a propósito: ya resuelve el
 * header ausente de `Ventas 2023.xlsx`, el agrupado por `Id`+familia y la normalización de campos.
 *
 * ## Qué NO hace
 *
 * - No inventa `cae` ni `cae_vencimiento`: el Excel no los trae, así que quedan `null`. El registro
 *   sirve para la partición del Libro IVA (que mira `estado` en SQL), pero **no** pasa
 *   {@see ComprobanteFiscal::aprobado()}, que exige CAE — así el QR fiscal de un PDF nunca va a
 *   mostrar un CAE inventado.
 * - No toca `ventas`, `notas_credito_debito` ni ningún importe: sólo inserta en
 *   `comprobantes_fiscales`. Reporte Final, stock, tesorería y cuenta corriente no leen esa tabla.
 * - No pisa un `comprobantes_fiscales` que ya exista para ese comprobante (idempotente).
 */
class BackfillComprobantesFiscalesContagram extends Command
{
    protected $signature = 'migracion:backfill-comprobantes-fiscales
        {--aplicar : Escribe los registros. Sin este flag sólo informa qué haría.}
        {--anio= : Procesar un solo año}';

    protected $description = 'Repone el comprobante fiscal aprobado de las ventas/notas migradas que fueron electrónicas en Contagram';

    /** Único valor de la columna `ARCA` que significa "aceptada por ARCA con CAE". */
    private const ARCA_APROBADO = 'Aprobado';

    public function handle(LectorExcelContagram $lector): int
    {
        $aplicar = (bool) $this->option('aplicar');

        if (! $aplicar) {
            $this->warn('Modo simulación: no se escribe nada. Usá --aplicar para guardar (con backup hecho).');
        }

        $servicio = new ComprobantesContagram($lector, public_path('imports'));
        $anios = $this->option('anio') ? [$this->option('anio')] : ComprobantesContagram::ANIOS;

        // El header canónico sale de 2022 porque `Ventas 2023.xlsx` no trae encabezado — mismo
        // criterio que `migracion:ventas` (plan técnico §3.3).
        $headerCanonico = $lector->leer(public_path('imports').'/Ventas/Ventas 2022.xlsx')['header'];

        $stats = array_fill_keys([
            'aprobados_en_excel', 'ya_tenian', 'creados', 'sin_comprobante_en_base', 'otros_estados',
        ], 0);
        $porAnio = [];
        $faltantes = [];

        foreach ($anios as $anio) {
            $this->line("Leyendo {$anio}…");
            $comprobantes = $servicio->delAnio($anio, $anio === '2023' ? $headerCanonico : null);

            $aCrear = [];
            $delAnio = ['aprobados' => 0, 'creados' => 0, 'ya_tenian' => 0, 'sin_base' => 0];

            foreach ($comprobantes as $legacyId => $c) {
                if (($c['arca'] ?? null) !== self::ARCA_APROBADO) {
                    $stats['otros_estados']++;

                    continue;
                }

                $stats['aprobados_en_excel']++;
                $delAnio['aprobados']++;

                [$modelo, $registro] = $this->resolverComprobante($legacyId, $c['familia']);

                if ($registro === null) {
                    $stats['sin_comprobante_en_base']++;
                    $delAnio['sin_base']++;
                    if (count($faltantes) < 20) {
                        $faltantes[] = $legacyId;
                    }

                    continue;
                }

                if ($this->yaTieneAprobado($modelo, $registro->id)) {
                    $stats['ya_tenian']++;
                    $delAnio['ya_tenian']++;

                    continue;
                }

                $aCrear[] = [
                    'comprobantable_type' => $modelo,
                    'comprobantable_id' => $registro->id,
                    'punto_venta_id' => null,
                    'tipo_comprobante' => $registro->tipo_comprobante ?: 'B',
                    'numero' => $registro->nro_comprobante ?: null,
                    'cae' => null,
                    'cae_vencimiento' => null,
                    'estado' => 'aprobado',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $stats['creados'] += count($aCrear);
            $delAnio['creados'] = count($aCrear);
            $porAnio[$anio] = $delAnio;

            if ($aplicar && $aCrear !== []) {
                DB::transaction(function () use ($aCrear) {
                    foreach (array_chunk($aCrear, 500) as $lote) {
                        DB::table('comprobantes_fiscales')->insert($lote);
                    }
                });
            }
        }

        $this->newLine();
        $this->table(
            ['Año', 'Aprobados en Excel', 'Ya tenían', 'Se crean', 'Sin comprobante en base'],
            collect($porAnio)->map(fn ($d, $anio) => [
                $anio, $d['aprobados'], $d['ya_tenian'], $d['creados'], $d['sin_base'],
            ])->values()->all(),
        );

        $this->line('Con otro estado ARCA (Sin Enviar / --- / Error), se ignoran: '.$stats['otros_estados']);

        if ($faltantes !== []) {
            $this->newLine();
            $this->warn('Aprobados en el Excel que no encontré en la base (primeros '.count($faltantes).'):');
            $this->line('  '.implode(', ', $faltantes));
        }

        $this->newLine();
        $aplicar
            ? $this->info("Listo: {$stats['creados']} comprobantes fiscales creados.")
            : $this->warn("No se escribió nada. Se crearían {$stats['creados']}. Repetí con --aplicar (con backup de la base hecho).");

        return self::SUCCESS;
    }

    /**
     * Ubica la venta o nota migrada por su `legacy_id`.
     *
     * @return array{0: class-string, 1: object|null}
     */
    private function resolverComprobante(string $legacyId, string $familia): array
    {
        if ($familia === 'FC') {
            return [Venta::class, Venta::where('legacy_id', $legacyId)->first(['id', 'tipo_comprobante', 'nro_comprobante'])];
        }

        return [NotaCreditoDebito::class, NotaCreditoDebito::where('legacy_id', $legacyId)->first(['id', 'tipo_comprobante', 'nro_comprobante'])];
    }

    /** Idempotencia: no duplica el comprobante fiscal de algo que ya lo tiene aprobado. */
    private function yaTieneAprobado(string $modelo, int $id): bool
    {
        return ComprobanteFiscal::where('comprobantable_type', $modelo)
            ->where('comprobantable_id', $id)
            ->where('estado', 'aprobado')
            ->whereNull('deleted_at')
            ->exists();
    }
}
