<?php

namespace App\Console\Commands;

use App\Models\Deposito;
use App\Models\Producto;
use App\Services\Migracion\LectorExcelContagram;
use App\Services\Stock\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ajusta el stock del CRM contra una hoja de conteo real, depósito por depósito.
 *
 * La migración del histórico se hizo **sin mover inventario**, para que el stock contado quedara
 * intacto. Eso funcionó para los productos que ya existían con su cantidad cargada, pero dejó
 * desviaciones en los que se dieron de alta poco antes: arrancaron en cero o con un número parcial
 * y las ventas posteriores los llevaron a negativo.
 *
 * Este comando cierra esa brecha con el conteo real, usando `StockService::ajustar()` — el mismo
 * mecanismo que un ajuste manual— para que cada corrección quede como un movimiento trazable y no
 * como una columna pisada en silencio.
 *
 * Reglas, acordadas con el usuario:
 *
 * - **No crea productos.** Los que están en la hoja y no en el CRM se ignoran: el catálogo de la
 *   plataforma es el que vale.
 * - **No toca los que no están en la hoja.** Sin dato de conteo no hay nada que corregir.
 * - **No toca servicios**, que no llevan inventario.
 * - Sólo ajusta los depósitos que se le indiquen (por defecto Local y Full).
 */
class AjustarStockDesdeHoja extends Command
{
    protected $signature = 'stock:ajustar-desde-hoja
        {archivo : Ruta del Excel con el conteo}
        {--dry-run : No escribe nada; sólo reporta qué cambiaría}
        {--depositos=Local,Full : Columnas de la hoja a ajustar, separadas por coma}';

    protected $description = 'Ajusta el stock contra una hoja de conteo real, con movimientos trazables';

    public function handle(LectorExcelContagram $lector, StockService $stock): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $archivo = $this->argument('archivo');

        if (! is_file($archivo)) {
            $this->error("No se encontró el archivo: {$archivo}");

            return self::FAILURE;
        }

        // La hoja nombra los depósitos por su columna; el CRM, por su nombre de depósito.
        $columnas = array_map('trim', explode(',', (string) $this->option('depositos')));
        $depositos = [];
        foreach ($columnas as $columna) {
            $deposito = Deposito::where('nombre', $columna)->first();
            if (! $deposito) {
                $this->error("No existe un depósito llamado «{$columna}» en el CRM.");

                return self::FAILURE;
            }
            $depositos[$columna] = $deposito;
        }

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— AJUSTANDO STOCK —');
        $this->line('Hoja      : '.basename($archivo));
        $this->line('Depósitos : '.implode(', ', $columnas));
        $this->newLine();

        $productos = Producto::pluck('id')->flip();
        $stockActual = [];
        foreach (DB::table('stocks')->select('producto_id', 'deposito_id', 'cantidad')->get() as $s) {
            $stockActual[$s->producto_id][$s->deposito_id] = (float) $s->cantidad;
        }

        $stats = array_fill_keys(['ajustados', 'movimientos', 'sin_cambios', 'no_en_crm', 'servicios'], 0);
        $porDeposito = [];
        $ejemplos = [];

        $filas = $lector->leer($archivo)['filas'];
        $barra = $this->output->createProgressBar(count($filas));

        foreach ($filas as $fila) {
            $barra->advance();

            $id = $lector->texto($fila['Id'] ?? null);
            if ($id === null) {
                continue;
            }

            if (mb_strtolower($lector->texto($fila['Tipo'] ?? null) ?? '') === 'servicio') {
                $stats['servicios']++;

                continue;
            }

            if (! $productos->has((int) $id)) {
                $stats['no_en_crm']++;

                continue;
            }

            $hubo = false;

            foreach ($depositos as $columna => $deposito) {
                $contado = (float) ($lector->numero($fila[$columna] ?? null) ?? 0);
                $enCrm = $stockActual[(int) $id][$deposito->id] ?? 0.0;
                $diferencia = round($contado - $enCrm, 3);

                if (abs($diferencia) < 0.005) {
                    continue;
                }

                $hubo = true;
                $porDeposito[$columna]['productos'] = ($porDeposito[$columna]['productos'] ?? 0) + 1;
                $porDeposito[$columna]['unidades'] = ($porDeposito[$columna]['unidades'] ?? 0) + $diferencia;

                if (count($ejemplos) < 10) {
                    $ejemplos[] = sprintf('%-7s %-34s %-6s %6s → %6s (%+s)',
                        $id, mb_substr($lector->texto($fila['Nombre'] ?? null) ?? '', 0, 34), $columna,
                        number_format($enCrm, 0), number_format($contado, 0), number_format($diferencia, 0));
                }

                if ($dryRun) {
                    continue;
                }

                $stock->ajustar(
                    producto: Producto::find((int) $id),
                    variante: null,
                    deposito: $deposito,
                    cantidadConSigno: $diferencia,
                    descripcion: 'Ajuste por conteo real — '.basename($archivo),
                );
                $stats['movimientos']++;
            }

            $stats[$hubo ? 'ajustados' : 'sin_cambios']++;
        }

        $barra->finish();
        $this->newLine(2);

        $this->table(['Concepto', 'Cantidad'], collect($stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());

        $this->newLine();
        $this->line('Por depósito:');
        foreach ($porDeposito as $columna => $d) {
            $this->line(sprintf('   %-8s %4d productos   %+8s unidades',
                $columna, $d['productos'], number_format($d['unidades'], 0)));
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
}
