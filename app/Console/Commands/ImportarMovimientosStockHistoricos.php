<?php

namespace App\Console\Commands;

use App\Services\Stock\FilaInformeStock;
use App\Services\Stock\LectorInformeStockContagram;
use App\Services\Stock\ResolvedorOperacionLegacy;
use App\Services\Stock\VerificadorCargaHistorica;
use App\Services\Stock\VerificadorSaldosContagram;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Carga el histórico de movimientos de stock de Contagram (spec 094).
 *
 * La migración del 13/08/2026 trajo ventas, compras y stock, pero no generó movimientos: hoy sólo
 * existen los que produjo el flujo normal del CRM desde esa fecha. Este comando reconstruye el
 * histórico de 2024-2026 desde los `Informe Stock AAAA.xlsx` SIN alterar el stock actual.
 *
 * La propiedad que lo hace seguro es estructural, no procedimental: el stock actual vive en la
 * tabla `stocks` y este comando sólo escribe en `movimientos_stock`. Encima de eso,
 * VerificadorCargaHistorica lo comprueba en cada corrida.
 */
class ImportarMovimientosStockHistoricos extends Command
{
    protected $signature = 'stock:importar-movimientos-historicos
        {directorio : Carpeta con los Informe Stock AAAA.xlsx}
        {--anios=2024,2025,2026 : Años a cargar, separados por coma}
        {--escribir : Escribe de verdad. Sin esto es dry-run}
        {--deshacer= : Revierte la corrida indicada y sale}
        {--verificar-saldos : Compara los deltas contra el Saldo Stock que traía Contagram}';

    protected $description = 'Carga el histórico de movimientos de stock desde los informes de Contagram (spec 094)';

    /**
     * Desde acá el CRM genera sus propios movimientos. Sólo aplica a las filas SIN operación: las
     * que tienen operación se cortan por "ya tiene movimiento", que es más seguro (ver corte()).
     */
    private const CORTE_MIGRACION = '2026-08-13';

    private const LOTE = 500;

