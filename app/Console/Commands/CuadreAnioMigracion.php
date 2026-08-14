<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Compara un año ya importado contra el Excel de origen. Es el control de aceptación de la
 * migración a la base nueva: hasta que un año no cuadra, no se importa el siguiente
 * (ver migracion-nueva/CHECKLIST_CUADRE.md).
 *
 * El criterio de fondo es el que atrapó todos los errores del import anterior: **la verificación
 * tiene que ser independiente del importador**. Por eso las cifras salen de leer el Excel de nuevo
 * acá, y no de lo que el import haya reportado al correr.
 */
class CuadreAnioMigracion extends Command
{
    protected $signature = 'migracion:cuadre
        {--anio= : Año a verificar}
        {--extra=* : Informes extra con el resumen del tramo final (2026)}';

    protected $description = 'Compara un año importado contra el Excel de origen (totales, cantidades y cobros)';

    private const TOLERANCIA = 0.005;

    public function handle(): int
    {
        $anio = $this->option('anio');

        if (! $anio) {
            $this->error('Falta --anio');

            return self::FAILURE;
        }

        $excel = $this->leerExcel($anio);

        if ($excel === null) {
            return self::FAILURE;
        }

        $this->info("— CUADRE {$anio} —");
        $this->comprobantes($anio, $excel);
        $this->importes($anio, $excel);
        $this->cobros($anio);
        $this->notas($anio, $excel);

        return self::SUCCESS;
    }

    /** Lee el `Ventas c- cobro` del año: una fila por comprobante, con los totales de control. */
    private function leerExcel(string $anio): ?array
    {
        $archivo = base_path("migracion-nueva/excel-origen/Ventas c- cobro/{$anio} Ventas c_ cobro.xlsx");

        if (! is_file($archivo)) {
            $this->error("No está {$archivo}");

            return null;
        }

        $datos = ['ventas' => 0, 'total' => 0.0, 'cobrado' => 0.0, 'nc' => 0.0, 'nd' => 0.0,
            'ids' => [], 'con_nota' => []];

        $this->acumular($archivo, $datos);

        // El export por año de 2026 corta el 05/08; los últimos días llegaron en informes aparte,
        // que hay que sumar acá para que la comparación sea contra el mismo universo que se importó.
        foreach ((array) $this->option('extra') as $patron) {
            foreach (glob($patron) ?: (is_file($patron) ? [$patron] : []) as $extra) {
                $this->acumular($extra, $datos, soloVentas: true);
            }
        }

        return $datos;
    }

    /**
     * Suma un archivo al acumulado. Con `$soloVentas` se lee el informe de Movimientos de Clientes,
     * donde cada fila es una operación y sólo interesan las de tipo `Venta`.
     */
    private function acumular(string $archivo, array &$datos, bool $soloVentas = false): void
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $hoja = $reader->load($archivo)->getActiveSheet();
        $ultima = $hoja->getHighestRow();

