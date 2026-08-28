<?php

namespace App\Console\Commands;

use App\Services\Migracion\ComprobantesContagram;
use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca las ventas/notas migradas que en Contagram no tenían comprobante fiscal emitido, para que
 * el Libro IVA Ventas las excluya igual que Contagram.
 *
 * ## El criterio (medido, no supuesto)
 *
 * En el export de Contagram, el campo `Tipo de Comprobante` trae la familia **con** letra fiscal
 * (`FCA`, `FCB`, `NCB`…) o **sin** ella (`FC`, `NC`, `ND`, vacío). Los que no tienen letra son
 * ventas sin factura emitida, y coinciden **exactamente** con `ARCA = '---'`: medido sobre Junio
 * 2026, los 183 sin letra son los 183 con `ARCA='---'`, y los 468 con letra son exactamente los 468
 * que Contagram muestra en su Libro IVA. {@see ComprobantesContagram} ya expone ese dato como
 * `$c['tipo']` (null cuando no hay letra).
 *
 * ## Por qué hace falta un flag
 *
 * `migracion:ventas` les asignó `tipo_comprobante = 'B'` igual, así que en la base son
 * indistinguibles de una Factura B real. `nro_comprobante` tampoco alcanza: de una muestra de 500
 * sin letra ninguna tiene número, pero 128 de 490 **con** letra tampoco — filtrar por eso se llevaría
 * puestas facturas reales.
 *
 * No toca `tipo_comprobante` (la leen 141 lugares del sistema que asumen que siempre hay letra):
 * sólo escribe la columna `sin_comprobante_fiscal`, que nadie más consulta.
 */
class MarcarSinComprobanteFiscalContagram extends Command
{
    protected $signature = 'migracion:marcar-sin-comprobante-fiscal
        {--aplicar : Escribe la marca. Sin este flag sólo informa qué haría.}';

    protected $description = 'Marca las ventas/notas migradas sin comprobante fiscal, para excluirlas del Libro IVA como hace Contagram';

    public function handle(LectorExcelContagram $lector): int
    {
        $aplicar = (bool) $this->option('aplicar');

        if (! $aplicar) {
            $this->warn('Modo simulación: no se escribe nada. Usá --aplicar para guardar (con backup hecho).');
        }

        $servicio = new ComprobantesContagram($lector, public_path('imports'));
        // `Ventas 2023.xlsx` no trae encabezado — mismo criterio que `migracion:ventas` (§3.3).
        $headerCanonico = $lector->leer(public_path('imports').'/Ventas/Ventas 2022.xlsx')['header'];

        $sinLetra = ['FC' => [], 'NC' => [], 'ND' => []];
        $conLetra = 0;

        foreach (ComprobantesContagram::ANIOS as $anio) {
            $this->line("Leyendo {$anio}…");
            foreach ($servicio->delAnio($anio, $anio === '2023' ? $headerCanonico : null) as $legacyId => $c) {
                if ($c['tipo'] !== null) {
                    $conLetra++;

                    continue;
                }
                $sinLetra[$c['familia']][] = $legacyId;
            }
        }

        $legacyVentas = $sinLetra['FC'];
        $legacyNotas = array_merge($sinLetra['NC'], $sinLetra['ND']);

        $this->newLine();
        $this->line('Con letra fiscal (van al Libro IVA):  '.$conLetra);
        $this->line('Sin letra fiscal (NO van):            '.(count($legacyVentas) + count($legacyNotas)));

        $ventas = $this->contarYSumar('ventas', $legacyVentas, 'total');
        $notas = $this->contarYSumar('notas_credito_debito', $legacyNotas, 'monto');

        $this->newLine();
        $this->table(['', 'Filas a marcar', 'Importe'], [
            ['Ventas', $ventas['cant'], '$'.number_format($ventas['suma'], 2)],
            ['Notas', $notas['cant'], '$'.number_format($notas['suma'], 2)],
        ]);

        if (! $aplicar) {
            $this->newLine();
            $this->warn('No se escribió nada. Repetí con --aplicar (con backup de la base hecho).');

            return self::SUCCESS;
        }

        // Se reescribe la marca entera (true a los del Excel, false al resto de los migrados) para
        // que el comando sea idempotente y reversible corriéndolo de nuevo tras corregir el origen.
        DB::transaction(function () use ($legacyVentas, $legacyNotas) {
            DB::table('ventas')->whereNotNull('legacy_id')->update(['sin_comprobante_fiscal' => false]);
            DB::table('notas_credito_debito')->whereNotNull('legacy_id')->update(['sin_comprobante_fiscal' => false]);

            foreach (array_chunk($legacyVentas, 1000) as $lote) {
                DB::table('ventas')->whereIn('legacy_id', $lote)->update(['sin_comprobante_fiscal' => true]);
            }
            foreach (array_chunk($legacyNotas, 1000) as $lote) {
                DB::table('notas_credito_debito')->whereIn('legacy_id', $lote)->update(['sin_comprobante_fiscal' => true]);
            }
        });

        $this->newLine();
        $this->info('Listo: '.($ventas['cant'] + $notas['cant']).' comprobantes marcados como sin comprobante fiscal.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $legacyIds
     * @return array{cant: int, suma: float}
     */
    private function contarYSumar(string $tabla, array $legacyIds, string $columnaImporte): array
    {
        $cant = 0;
        $suma = 0.0;

        foreach (array_chunk($legacyIds, 1000) as $lote) {
            $r = DB::table($tabla)->whereNull('deleted_at')->whereIn('legacy_id', $lote)
                ->selectRaw("count(*) as c, COALESCE(sum({$columnaImporte}), 0) as s")->first();
            $cant += (int) $r->c;
            $suma += (float) $r->s;
        }

        return ['cant' => $cant, 'suma' => $suma];
    }
}