    public function handle(
        LectorInformeStockContagram $lector,
        VerificadorCargaHistorica $verificador,
    ): int {
        if ($this->option('deshacer') !== null) {
            return $this->deshacer((int) $this->option('deshacer'));
        }

        $directorio = rtrim($this->argument('directorio'), '/\\');
        $anios = array_map('intval', explode(',', $this->option('anios')));
        $escribir = (bool) $this->option('escribir');

        $archivos = [];

        foreach ($anios as $anio) {
            $ruta = "{$directorio}/Informe Stock {$anio}.xlsx";

            if (! is_readable($ruta)) {
                $this->error("No se puede leer {$ruta}");

                return self::FAILURE;
            }

            $archivos[$anio] = $ruta;
        }

        $this->info($escribir ? '=== CORRIDA REAL ===' : '=== DRY-RUN (no escribe nada) ===');
        $this->newLine();

        $resolvedor = new ResolvedorOperacionLegacy;
        $yaConMovimiento = $this->operacionesConMovimiento();

        $stats = array_fill_keys([
            'leidas', 'cantidad_cero', 'reales', 'sin_producto',
            'con_operacion', 'sin_operacion', 'corte_operacion', 'corte_fecha', 'a_cargar',
        ], 0);

        $sinProducto = [];
        $porOperacion = [];
        $movimientos = [];
        $paraSaldos = [];
        $corrida = now()->format('YmdHis');

        foreach ($archivos as $anio => $ruta) {
            $this->line("Leyendo {$ruta}...");

            try {
                $leido = $lector->leer($ruta, $anio, (bool) $this->option('verificar-saldos'));
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            if ($this->option('verificar-saldos')) {
                // Por archivo: la cadena de saldos es continua dentro de cada export, no entre ellos.
                $paraSaldos[$anio] = $leido['cadena_saldos'];
            }

            $stats['leidas'] += $leido['leidas'];
            $stats['cantidad_cero'] += $leido['descartadas_cantidad_cero'];

            foreach ($leido['filas'] as $fila) {
                $stats['reales']++;

                $operacion = $resolvedor->operacion($fila);
                $producto = $resolvedor->producto($fila, $operacion);

                if ($producto === null) {
                    $stats['sin_producto']++;
                    $sinProducto[$fila->codigo] = ($sinProducto[$fila->codigo] ?? 0) + 1;

                    continue;
                }

                if ($operacion !== null) {
                    $stats['con_operacion']++;

                    if (isset($yaConMovimiento[$operacion[0].'#'.$operacion[1]])) {
                        $stats['corte_operacion']++;

                        continue;
                    }
                } else {
                    $stats['sin_operacion']++;

                    if ($fila->fecha->toDateString() >= self::CORTE_MIGRACION) {
                        $stats['corte_fecha']++;

                        continue;
                    }
                }

                try {
                    $movimientos[] = $this->armar($fila, $producto, $operacion, $corrida);
                } catch (Throwable $e) {
                    // Una operación fuera del mapeo significa que el export cambió. Se corta acá
                    // con un mensaje legible en vez de dejar escapar la excepción: el que corra
                    // esto tiene que poder leer qué pasó sin interpretar un stack trace.
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                $stats['a_cargar']++;
                $porOperacion[$fila->operacion] = ($porOperacion[$fila->operacion] ?? 0) + 1;
            }

            unset($leido);
        }

        $this->tabla($stats, $porOperacion, $sinProducto);

        if ($this->option('verificar-saldos')) {
            foreach ($paraSaldos as $anio => $cadena) {
                $this->verificarSaldos($anio, $cadena);
            }
        }

        if (! $escribir) {
            $this->newLine();
            $this->comment('Dry-run: no se escribió nada. Agregá --escribir para la corrida real.');

            return self::SUCCESS;
        }

        return $this->escribir($movimientos, $verificador, $corrida);
    }

    /**
     * @param  array<int,array<string,mixed>>  $movimientos
     */
    private function escribir(array $movimientos, VerificadorCargaHistorica $verificador, string $corrida): int
    {
        $this->newLine();
        $this->line('Fotografiando el estado actual...');
        $verificador->fotografiar();

        // DB::transaction() se encarga de anidar: en producción abre la transacción real, y bajo
        // RefreshDatabase en los tests abre un SAVEPOINT dentro de la del test. En los dos casos,
        // una excepción del verificador revierte exactamente lo que este comando insertó — la
        // garantía de "todo o nada" no depende del contexto.
        try {
            DB::transaction(function () use ($movimientos, $verificador) {
                $barra = $this->output->createProgressBar(count($movimientos));
                $barra->start();

                foreach (array_chunk($movimientos, self::LOTE) as $lote) {
                    // Query builder, NO Eloquent. Con el modelo, cada created dispararía
                    // MovimientoStockObserver (marcaría las 270 publicaciones de ML y las 85
                    // variantes de Tiendanube como pendientes, y el cron publicaría stock histórico
                    // en las dos plataformas) y MovimientoStockAuditoriaObserver (30.716 eventos de
                    // auditoría). No se silencian los eventos: este camino de código no los tiene.
                    DB::table('movimientos_stock')->insert($lote);
                    $barra->advance(count($lote));
                }

                $barra->finish();
                $this->newLine(2);

                $this->line('Verificando que nada más haya cambiado...');
                $verificador->verificar();
            });
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('SE REVIRTIÓ TODO. Motivo:');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('Listo: %d movimientos cargados. Corrida %s.', count($movimientos), $corrida));
        $this->comment("Para revertir: php artisan stock:importar-movimientos-historicos . --deshacer={$corrida}");

        return self::SUCCESS;
    }

    /**
     * FR-016: contrasta la carga contra el saldo que traía el propio Contagram.
     *
     * @param  array<int, array{cantidad: float, saldo: float|null, fila: int, operacion: string, codigo: string}>  $cadena
     */
    private function verificarSaldos(int $anio, array $cadena): void
    {
        $resultado = (new VerificadorSaldosContagram)->verificar($cadena);

        $this->newLine();
        $this->line(sprintf(
            "Saldos de {$anio} contra Contagram: %s pares comparados, %s discrepancias.",
            number_format($resultado['comparados']),
            number_format(count($resultado['discrepancias']))
        ));

        foreach (array_slice($resultado['discrepancias'], 0, 10) as $d) {
            $this->warn(sprintf(
                '  fila %d — %s (%s): se leyó %s pero el saldo saltó %s',
                $d['fila'], $d['codigo'], $d['operacion'], $d['cantidad_leida'], $d['delta_del_saldo']
            ));
        }
    }

    private function deshacer(int $corrida): int
    {
        $cuantos = DB::table('movimientos_stock')->where('carga_historica_id', $corrida)->count();

        if ($cuantos === 0) {
            $this->error("No hay movimientos de la corrida {$corrida}.");

            return self::FAILURE;
        }

        if (! $this->confirm("Se van a borrar {$cuantos} movimientos de la corrida {$corrida}. ¿Seguir?")) {
            return self::SUCCESS;
        }

        DB::table('movimientos_stock')->where('carga_historica_id', $corrida)->delete();

        $this->info("Borrados {$cuantos} movimientos de la corrida {$corrida}.");

        return self::SUCCESS;
    }

    /**
     * @param  array{0: class-string, 1: int}|null  $operacion
     * @return array<string,mixed>
     */
    private function armar(FilaInformeStock $fila, int $producto, ?array $operacion, string $corrida): array
    {
        $ahora = CarbonImmutable::now();

        return [
            'producto_id' => $producto,
            'variante_id' => null,
            'deposito_id' => $this->deposito($fila),
            'tipo' => $this->tipo($fila),
            'cantidad' => $fila->cantidad,
            'descripcion' => $fila->textoDescripcion(),
            'origen_type' => $operacion[0] ?? null,
            'origen_id' => $operacion[1] ?? null,
            'fecha' => $fila->fecha->toDateTimeString(),
            // usuario_id queda NULL a propósito (FR-023): los usuarios del Excel son de Contagram,
            // no del CRM, y atribuir una operación a la persona equivocada es peor que no tener el
            // dato. El nombre viaja en la descripción.
            'usuario_id' => null,
            'carga_historica_id' => $corrida,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    /**
     * Local => 5, Full => 6.
     *
     * El "Depósito Tiendanube" del Excel no existe en el CRM. Casi todas sus filas se descartan por
     * cantidad 0; las que sobreviven se imputan a Local, que es el depósito desde el que el CRM
     * atiende Tiendanube hoy. No se crea un depósito nuevo para alojar un puñado de movimientos
     * históricos: aparecería en todos los selectores del CRM a cambio de nada.
     */
    private function deposito(FilaInformeStock $fila): int
    {
        return str_contains($fila->deposito, 'Full') ? 6 : 5;
    }

    /**
     * Las ventas y compras son entrada/salida según el signo; todo lo demás es ajuste.
     *
     * Una operación desconocida NO cae en un default: significa que el export cambió, y adivinar su
     * tipo es exactamente lo que no hay que hacer.
     */
    private function tipo(FilaInformeStock $fila): string
    {
        if ($fila->esDeVenta() || $fila->esDeCompra()) {
            return $fila->cantidad > 0 ? 'entrada' : 'salida';
        }

        $ajustes = ['Aumento', 'Disminución', 'Importación', 'Sincronización', 'Registro Inicial'];

        foreach ($ajustes as $ajuste) {
            if (str_contains($fila->operacion, $ajuste)) {
                return 'ajuste';
            }
        }

        throw new \RuntimeException(
            "Operación desconocida en la fila {$fila->fila} de {$fila->anio}: \"{$fila->operacion}\". ".
            'El export cambió; revisar el mapeo antes de seguir.'
        );
    }

    /** @return array<string,bool> */
    private function operacionesConMovimiento(): array
    {
        $set = [];

        $filas = DB::table('movimientos_stock')
            ->whereNotNull('origen_type')
            ->select('origen_type', 'origen_id')
            ->distinct()
            ->get();

        foreach ($filas as $fila) {
            $set[$fila->origen_type.'#'.$fila->origen_id] = true;
        }

        return $set;
    }

    /**
     * @param  array<string,int>  $stats
     * @param  array<string,int>  $porOperacion
     * @param  array<string,int>  $sinProducto
     */
    private function tabla(array $stats, array $porOperacion, array $sinProducto): void
    {
        $this->newLine();
        $this->table(['Etapa', 'Filas'], [
            ['Leídas de los Excel', number_format($stats['leidas'])],
            ['Descartadas por cantidad 0 (réplica por depósito)', number_format($stats['cantidad_cero'])],
            ['Movimientos reales', number_format($stats['reales'])],
            ['Sin producto en el CRM (se saltean)', number_format($stats['sin_producto'])],
            ['Con operación matcheada', number_format($stats['con_operacion'])],
            ['Sin operación (ajustes)', number_format($stats['sin_operacion'])],
            ['Salteadas: la operación ya tiene movimiento', number_format($stats['corte_operacion'])],
            ['Salteadas: sin ID y posteriores al corte', number_format($stats['corte_fecha'])],
            ['A CARGAR', number_format($stats['a_cargar'])],
        ]);

        arsort($porOperacion);
        $this->newLine();
        $this->line('Por operación:');

        foreach ($porOperacion as $operacion => $cuantos) {
            $this->line(sprintf('  %-42s %s', $operacion, number_format($cuantos)));
        }

        if ($sinProducto !== []) {
            arsort($sinProducto);
            $this->newLine();
            $this->warn('Códigos sin producto en el CRM (top 10):');

            foreach (array_slice($sinProducto, 0, 10, true) as $codigo => $cuantos) {
                $this->line(sprintf('  %-46s %d', $codigo === '' ? '(vacío)' : $codigo, $cuantos));
            }
        }
    }
}