        $encabezado = [];
        foreach ($hoja->getRowIterator(1, 1) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $v = $celda->getValue();
                $encabezado[] = is_scalar($v) ? (string) $v : '';
            }
        }

        $col = [];
        foreach ($encabezado as $pos => $nombre) {
            if ($nombre !== '' && ! isset($col[$nombre])) {
                $col[$nombre] = $pos;
            }
        }

        if (! isset($col['Id'], $col['Total Venta'])) {
            return;
        }

        for ($r = 2; $r <= $ultima; $r++) {
            if ($soloVentas) {
                $op = (string) $hoja->getCellByColumnAndRow(($col['Operación'] ?? 0) + 1, $r)->getValue();

                if ($op !== 'Venta') {
                    continue;
                }
            }

            $id = $hoja->getCellByColumnAndRow($col['Id'] + 1, $r)->getValue();

            if (! is_numeric($id)) {
                continue;
            }

            // Los informes del tramo final se exportaron por rangos que se solapan, así que el
            // mismo comprobante aparece en dos archivos. Sin esto el total se cuenta dos veces.
            if (isset($datos['ids'][(int) $id])) {
                continue;
            }

            // El informe de Movimientos no trae `Total NC`/`Total ND` (es una fila por operación,
            // no por comprobante): esas notas se cuentan por su propia fila.
            $nc = isset($col['Total NC'])
                ? abs((float) $hoja->getCellByColumnAndRow($col['Total NC'] + 1, $r)->getValue()) : 0.0;
            $nd = isset($col['Total ND'])
                ? abs((float) $hoja->getCellByColumnAndRow($col['Total ND'] + 1, $r)->getValue()) : 0.0;

            $datos['ventas']++;
            $datos['ids'][(int) $id] = true;
            $datos['total'] += (float) $hoja->getCellByColumnAndRow($col['Total Venta'] + 1, $r)->getValue();
            $datos['cobrado'] += (float) $hoja->getCellByColumnAndRow($col['Cobrado'] + 1, $r)->getValue();
            $datos['nc'] += $nc;
            $datos['nd'] += $nd;

            if ($nc > self::TOLERANCIA || $nd > self::TOLERANCIA) {
                $datos['con_nota'][(int) $id] = ['nc' => $nc, 'nd' => $nd];
            }
        }
    }

    private function comprobantes(string $anio, array $excel): void
    {
        $enBase = DB::table('ventas')->whereNull('deleted_at')
            ->whereYear('fecha_emision', $anio)->count();

        $faltantes = array_diff(array_keys($excel['ids']),
            DB::table('ventas')->whereNull('deleted_at')->whereYear('fecha_emision', $anio)->pluck('id')->all());

        $this->newLine();
        $this->table(['Comprobantes', 'Excel', 'Base', 'Diferencia'], [[
            'Ventas', number_format($excel['ventas']), number_format($enBase),
            $this->marca($excel['ventas'] - $enBase),
        ]]);

        if ($faltantes !== []) {
            $this->warn('  Ids del Excel que no están en la base ('.count($faltantes).'): '
                .implode(', ', array_slice($faltantes, 0, 15)));
        }
    }

    private function importes(string $anio, array $excel): void
    {
        $base = DB::table('ventas')->whereNull('deleted_at')->whereYear('fecha_emision', $anio)
            ->selectRaw('COALESCE(SUM(total),0) total')->first();

        // Sólo las notas **de venta**: `notas_credito_debito` guarda también las de compra, y
        // sumarlas acá hacía aparecer una diferencia de cientos de miles que no existía.
        $notas = DB::table('notas_credito_debito')->whereNull('deleted_at')
            ->whereYear('fecha_emision', $anio)
            ->whereNull('compra_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo='credito' THEN monto END),0) nc")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo='debito' THEN monto END),0) nd")
            ->first();

        $this->newLine();
        $this->table(['Importe', 'Excel', 'Base', 'Diferencia'], [
            $this->fila('Facturado', $excel['total'], (float) $base->total),
            $this->fila('NC de venta emitidas en el año', abs($excel['nc']), (float) $notas->nc),
            $this->fila('ND de venta emitidas en el año', abs($excel['nd']), (float) $notas->nd),
        ]);
        $this->comment('  Las dos filas de notas comparan cosas distintas a propósito: el Excel dice cuánta');
        $this->comment('  nota recibió una venta del año, y la base cuánta se emitió en el año. Cuadran por');
        $this->comment('  venta más abajo, que es la comparación que vale.');
    }

    /**
     * El `Cobrado` del Excel es el total cobrado de las ventas **de ese año**, sin importar cuándo
     * se cobró; los cobros de la base están fechados por su fecha real. Por eso se comparan las dos
     * lecturas: por venta (tiene que dar igual) y por fecha de cobro (difiere legítimamente cuando
     * una venta de diciembre se cobra en enero, o cuando hay una seña de un año anterior).
     */
    private function cobros(string $anio): void
    {
        $porVenta = DB::table('cobros as c')
            ->join('ventas as v', 'v.id', '=', 'c.venta_id')
            ->whereNull('c.deleted_at')->whereNull('v.deleted_at')
            ->whereYear('v.fecha_emision', $anio)
            ->selectRaw('COUNT(*) n, COALESCE(SUM(c.monto),0) monto')->first();

        $porFecha = DB::table('cobros')->whereNull('deleted_at')
            ->whereYear('fecha', $anio)
            ->selectRaw('COUNT(*) n, COALESCE(SUM(monto),0) monto')->first();

        $this->newLine();
        $this->table(['Cobros', 'Cantidad', 'Monto'], [
            ['De ventas de '.$anio.' (cobradas en cualquier fecha)', number_format($porVenta->n), number_format((float) $porVenta->monto, 2)],
            ['Cobrados en '.$anio.' (de ventas de cualquier año)', number_format($porFecha->n), number_format((float) $porFecha->monto, 2)],
        ]);
    }

    /**
     * Las NC/ND se comparan **por la venta que corrigen**, no por su año de emisión.
     *
     * La columna `Total NC` del Excel dice cuánta nota recibió esa venta sin importar cuándo se
     * emitió, y una venta de diciembre suele recibir su nota en enero del año siguiente. Comparar
     * "notas emitidas en el año" contra "notas aplicadas a ventas del año" da una diferencia que
     * parece un faltante y no lo es: se cierra sola al importar los años que siguen.
     */
    private function notas(string $anio, array $excel): void
    {
        $sueltas = DB::table('notas_credito_debito')->whereNull('deleted_at')
            ->whereYear('fecha_emision', $anio)
            ->whereNull('venta_id')->whereNull('compra_id')->count();

        $deVenta = DB::table('notas_credito_debito')->whereNull('deleted_at')
            ->whereYear('fecha_emision', $anio)->whereNotNull('venta_id')->count();

        $deCompra = DB::table('notas_credito_debito')->whereNull('deleted_at')
            ->whereYear('fecha_emision', $anio)->whereNotNull('compra_id')->count();

        $this->newLine();
        $this->line("Notas emitidas en {$anio}: {$deVenta} de venta + {$deCompra} de compra, "
            ."{$sueltas} sin comprobante asociado"
            .($sueltas === 0 ? ' ✔' : ' ← inflan la Cta Cte'));

        if ($excel['con_nota'] === []) {
            return;
        }

        $enBase = DB::table('notas_credito_debito')->whereNull('deleted_at')
            ->whereIn('venta_id', array_keys($excel['con_nota']))
            ->selectRaw('venta_id, tipo, SUM(monto) monto')
            ->groupBy('venta_id', 'tipo')->get()
            ->groupBy('venta_id');

        $pendNC = 0.0;
        $pendND = 0.0;
        $ventas = [];

        foreach ($excel['con_nota'] as $ventaId => $m) {
            $notas = $enBase->get($ventaId, collect());
            $bNC = (float) ($notas->firstWhere('tipo', 'credito')->monto ?? 0);
            $bND = (float) ($notas->firstWhere('tipo', 'debito')->monto ?? 0);

            if (abs($m['nc'] - $bNC) < self::TOLERANCIA && abs($m['nd'] - $bND) < self::TOLERANCIA) {
                continue;
            }

            $pendNC += $m['nc'] - $bNC;
            $pendND += $m['nd'] - $bND;
            $ventas[] = $ventaId;
        }

        if ($ventas === []) {
            $this->line("Notas aplicadas a ventas de {$anio}: coinciden exacto con el Excel ✔");

            return;
        }

        $this->line(sprintf(
            'Ventas de %s esperando una nota de un año posterior: %d (NC %s, ND %s)',
            $anio, count($ventas), number_format($pendNC, 2), number_format($pendND, 2)
        ));
        $this->line('  ids: '.implode(', ', array_slice($ventas, 0, 20)));
        $this->comment('  Se cierra al importar los años siguientes: la nota existe, todavía no se importó.');
    }

    private function fila(string $concepto, float $excel, float $base): array
    {
        return [$concepto, number_format($excel, 2), number_format($base, 2), $this->marca($excel - $base)];
    }

    private function marca(float $dif): string
    {
        return abs($dif) < self::TOLERANCIA ? '✔ 0' : number_format($dif, 2);
    }
}
