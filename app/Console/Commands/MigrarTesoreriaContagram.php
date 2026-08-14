<?php

namespace App\Console\Commands;

use App\Models\MovimientoTesoreria;
use App\Services\Migracion\ComprobantesContagram;
use App\Services\Migracion\CuentasDeTesoreria;
use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa los 48.222 movimientos de tesorería de Contagram (carpeta `Cuentas/`).
 *
 * **Por qué desde acá y no generándolos desde los cobros y pagos** (decisión del 10/08/2026):
 * `Cuentas/` es la única fuente completa. Contiene 10.458 movimientos entre cuentas —transferencias
 * internas— que no salen de ninguna venta ni compra; sin ellos el saldo no cierra jamás. Y sus
 * 25.086 "Cobro" son los mismos cobros ya importados: generarlos otra vez contaría cada peso dos
 * veces. Por eso `migracion:cobros` y `migracion:compras` crean cobros y pagos **sin** movimiento
 * de tesorería, y la tesorería entra completa por acá.
 *
 * **Control de aceptación**: cada archivo trae la columna `Saldo` con el saldo corriente, así que
 * el saldo final de cada cuenta es verificable contra Contagram. Verificado antes de importar:
 * cuadra en las 20 cuentas (en las 3 de tipo "a pagar", con el signo invertido, que es cómo
 * Contagram muestra un pasivo).
 *
 * Idempotente por `legacy_id` = `TES-{cuenta}-{operación}-{Id}-{centavos}`.
 */
class MigrarTesoreriaContagram extends Command
{
    protected $signature = 'migracion:tesoreria
        {--dry-run : No escribe nada; sólo reporta y verifica saldos}
        {--cuenta= : Procesar un solo archivo (ej. "Caja local")}
        {--excluir= : JSON con los legacy_id de ventas de ML ya cargadas en el CRM}
        {--dir=* : Carpeta(s) con los extractos (por defecto public/imports/Cuentas)}
        {--corte= : Fecha de corte (por defecto 2026-08-05)}
        {--fechas-directas : Los extractos traen la fecha bien y NO hay que invertir día/mes}';

    protected $description = 'Importa los movimientos de tesorería históricos de Contagram';

    /** Mismo corte que ventas y compras: del 06/08 en adelante el negocio ya operaba en el CRM. */
    private const CORTE = '2026-08-05';

    /** Corte efectivo; `--corte` lo corre al día del pase a producción en la base nueva. */
    private string $corte = self::CORTE;

    /** Operación de Contagram => `tipo` del enum de movimientos_tesoreria. */
    private const TIPOS = [
        'Cobro' => 'cobro',
        'Pago' => 'pago',
        'Gasto' => 'gasto',
        'Movimiento entre Cuenta' => 'movimiento_entre_cuentas',
        'Saldo Inicial' => 'saldo_inicial',
        'Ingreso' => 'ingreso',
    ];

    private const ABREVIATURAS = [
        'cobro' => 'COB', 'pago' => 'PAG', 'gasto' => 'GAS',
        'movimiento_entre_cuentas' => 'MOV', 'saldo_inicial' => 'SAL', 'ingreso' => 'ING',
    ];

    /**
     * Violaciones del orden descendente por fecha, que es como vienen los extractos.
     *
     * Es el test que validó la regla de fechas invertidas en su momento (plan §3.1): con la
     * corrección aplicada, los 25 archivos de `Cuentas/` quedan en 0. Acá se usa como red de
     * seguridad: si el modo elegido deja el archivo desordenado, se avisa.
     *
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function violacionesDeOrden(LectorExcelContagram $lector, array $filas, bool $invertida): int
    {
        $previa = null;
        $malas = 0;

        foreach ($filas as $fila) {
            $f = $lector->fecha($fila['Fecha'] ?? null, $invertida);

            if ($f === null) {
                continue;
            }
            if ($previa !== null && $f->gt($previa)) {
                $malas++;
            }
            $previa = $f;
        }

        return $malas;
    }

    public function handle(LectorExcelContagram $lector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cuentas = new CuentasDeTesoreria();

        // Los export de `Cuentas/` traen el día y el mes cambiados (plan §3.1) y hay que
        // corregirlos; los extractos exportados por rango en agosto de 2026 vienen bien y
        // corregirlos los rompería (05/08 pasaría a 08/05). Se declara explícitamente en vez de
        // adivinarlo: en un archivo de uno o dos movimientos las dos lecturas son indistinguibles,
        // y equivocarse ahí corrompe fechas en silencio. El control de orden de abajo avisa si la
        // elección fue la incorrecta.
        $invertir = ! $this->option('fechas-directas');

        if ($corte = $this->option('corte')) {
            $this->corte = $corte;
            $this->info("Corte: {$corte}");
        }

        $dirs = $this->option('dir') ?: [public_path('imports/Cuentas')];
        $archivos = [];
        foreach ($dirs as $d) {
            $archivos = array_merge($archivos, glob(rtrim($d, '/\\').'/*.xlsx') ?: []);
        }

        if ($filtro = $this->option('cuenta')) {
            $archivos = array_filter($archivos, fn ($a) => str_contains(basename($a), $filtro));
        }

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— IMPORTANDO TESORERÍA —');

        $stats = array_fill_keys(['creados', 'ya_existian', 'sin_tipo', 'fuera_de_corte', 'excluidos_ml'], 0);
        $cobrosDeML = $this->cobrosDeVentasYaEnElCrm($lector);
        $porTipo = [];
        $saldos = [];

        foreach ($archivos as $path) {
            $archivo = basename($path, '.xlsx');
            // Los extractos del tramo final llegaron con el nombre completo del export
            // ("Caja del Local Movimientos 13-08-2026 2302 Hs"): la cuenta es lo que va antes de
            // " Movimientos ". Los de `Cuentas/` traen sólo el nombre y no cambian.
            $nombreContagram = preg_split('/ Movimientos \d/', $archivo)[0];
            // Mercado Pago viene partido por año pero es una sola cuenta.
            if (preg_match('/^20\d\d MP$/', $nombreContagram)) {
                $nombreContagram = 'MP';
            }

            // Un archivo que no corresponde a una cuenta (informes de ventas, compras o gastos
            // dejados en la misma carpeta) se saltea en vez de crear una cuenta fantasma.
            if (! $cuentas->existe($nombreContagram)) {
                continue;
            }
            $cuentaId = $cuentas->resolver($nombreContagram);
            $nombreCrm = $cuentas->nombreEnElCrm($nombreContagram);

            $filas = $lector->leer($path)['filas'];
            $desorden = $this->violacionesDeOrden($lector, $filas, $invertir);
            $this->line(sprintf('%-26s -> %-24s %5d movimientos%s%s', $archivo, $nombreCrm,
                count($filas), $invertir ? '' : '  [fechas directas]',
                $desorden > 0 ? "  ⚠ {$desorden} fuera de orden" : ''));

            $suma = 0.0;
            $saldoDeclarado = null;

            foreach ($filas as $fila) {
                $operacion = $lector->texto($fila['Operación'] ?? null) ?? '';
                $tipo = self::TIPOS[$operacion] ?? null;
                if ($tipo === null) {
                    $stats['sin_tipo']++;

                    continue;
                }

                $monto = (float) ($lector->numero($fila['Ingreso'] ?? null) ?? 0)
                       + (float) ($lector->numero($fila['Egreso'] ?? null) ?? 0);

                // `Fecha` invertida sólo donde corresponde: ver necesitaInvertirFechas().
                $fecha = $lector->fecha($fila['Fecha'] ?? null, $invertir);

                // Del 06/08 en adelante manda el CRM: importar esos movimientos duplica los que la
                // app ya generó. Sin este corte entraban 31 de más, uno con fecha 24/08 (un cheque
                // propio a vencer, o sea del futuro).
                if ($fecha === null || $fecha->format('Y-m-d') > $this->corte) {
                    $stats['fuera_de_corte']++;

                    continue;
                }

                // Los cobros de las ventas de Mercado Libre que ya estaban en el CRM: el CRM generó
                // su movimiento al cobrarlas y éste sería el mismo peso por segunda vez.
                $claveCobro = $this->claveCobro($lector, $fila, $tipo, $monto);
                if ($claveCobro !== null && ($cobrosDeML[$claveCobro] ?? 0) > 0) {
                    $cobrosDeML[$claveCobro]--;
                    $stats['excluidos_ml']++;

                    continue;
                }

                // El archivo viene ordenado por fecha descendente: la primera fila trae el saldo
                // final de la cuenta. (El `Id` no sirve para ordenar: es global del sistema.)
                $saldoDeclarado ??= (float) ($lector->numero($fila['Saldo'] ?? null) ?? 0);
                $suma += $monto;

                // La fecha es parte de la clave, no un adorno: sin ella hay colisiones reales
                // (`TES-7-COB-14581-13492037` se repetía). Sólo `cuenta + operación + Id + fecha +
                // monto` es única sobre los 48.222 movimientos — verificado contra las otras cuatro
                // combinaciones, que dan de 8 a 22.823 colisiones.
                $legacyId = sprintf('TES-%d-%s-%s-%s-%d', $cuentaId, self::ABREVIATURAS[$tipo],
                    $lector->texto($fila['Id'] ?? null) ?? '0', $fecha->format('Ymd'),
                    (int) round($monto * 100));

                $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;

                if (MovimientoTesoreria::withTrashed()->where('legacy_id', $legacyId)->exists()) {
                    $stats['ya_existian']++;

                    continue;
                }

                $stats['creados']++;
                if ($dryRun) {
                    continue;
                }

                $mov = new MovimientoTesoreria([
                    'cuenta_tesoreria_id' => $cuentaId,
                    'fecha' => $fecha,
                    'tipo' => $tipo,
                    'monto' => $monto,
                    'detalle' => $lector->texto($fila['Detalle'] ?? null),
                    'nro_comprobante' => $this->nroComprobante($lector, $fila),
                    'observacion' => $lector->texto($fila['Descripción'] ?? null),
                ]);
                $mov->legacy_id = $legacyId;
                $mov->created_at = $fecha;
                $mov->updated_at = $fecha;
                $mov->save();
            }

            $clave = $nombreCrm;
            $saldos[$clave]['suma'] = ($saldos[$clave]['suma'] ?? 0) + $suma;
            // Para MP (6 archivos) el saldo válido es el del último año procesado.
            $saldos[$clave]['declarado'] = $saldoDeclarado;
        }

        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], collect($stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());
        $this->table(['Tipo', 'Movimientos'], collect($porTipo)
            ->map(fn ($v, $k) => [$k, number_format($v)])->values()->all());

        $this->verificarSaldos($saldos, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Cobros que **no** hay que importar: los de las ventas de Mercado Libre que ya estaban en el
     * CRM y por eso se excluyeron de `migracion:ventas`.
     *
     * El cobro de esas ventas figura igual en `Cuentas/`, y el CRM ya generó su propio movimiento
     * al cobrarlas: importarlo sería contar el mismo peso dos veces.
     *
     * El cruce va por **nombre del cliente + monto**, que funciona porque el `Detalle` del
     * movimiento usa el mismo nombre que el Excel de ventas (el nombre real del comprador, no el
     * apodo de Mercado Libre con el que quedaron cargadas en el CRM).
     *
     * @return array<string,int> clave => cuántos cobros excluir con esa clave
     */
    private function cobrosDeVentasYaEnElCrm(LectorExcelContagram $lector): array
    {
        $path = $this->option('excluir');
        if (! $path) {
            $this->warn('Sin --excluir: NO se filtran los cobros de las ventas de ML ya cargadas.');

            return [];
        }

        $excluidas = array_flip(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
        $servicio = new ComprobantesContagram($lector, public_path('imports'));

        $claves = [];
        // Las exclusiones de ML son todas de 2026: el CRM se empezó a usar en agosto de ese año.
        foreach ($servicio->delAnio('2026') as $legacyId => $c) {
            if (! isset($excluidas[$legacyId]) || abs($c['cobrado']) < 0.005) {
                continue;
            }
            $clave = $this->clave($c['cliente'], $c['cobrado']);
            $claves[$clave] = ($claves[$clave] ?? 0) + 1;
        }

        $this->info('Cobros de ventas de ML a excluir de tesorería: '.array_sum($claves));

        return $claves;
    }

    /** Clave de un movimiento de cobro, para cruzarlo con las ventas excluidas. */
    private function claveCobro(LectorExcelContagram $lector, array $fila, string $tipo, float $monto): ?string
    {
        if ($tipo !== 'cobro') {
            return null;
        }

        return $this->clave($lector->normalizarNombre($fila['Detalle'] ?? ''), $monto);
    }

    private function clave(string $nombre, float $monto): string
    {
        // Redondeo a centavos: el CRM y Contagram difieren hasta en un centavo por redondeo.
        return mb_strtolower($nombre).'|'.round($monto, 1);
    }

    /** Punto de venta y número de factura, si el movimiento los trae. */
    private function nroComprobante(LectorExcelContagram $lector, array $fila): ?string
    {
        $pv = $lector->numero($fila['Punto de Venta'] ?? null);
        $nro = $lector->numero($fila['N° Factura'] ?? null);

        return $pv && $nro ? sprintf('%04d-%08d', (int) $pv, (int) $nro) : null;
    }

    /**
     * Compara el saldo de cada cuenta contra el que declara Contagram.
     *
     * En las cuentas de tipo "a pagar" el signo viene invertido: Contagram muestra un pasivo en
     * positivo (lo que se debe) mientras la suma de movimientos da negativo. No es un error.
     */
    private function verificarSaldos(array $saldos, bool $dryRun): void
    {
        $this->newLine();
        $this->line('<comment>Saldo por cuenta — control contra Contagram</comment>');

        $filas = [];
        $ok = 0;
        foreach ($saldos as $cuenta => $d) {
            $declarado = (float) $d['declarado'];
            $suma = round((float) $d['suma'], 2);
            $coincide = abs($suma - $declarado) < 0.05;
            $esPasivo = ! $coincide && abs($suma + $declarado) < 0.05;

            if ($coincide || $esPasivo) {
                $ok++;
            }

            $filas[] = [
                $cuenta,
                number_format($suma, 2),
                number_format($declarado, 2),
                $coincide ? 'OK' : ($esPasivo ? 'OK (pasivo, signo invertido)' : '*** NO CUADRA'),
            ];
        }

        $this->table(['Cuenta', 'Suma migrada', 'Saldo Contagram', ''], $filas);
        $this->line(sprintf('Cuentas que cuadran: <info>%d de %d</info>', $ok, count($saldos)));

        if ($dryRun) {
            $this->info('DRY RUN: no se escribió nada.');
        }
    }
}
