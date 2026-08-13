<?php

namespace App\Console\Commands;

use App\Models\CuentaTesoreria;
use App\Models\MovimientoTesoreria;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja la tesorería migrada igual a Contagram y vincula las NC/ND de venta a su comprobante.
 *
 * Todo lo que hace está justificado en `docs/importacion_casos_a_revisar.md` §10 a §13, y es
 * **idempotente**: correrlo dos veces no cambia nada la segunda vez. Se ejecutó primero sobre un
 * clon local de la base del VPS (12/08/2026) y se verificó cuenta por cuenta contra la columna
 * `Saldo` de los Excel de `public/imports/Cuentas/` antes de llevarlo a producción.
 *
 * No se sube el dump de local al VPS a propósito: el VPS siguió operando (cron de Mercado Libre)
 * desde que se clonó, así que los cambios se re-aplican como comando y no como restore.
 */
class NormalizarTesoreriaContagram extends Command
{
    protected $signature = 'contagram:normalizar-tesoreria {--dry-run : Muestra lo que haría sin escribir}';

    protected $description = 'Fusiona/renombra las cuentas de tesorería según Contagram, reconstruye Caja chica gastos y vincula las NC/ND de venta';

    /**
     * Fusiones: el import de ventas/compras/gastos usaba el nombre del Excel de origen y el de
     * tesorería el nombre del archivo, así que la misma cuenta de Contagram quedó partida en dos
     * ids. Se conserva la que tiene los movimientos de tesorería.
     *
     * @var array<string, string> nombre a absorber => nombre que queda
     */
    private const FUSIONES = [
        'USD Local' => 'Juan USD Personal',
        'Cabal Acreditaciones' => 'Cabal',
        'Cabal Credicoop' => 'Cabal A Pagar',
        'Cabal Credicoop A Pagar' => 'Cabal A Pagar',
        'Visa Credicoop' => 'Visa Credicoop A Pagar',
    ];

    /**
     * Nombre canónico = el de la **ficha** de la cuenta en Contagram, no el del panel de Saldos,
     * que los recorta (§10). El sufijo lo llevan las cuentas de tarjeta/valores, no cajas ni bancos.
     *
     * @var array<string, array{0: string, 1: ?string}> nombre actual => [nombre nuevo, tipo nuevo]
     */
    private const CANONICOS = [
        'Cabal' => ['Cabal Acreditaciones a Cobrar', 'a_cobrar'],
        'Cabal A Pagar' => ['Cabal Credicoop a Pagar', 'a_pagar'],
        'Visa Credicoop A Pagar' => ['Visa Credicoop a Pagar', 'a_pagar'],
        'VISA' => ['Visa a Cobrar', null],
        'Mastercard' => ['Mastercard a Cobrar', null],
        'PAYWAY QR' => ['PAYWAY QR a Cobrar', null],
        'Nulo' => ['Nulo a Cobrar', 'a_cobrar'],
        // El `tipo` decide en qué bloque del panel cae el saldo. Nulo y Retenciones son A Cobrar en
        // Contagram (estaban como efectivo) y USD Online figura en Bancos (estaba como efectivo).
        'Retenciones' => ['Retenciones', 'a_cobrar'],
        'USD Online' => ['USD Online', 'banco'],
    ];

    /** Cuentas que no existen en Contagram (confirmado por el usuario) y no tienen ninguna referencia. */
    private const INEXISTENTES = ['Caja General', 'VISA Corporativa'];

    /**
     * Cobros y pago de `Caja chica gastos` que sólo figuran en la ficha de Contagram: no hay export
     * de esa cuenta y el import de `Cuentas/` nunca la cubrió (§13).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: float, 4: string, 5: string}>
     */
    private const CAJA_CHICA_FICHA = [
        ['RECON-19-COB-21330', '2025-10-27', 'cobro', 44729.35, 'Juan Ignacio 1141881828', 'Ficha de Contagram, Id 21330 (obs: de nc)'],
        ['RECON-19-COB-17283', '2025-01-09', 'cobro', 32889.06, 'Maria Elena 1133244122', 'Ficha de Contagram, Id 17283 (obs: Cobro Mauricio)'],
        ['RECON-19-COB-20682', '2025-09-05', 'cobro', 10000.00, 'Maria 1122606824', 'Ficha de Contagram, Id 20682. Parcial: el cobro es de 553.311,00 y a esta caja entraron 10.000'],
        ['RECON-19-PAG-2268', '2025-01-09', 'pago', -25050.00, 'Ferreteria La de Olleros', 'Ficha de Contagram, Id 2268'],
    ];

    /** Fecha hasta la que llega el export `Cuentas/` (el archivo más completo). */
    private const CORTE_EXPORT = '2026-08-05';

    private bool $dryRun = false;

    /** Tablas que apuntan a `cuenta_tesoreria_id` y hay que arrastrar en cada fusión. */
    private const TABLAS_CUENTA = ['movimientos_tesoreria', 'cobros', 'gastos', 'pagos', 'otros_ingresos', 'tn_conexion_rest'];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('DRY RUN — no se escribe nada.');
        }

        try {
            DB::transaction(function () {
                $this->fusionarCuentas();
                $this->renombrarYTipificar();
                $this->borrarInexistentes();
                $this->refecharGastos();
                $this->reconstruirCajaChica();
                $this->vincularNotas();
                $this->recuperarNotasSinImporte();
                $this->vincularNotasCompra();
                $this->notasPosterioresAlCorte();
                $this->refecharPagos();
                $this->reconstruirPagos();
                $this->reconstruirCobros();
                $this->movimientosPosterioresAlCorte();
                $this->tesoreriaDePagosSinMovimiento();
                $this->resincronizarGastos();

                // El dry-run recorre todo con las escrituras hechas y las descarta al final: así el
                // conteo de cada paso refleja el estado que dejaría el paso anterior, no el actual.
                if ($this->dryRun) {
                    throw new \RuntimeException('__dry_run__');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__dry_run__') {
                throw $e;
            }

            $this->newLine();
            $this->warn('DRY RUN terminado: los cambios se descartaron.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Listo. Verificar el panel de Tesorería contra la columna `Saldo` de los Excel de Cuentas/.');

        return self::SUCCESS;
    }

    private function fusionarCuentas(): void
    {
        foreach (self::FUSIONES as $origen => $destino) {
            $o = CuentaTesoreria::where('nombre', $origen)->first();
            $d = CuentaTesoreria::where('nombre', $destino)->first();

            if ($o === null || $d === null || $o->id === $d->id) {
                continue;
            }

            $movidos = 0;
            foreach (self::TABLAS_CUENTA as $tabla) {
                $movidos += DB::table($tabla)->where('cuenta_tesoreria_id', $o->id)
                    ->update(['cuenta_tesoreria_id' => $d->id]);
            }

            // El saldo inicial de la cuenta absorbida se conserva si la destino no tenía uno propio.
            if ((float) $d->saldo_inicial === 0.0 && (float) $o->saldo_inicial !== 0.0) {
                $d->update(['saldo_inicial' => $o->saldo_inicial, 'saldo_inicial_fecha' => $o->saldo_inicial_fecha]);
            }

            $o->delete();
            $this->line(sprintf('  fusionada  %-28s -> %-28s (%d referencias)', $origen, $destino, $movidos));
        }
    }

    private function renombrarYTipificar(): void
    {
        foreach (self::CANONICOS as $actual => [$nuevo, $tipo]) {
            $c = CuentaTesoreria::where('nombre', $actual)->first();

            if ($c === null) {
                continue;
            }

            $cambios = array_filter([
                'nombre' => $c->nombre === $nuevo ? null : $nuevo,
                'tipo' => ($tipo === null || $c->tipo === $tipo) ? null : $tipo,
            ]);

            if ($cambios === []) {
                continue;
            }

            $c->update($cambios);
            $this->line(sprintf('  ajustada   %-28s -> %-28s %s', $actual, $nuevo, $tipo ? "[{$tipo}]" : ''));
        }
    }

    private function borrarInexistentes(): void
    {
        foreach (self::INEXISTENTES as $nombre) {
            $c = CuentaTesoreria::where('nombre', $nombre)->first();

            if ($c === null) {
                continue;
            }

            // Nunca borrar a ciegas: la lección de §8 vale para lo que *puede* estar en uso.
            $referencias = collect(self::TABLAS_CUENTA)
                ->sum(fn (string $t) => DB::table($t)->where('cuenta_tesoreria_id', $c->id)->count());

            if ($referencias > 0) {
                $this->warn("  {$nombre} tiene {$referencias} referencias: se oculta en vez de borrarse.");
                $c->update(['visible' => false]);

                continue;
            }

            $c->delete();
            $this->line("  borrada    {$nombre} (no existe en Contagram, 0 referencias)");
        }
    }

    /**
     * `Caja chica gastos` no tiene export propio. Los gastos ya estaban en la tabla `gastos` (el
     * export de Gastos trae la cuenta en `Medio de pago`), pero nunca se les generó el movimiento;
     * y el fondeo estaba registrado sólo del lado de la cuenta que transfiere.
     */
    /**
     * Corrige los gastos que quedaron con el día y el mes cambiados.
     *
     * En los Excel de `Gastos/` la fecha viene en formato **mes/día**, y cuando el día es ≤ 12 se
     * interpretó al revés: el gasto 8184 ("Caja chica", $77.757) figura el 01/06/2026 y es del
     * 06/01/2026. Se nota en el propio archivo: los "días" van sólo del 1 al 8 y los meses del 1 al
     * 12, imposible en un año de gastos. Las filas con día > 12 no se pueden confundir y quedaron
     * bien.
     *
     * La fecha correcta ya está en la base: el movimiento de tesorería del gasto se importó del
     * extracto de la cuenta (`Cuentas/`), que sí trae la fecha como fecha. Por eso los bancos
     * cierran a cualquier corte aunque el gasto tenga el día cambiado — son 945 de 9.095.
     *
     * Sólo se toca el gasto cuando el cruce es inequívoco: **un** movimiento de tipo `gasto` con
     * ese Id de Contagram, el mismo importe, y una fecha que es exactamente la del gasto con día y
     * mes intercambiados. Con eso no hay criterio inventado: si no calza, se deja como está.
     *
     * Corre antes de `reconstruirCajaChica()` a propósito: esa cuenta es la única sin extracto
     * propio, se reconstruye desde `gastos` y por eso heredó las fechas malas. Los movimientos que
     * ya estén creados los re-fecha después `resincronizarGastos()`.
     */
    private function refecharGastos(): void
    {
        // Se cruza en memoria y no con un JOIN: el vínculo vive dentro del `legacy_id` y sacarlo en
        // SQL obliga a un LIKE por fila, que sobre 9.000 gastos × 25.000 movimientos no termina más.
        $porId = [];

        foreach (DB::table('movimientos_tesoreria')->whereNull('deleted_at')->where('tipo', 'gasto')
            ->whereNotNull('legacy_id')->get(['legacy_id', 'fecha', 'monto']) as $m) {
            if (! preg_match('/-GAS-(\d+)-/', $m->legacy_id, $c)) {
                continue;
            }

            $porId[$c[1]][] = $m;
        }

        $corregidos = 0;

        foreach (DB::table('gastos')->whereNull('deleted_at')->whereNotNull('legacy_id')
            ->get(['id', 'legacy_id', 'fecha', 'monto']) as $g) {
            $candidatos = $porId[substr($g->legacy_id, strrpos($g->legacy_id, '-') + 1)] ?? [];

            // Un único movimiento con ese Id, mismo importe, y fecha = la del gasto con día y mes
            // intercambiados. Si no calza exactamente, no se toca.
            if (count($candidatos) !== 1) {
                continue;
            }

            $m = $candidatos[0];

            if (abs((float) $m->monto + (float) $g->monto) >= 0.005 || $m->fecha === $g->fecha) {
                continue;
            }

            [$ay, $am, $ad] = explode('-', $g->fecha);
            [$by, $bm, $bd] = explode('-', $m->fecha);

            if ($ay !== $by || $am !== $bd || $ad !== $bm) {
                continue;
            }

            $corregidos++;

            if (! $this->dryRun) {
                DB::table('gastos')->where('id', $g->id)->update(['fecha' => $m->fecha]);
            }
        }

        if ($corregidos > 0) {
            $this->line("  Gastos con día y mes cambiados, re-fechados contra el extracto: {$corregidos}");
        }

        $this->refecharGastosDeCajaChica();
    }

    /**
     * Los 17 gastos de `Caja chica gastos` que arrastran el mismo día/mes cambiado.
     *
     * Esa cuenta es la única sin export propio, así que no tienen movimiento en ningún extracto y
     * `refecharGastos()` no puede cruzarlos. Se corrigen por la regla del formato, que se validó
     * contra los que **sí** tienen extracto: de 945 casos ambiguos, los 945 estaban invertidos y
     * **cero** quedaron derechos. Sin contraejemplos.
     *
     * La lista va versionada y explícita —no se deduce en tiempo de ejecución— para que se pueda
     * auditar gasto por gasto y para no depender de volver a leer los Excel.
     */
    private function refecharGastosDeCajaChica(): void
    {
        $archivo = base_path('database/data/gastos_caja_chica_refecha.json');

        if (! is_file($archivo)) {
            return;
        }

        $corregidos = 0;

        foreach (json_decode(file_get_contents($archivo), true) as $legacy => $d) {
            // Sólo si sigue como la dejó el import: si alguien ya la tocó, se respeta.
            $afectadas = $this->dryRun
                ? DB::table('gastos')->where('legacy_id', $legacy)->whereNull('deleted_at')
                    ->where('fecha', $d['actual'])->count()
                : DB::table('gastos')->where('legacy_id', $legacy)->whereNull('deleted_at')
                    ->where('fecha', $d['actual'])->update(['fecha' => $d['correcta']]);

            $corregidos += $afectadas;
        }

        if ($corregidos > 0) {
            $this->line("  Gastos de Caja chica re-fechados por la regla del formato: {$corregidos}");
        }
    }

    /**
     * Notas de crédito emitidas en Contagram **después** del corte del 05/08 y que el CRM no tiene.
     *
     * Salieron del informe de NC/ND de 2026 (`public/imports/nc nd 2026/`): de 155 notas, 148 están
     * en los dos sistemas con el importe idéntico y éstas 6 sólo en Contagram. Explican por qué las
     * ventas de Micaela Echeverría, Emanuel Gutiérrez y Martín González aparecían con el cobro
     * "borrado" — no se borró la venta, se le emitió NC y se anuló el cobro.
     *
     * La de Jacinto además cierra una de las diferencias de `Caja del Local`: la venta es de
     * $257.690,06 y $257.690,06 − $227.357,99 = **$30.332,07**, que es justo lo que Contagram tenía
     * cobrado para ese cliente.
     *
     * Se cargan una por una y no por regla general: son documentos fiscales de otro sistema, así
     * que el número de comprobante y la venta a la que aplican se transcriben, no se deducen. El
     * `legacy_id` es el que habría puesto el importador, así que si alguna vez llega un export que
     * las incluya no se duplican.
     *
     * `nro_comprobante` va nulo a propósito: las 546 notas B migradas lo tienen nulo (sólo las A de
     * compras lo traen), y romper esa convención por 6 filas ensucia más de lo que aporta. El
     * número real queda en `nota_interna`.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: float, 4: string, 5: string}>
     */
    private const NOTAS_POST_CORTE = [
        ['2026-NC-728', '2026-FC-24103', '2026-08-07', 19290.86, 'B', 'Contagram Id 728, B 0005-00000311'],
        ['2026-NC-729', '2026-FC-23756', '2026-08-10', 212706.70, 'B', 'Contagram Id 729, B 0005-00000312'],
        ['2026-NC-730', '2026-FC-23661', '2026-08-10', 79096.49, 'B', 'Contagram Id 730, B 0005-00000313'],
        ['2026-NC-731', '2026-FC-24162', '2026-08-10', 176611.63, 'B', 'Contagram Id 731, B 0005-00000225'],
        ['2026-NC-732', '2026-FC-24159', '2026-08-10', 171818.79, 'B', 'Contagram Id 732, B 0005-00000226'],
        // La venta de Jacinto se cargó a mano después del corte, así que no tiene legacy: se
        // identifica por su id del CRM, verificado por cliente y por el neto de $30.332,07.
        ['2026-NC-733', '#23756', '2026-08-11', 227357.99, 'B', 'Contagram Id 733, sin comprobante fiscal'],
    ];

    private function notasPosterioresAlCorte(): void
    {
        $creadas = 0;

        foreach (self::NOTAS_POST_CORTE as [$legacy, $refVenta, $fecha, $monto, $tipoComp, $nota]) {
            if (DB::table('notas_credito_debito')->where('legacy_id', $legacy)->exists()) {
                continue;
            }

            $ventaId = str_starts_with($refVenta, '#')
                ? (int) substr($refVenta, 1)
                : (int) DB::table('ventas')->where('legacy_id', $refVenta)->whereNull('deleted_at')->value('id');

            // Sin venta no se crea: una NC suelta no descuenta de la cuenta corriente de nadie.
            if ($ventaId === 0 || ! DB::table('ventas')->where('id', $ventaId)->whereNull('deleted_at')->exists()) {
                $this->warn("  Nota {$legacy}: no existe la venta {$refVenta}, se omite.");

                continue;
            }

            $creadas++;

            if ($this->dryRun) {
                continue;
            }

            DB::table('notas_credito_debito')->insert([
                'legacy_id' => $legacy,
                'venta_id' => $ventaId,
                'tipo' => 'credito',
                'afecta_stock' => 0,
                'mes_imputacion' => substr($fecha, 0, 8).'01',
                'fecha_emision' => $fecha,
                'monto' => $monto,
                'descuento_general_tipo' => 'porcentaje',
                'tipo_comprobante' => $tipoComp,
                'nota_interna' => $nota,
                'created_at' => $fecha,
                'updated_at' => $fecha,
            ]);
        }

        if ($creadas > 0) {
            $this->line("  Notas de crédito posteriores al corte, creadas desde el informe: {$creadas}");
        }
    }

    private function reconstruirCajaChica(): void
    {
        $caja = CuentaTesoreria::where('nombre', 'Caja chica gastos')->first();

        if ($caja === null) {
            return;
        }

        $creados = 0;

        // 1) Contrapartida del fondeo: la otra pata ya existe en la cuenta que transfirió.
        $origen = MovimientoTesoreria::where('tipo', 'movimiento_entre_cuentas')
            ->where('detalle', 'like', '%chica%')
            ->where('cuenta_tesoreria_id', '!=', $caja->id)
            ->with('cuenta:id,nombre')
            ->get();

        foreach ($origen as $m) {
            $creados += (int) $this->crearMovimiento($caja->id, 'RECON-19-'.$m->legacy_id, $m->fecha,
                'movimiento_entre_cuentas', -$m->monto, $m->cuenta?->nombre ?? 'Transferencia',
                'Contrapartida reconstruida (sin export de Caja chica gastos)');
        }

        // 2) Un movimiento por cada gasto ya imputado a la caja.
        foreach (DB::table('gastos')->where('cuenta_tesoreria_id', $caja->id)->where('pendiente', false)
            ->whereNull('deleted_at')->get() as $g) {
            $creados += (int) $this->crearMovimiento($caja->id, 'RECON-19-'.$g->legacy_id, $g->fecha,
                'gasto', -$g->monto, $g->descripcion ?: 'Gasto',
                'Movimiento reconstruido desde gastos (sin export de Caja chica gastos)',
                \App\Models\Gasto::class, $g->id);
        }

        // 3) Cobros y pago que sólo figuran en la ficha de Contagram.
        foreach (self::CAJA_CHICA_FICHA as [$legacy, $fecha, $tipo, $monto, $detalle, $obs]) {
            $creados += (int) $this->crearMovimiento($caja->id, $legacy, $fecha, $tipo, $monto, $detalle, $obs);
        }

        if ($creados > 0) {
            $saldo = $this->dryRun ? null : $caja->saldoA();
            $this->line("  Caja chica gastos: {$creados} movimientos reconstruidos".
                ($saldo === null ? '' : sprintf(' (saldo %s, Contagram 33.137,66)', number_format($saldo, 2))));
        }
    }

    private function crearMovimiento(int $cuentaId, string $legacy, mixed $fecha, string $tipo,
        float $monto, ?string $detalle, string $observacion, ?string $origenType = null, ?int $origenId = null): bool
    {
        if (MovimientoTesoreria::withTrashed()->where('legacy_id', $legacy)->exists()) {
            return false;
        }

        if ($this->dryRun) {
            return true;
        }

        $mov = new MovimientoTesoreria([
            'cuenta_tesoreria_id' => $cuentaId,
            'fecha' => $fecha,
            'tipo' => $tipo,
            'monto' => $monto,
            'detalle' => $detalle,
            'observacion' => $observacion,
            'origen_type' => $origenType,
            'origen_id' => $origenId,
        ]);
        $mov->legacy_id = $legacy;
        $mov->save();

        return true;
    }

    /**
     * El export no dice a qué venta corrige cada NC/ND. El vínculo se dedujo cruzando las columnas
     * `Total NC`/`Total ND` del `Ventas c- cobro` con los importes de las notas del mismo cliente
     * (§11), y el resultado quedó versionado para poder re-aplicarlo tal cual en cualquier ambiente.
     */
    private function vincularNotas(): void
    {
        $archivo = base_path('database/data/notas_venta_mapeo.json');

        if (! is_file($archivo)) {
            $this->warn('  No está database/data/notas_venta_mapeo.json: se omite el vínculo de notas.');

            return;
        }

        $mapa = json_decode(file_get_contents($archivo), true);
        $ventas = Venta::whereIn('legacy_id', array_values($mapa))->pluck('id', 'legacy_id');
        $vinculadas = 0;

        foreach ($mapa as $notaLegacy => $ventaLegacy) {
            if (! isset($ventas[$ventaLegacy])) {
                continue;
            }

            $q = NotaCreditoDebito::where('legacy_id', $notaLegacy)->whereNull('venta_id');
            $vinculadas += $this->dryRun ? $q->count() : $q->update(['venta_id' => $ventas[$ventaLegacy]]);
        }

        $this->line("  NC/ND de venta vinculadas a su comprobante: {$vinculadas} de ".count($mapa));
    }

    /**
     * Notas cuyo **importe en la base estaba mal** y por eso nunca pudieron matchearse. Dos causas:
     *
     * 1. Las de 2021/2022 quedaron en $0,00: el export por-ítem de esos años no trae los importes
     *    en las filas de nota (`Total Venta`, `Subtotal` y `Precio de Venta` vienen vacíos).
     * 2. Las multi-renglón quedaron **cortas**: el importador tomó el `Total Venta` de una fila en
     *    vez de sumar los renglones. La NC 234 entró en $212.560,92 sumando 2 de sus 3 ítems,
     *    cuando vale $311.628,81.
     *
     * En ambos casos el importe correcto está en la columna `Total NC` de la venta en el
     * `Ventas c- cobro`, y el vínculo se confirmó contra el "Documento que Ajusta" de Contagram.
     */
    private function recuperarNotasSinImporte(): void
    {
        $archivo = base_path('database/data/notas_venta_recuperadas.json');

        if (! is_file($archivo)) {
            return;
        }

        $mapa = json_decode(file_get_contents($archivo), true);
        $ventas = Venta::whereIn('legacy_id', array_column($mapa, 'venta'))->pluck('id', 'legacy_id');
        $recuperadas = 0;

        foreach ($mapa as $notaLegacy => $d) {
            if (! isset($ventas[$d['venta']])) {
                continue;
            }

            $q = NotaCreditoDebito::where('legacy_id', $notaLegacy)->whereNull('venta_id');
            $recuperadas += $this->dryRun
                ? $q->count()
                : $q->update(['venta_id' => $ventas[$d['venta']], 'monto' => $d['monto']]);
        }

        $this->line("  Notas con importe recuperado del `Total NC` de su venta: {$recuperadas} de ".count($mapa));
    }

    /**
     * Las 12 NC/ND de compra que el mapeo automático de §8d no había podido resolver. Se sacaron
     * del "Documento que Ajusta" de la ficha de cada compra en Contagram, y cada grupo cierra por
     * aritmética contra el total de NC/ND que declara esa misma ficha —por ejemplo la compra 1300:
     * 338.887,40 + 179.735,88 = 518.623,28—, así que el vínculo no depende de leer bien una captura.
     *
     * Dos de ellas traían además el importe mal por el defecto de multi-renglón: la NC 46 estaba en
     * $89.867,94 y vale $179.735,88, exactamente el doble.
     */
    private function vincularNotasCompra(): void
    {
        $archivo = base_path('database/data/notas_compra_recuperadas.json');

        if (! is_file($archivo)) {
            return;
        }

        $mapa = json_decode(file_get_contents($archivo), true);
        $compras = \App\Models\Compra::whereIn('legacy_id', array_column($mapa, 'compra'))->pluck('id', 'legacy_id');
        $vinculadas = 0;

        foreach ($mapa as $notaLegacy => $d) {
            if (! isset($compras[$d['compra']])) {
                continue;
            }

            $q = NotaCreditoDebito::where('legacy_id', $notaLegacy)->whereNull('compra_id');
            $vinculadas += $this->dryRun
                ? $q->count()
                : $q->update(['compra_id' => $compras[$d['compra']], 'monto' => $d['monto']]);
        }

        $this->line("  NC/ND de compra vinculadas a su comprobante: {$vinculadas} de ".count($mapa));
    }

    /**
     * Los pagos migrados quedaron con **la fecha de emisión de la compra**, no la del pago real
     * (2.343 de 2.346). Con eso el saldo de Cta Cte de Proveedores a hoy cierra, pero a cualquier
     * fecha pasada da mal: se descuentan pagos que todavía no habían ocurrido.
     *
     * La fecha real está en los movimientos de tesorería que vinieron de `Cuentas/`: los de tipo
     * `pago` traen el `nro_comprobante` de la compra que cancelan. Se cruza compra + importe exacto
     * y sólo se re-fecha cuando el movimiento es **único** para ese importe, o cuando todos los
     * candidatos coinciden en la fecha. Nada que quede ambiguo se toca.
     */
    private function refecharPagos(): void
    {
        $movimientos = DB::table('movimientos_tesoreria as m')
            ->join('compras as c', function ($j) {
                $j->on('c.nro_comprobante', '=', 'm.nro_comprobante')->whereNull('c.deleted_at');
            })
            ->where('m.tipo', 'pago')->whereNotNull('m.legacy_id')
            ->whereNotNull('m.nro_comprobante')->where('m.nro_comprobante', '<>', '')
            ->select('c.id as compra_id', 'm.fecha', 'm.monto')
            ->get()
            ->groupBy('compra_id');

        $refechados = 0;

        foreach (DB::table('pagos')->whereNull('deleted_at')->get()->groupBy('compra_id') as $compraId => $pagos) {
            $candidatos = $movimientos->get($compraId, collect())->all();

            foreach ($pagos as $pago) {
                $mismos = array_filter($candidatos,
                    fn ($m) => abs(abs((float) $m->monto) - (float) $pago->monto) < 0.005);

                if ($mismos === []) {
                    continue;
                }

                $fechas = array_unique(array_map(fn ($m) => (string) $m->fecha, $mismos));

                // Varios movimientos del mismo importe pero en fechas distintas: no hay forma de
                // saber cuál corresponde a este pago, así que se deja como está.
                if (count($fechas) > 1) {
                    continue;
                }

                $primero = array_key_first($mismos);
                unset($candidatos[$primero]);

                if ((string) $pago->fecha === reset($fechas)) {
                    continue;
                }

                $refechados++;

                if (! $this->dryRun) {
                    DB::table('pagos')->where('id', $pago->id)->update(['fecha' => reset($fechas)]);
                }
            }
        }

        $this->line("  Pagos re-fechados contra su movimiento de tesorería: {$refechados}");
    }

    /**
     * Alias de los medios de pago de Contagram que no coinciden literalmente con el nombre de la
     * cuenta en el CRM. `Juan USD  Personal` viene con doble espacio y se resuelve normalizando.
     */
    private const MEDIOS = [
        'galicia' => 'Banco Galicia',
        'usd online' => 'USD Online',
        'visa credicoop' => 'Visa Credicoop a Pagar',
        'cabal credicoop' => 'Cabal Credicoop a Pagar',
        'cabal acreditaciones' => 'Cabal Acreditaciones a Cobrar',
        'mastercard' => 'Mastercard a Cobrar',
        'visa' => 'Visa a Cobrar',
        'payway qr' => 'PAYWAY QR a Cobrar',
        'nulo' => 'Nulo a Cobrar',
    ];

    /**
     * Deja los pagos de cada compra igual a Contagram, usando el informe
     * "Cuentas Corrientes - Movimientos de Proveedores" filtrado por `Operación = Pago`, que trae
     * el vínculo explícito (`Id Compra`), la fecha real y el medio de pago de cada uno.
     *
     * Dos situaciones:
     *
     * 1. **El importe coincide** → se corrige la fecha (y la cuenta si hace falta). Es el caso de
     *    los pagos que el importador creó bien pero fechados con la emisión de la compra.
     * 2. **El desglose no coincide pero la suma sí** → el importador consolidó en un pago lo que en
     *    Contagram son varios. Ahí se reemplaza el conjunto por el real.
     *
     * Salvaguarda: si algún medio de pago de esa compra no resuelve a una cuenta, **no se toca la
     * compra**. Sin eso se borrarían pagos sin poder recrearlos y la compra quedaría con menos
     * pagado del que tiene (pasó en una prueba: 145 pagos perdidos por `Galicia` vs `Banco Galicia`).
     */
    private function reconstruirPagos(): void
    {
        $archivo = base_path('database/data/pagos_contagram.json');

        if (! is_file($archivo)) {
            return;
        }

        $mapa = json_decode(file_get_contents($archivo), true);
        $cuentas = CuentaTesoreria::pluck('id', 'nombre')
            ->mapWithKeys(fn ($id, $nombre) => [$this->clave($nombre) => $id]);

        $cuentaDe = function (string $medio) use ($cuentas): ?int {
            $k = $this->clave($medio);

            return $cuentas[$k] ?? ($cuentas[$this->clave(self::MEDIOS[$k] ?? '')] ?? null);
        };

        $refechados = 0;
        $rearmadas = 0;
        $creados = 0;
        $omitidas = 0;

        foreach (\App\Models\Compra::whereNotNull('legacy_id')->get(['id', 'legacy_id']) as $compra) {
            $idContagram = substr($compra->legacy_id, strrpos($compra->legacy_id, '-') + 1);
            $reales = $mapa[$idContagram] ?? null;

            if ($reales === null) {
                continue;
            }

            // Sólo los pagos que trajo la migración. Un pago cargado después en el CRM (sin esa
            // nota) no se toca ni entra en el cálculo: el informe de Contagram es anterior y no lo
            // tiene, así que borrarlo sería perder plata real. En el VPS ya hay 8 de esos.
            $pagos = DB::table('pagos')->where('compra_id', $compra->id)->whereNull('deleted_at')
                ->where('nota', 'like', 'Migrado de Contagram%')->get();

            if ($pagos->isEmpty()) {
                continue;
            }

            // 1) Los que coinciden por importe: sólo se corrige fecha y cuenta.
            $libres = $reales;
            $pendientes = [];

            foreach ($pagos as $pago) {
                $i = null;
                foreach ($libres as $k => $r) {
                    if (abs($r['monto'] - (float) $pago->monto) < 0.005) {
                        $i = $k;
                        break;
                    }
                }

                if ($i === null) {
                    $pendientes[] = $pago;

                    continue;
                }

                $cambios = array_filter([
                    'fecha' => $pago->fecha === $libres[$i]['fecha'] ? null : $libres[$i]['fecha'],
                    'cuenta_tesoreria_id' => ($cid = $cuentaDe($libres[$i]['medio'])) === $pago->cuenta_tesoreria_id ? null : $cid,
                ]);

                if ($cambios !== [] && ! $this->dryRun) {
                    DB::table('pagos')->where('id', $pago->id)->update($cambios);
                }

                $refechados += $cambios === [] ? 0 : 1;
                unset($libres[$i]);
            }

            if ($pendientes === []) {
                continue;
            }

            $sumaReal = array_sum(array_column($libres, 'monto'));
            $sumaNuestra = array_sum(array_map(fn ($p) => (float) $p->monto, $pendientes));

            if (abs($sumaReal - $sumaNuestra) > 0.02) {
                continue;
            }

            // Sin cuenta para todos, no se toca: borrar sin poder recrear descuadra la compra.
            foreach ($libres as $r) {
                if ($cuentaDe($r['medio']) === null) {
                    $this->warn("  Compra {$compra->legacy_id}: medio \"{$r['medio']}\" sin cuenta, se omite.");
                    $omitidas++;

                    continue 2;
                }
            }

            $rearmadas++;

            if ($this->dryRun) {
                $creados += count($libres);

                continue;
            }

            foreach ($pendientes as $p) {
                DB::table('pagos')->where('id', $p->id)->delete();
            }

            foreach ($libres as $r) {
                DB::table('pagos')->insert([
                    'compra_id' => $compra->id,
                    'fecha' => $r['fecha'],
                    'monto' => $r['monto'],
                    'cuenta_tesoreria_id' => $cuentaDe($r['medio']),
                    'nota' => 'Migrado de Contagram (pago '.$r['id'].')',
                    'created_at' => $r['fecha'],
                    'updated_at' => $r['fecha'],
                ]);
                $creados++;
            }
        }

        $this->line("  Pagos corregidos contra el informe de Contagram: {$refechados}");
        $this->line("  Compras con el desglose de pagos rearmado: {$rearmadas} ({$creados} pagos)".
            ($omitidas > 0 ? " — {$omitidas} omitidas por medio sin cuenta" : ''));
    }

    /**
     * Lo mismo que `reconstruirPagos()` pero del lado de las ventas, con el informe
     * "Cuentas Corrientes - Movimientos de Clientes" filtrado por `Operación = Cobro` (§17).
     *
     * El importador de ventas **consolidó en un solo cobro** los parciales de cada venta y los
     * fechó con la emisión de la factura, así que la Cta Cte de Clientes envejecía mal: 1.690
     * ventas tienen varios cobros en Contagram y uno solo acá, y otros 1.046 cobros coinciden en
     * importe pero no en fecha (hasta 353 días de desvío).
     *
     * **No toca `movimientos_tesoreria`.** Son dos capas independientes: los saldos de las cajas se
     * importaron de los extractos de `Cuentas/` y ya están verificados contra Contagram, y los
     * cobros migrados no generan movimiento (25.022 movimientos de tipo `cobro` tienen
     * `origen_type` nulo). Por eso las cajas cerraban aunque el desglose de cobros estuviera mal.
     *
     * Salvaguardas, iguales a las de los pagos:
     * - sólo se consideran los cobros con nota `Migrado de Contagram%`: uno cargado después por la
     *   app (una orden de Mercado Libre, por ejemplo) es posterior al informe y borrarlo sería
     *   perder plata real;
     * - el desglose se rearma **sólo si la suma coincide**, y si algún medio no resuelve a una
     *   cuenta se omite la venta entera;
     * - las ventas que no están en el informe se dejan intactas. El export arranca el 02/08/2021,
     *   así que las 358 ventas cobradas antes de esa fecha quedan como están hasta que se consiga
     *   el tramo que falta; volver a correr el comando con el JSON completo las termina de arreglar.
     */
    private function reconstruirCobros(): void
    {
        $archivo = base_path('database/data/cobros_contagram.json');

        if (! is_file($archivo)) {
            return;
        }

        $mapa = json_decode(file_get_contents($archivo), true);
        $cuentas = CuentaTesoreria::pluck('id', 'nombre')
            ->mapWithKeys(fn ($id, $nombre) => [$this->clave($nombre) => $id]);

        $cuentaDe = function (string $medio) use ($cuentas): ?int {
            $k = $this->clave($medio);

            return $cuentas[$k] ?? ($cuentas[$this->clave(self::MEDIOS[$k] ?? '')] ?? null);
        };

        $corregidos = 0;
        $rearmadas = 0;
        $creados = 0;
        $omitidas = 0;

        foreach (Venta::whereNotNull('legacy_id')->get(['id', 'legacy_id']) as $venta) {
            $idContagram = substr($venta->legacy_id, strrpos($venta->legacy_id, '-') + 1);
            $reales = $mapa[$idContagram] ?? null;

            if ($reales === null) {
                continue;
            }

            $cobros = DB::table('cobros')->where('venta_id', $venta->id)->whereNull('deleted_at')
                ->where('nota', 'like', 'Migrado de Contagram%')->get();

            if ($cobros->isEmpty()) {
                continue;
            }

            // 1) Los que coinciden por importe: sólo se corrige fecha y cuenta.
            $libres = $reales;
            $pendientes = [];

            foreach ($cobros as $cobro) {
                $i = null;
                foreach ($libres as $k => $r) {
                    if (abs($r['monto'] - (float) $cobro->monto) < 0.005) {
                        $i = $k;
                        break;
                    }
                }

                if ($i === null) {
                    $pendientes[] = $cobro;

                    continue;
                }

                $cambios = array_filter([
                    'fecha' => $cobro->fecha === $libres[$i]['fecha'] ? null : $libres[$i]['fecha'],
                    'cuenta_tesoreria_id' => ($cid = $cuentaDe($libres[$i]['medio'])) === $cobro->cuenta_tesoreria_id ? null : $cid,
                ]);

                if ($cambios !== [] && ! $this->dryRun) {
                    DB::table('cobros')->where('id', $cobro->id)->update($cambios);
                }

                $corregidos += $cambios === [] ? 0 : 1;
                unset($libres[$i]);
            }

            if ($pendientes === []) {
                continue;
            }

            $sumaReal = array_sum(array_column($libres, 'monto'));
            $sumaNuestra = array_sum(array_map(fn ($c) => (float) $c->monto, $pendientes));

            if (abs($sumaReal - $sumaNuestra) > 0.02) {
                continue;
            }

            foreach ($libres as $r) {
                if ($cuentaDe($r['medio']) === null) {
                    $this->warn("  Venta {$venta->legacy_id}: medio \"{$r['medio']}\" sin cuenta, se omite.");
                    $omitidas++;

                    continue 2;
                }
            }

            $rearmadas++;

            if ($this->dryRun) {
                $creados += count($libres);

                continue;
            }

            foreach ($pendientes as $c) {
                DB::table('cobros')->where('id', $c->id)->delete();
            }

            foreach ($libres as $r) {
                DB::table('cobros')->insert([
                    'venta_id' => $venta->id,
                    'fecha' => $r['fecha'],
                    'monto' => $r['monto'],
                    'cuenta_tesoreria_id' => $cuentaDe($r['medio']),
                    'nota' => 'Migrado de Contagram (cobro '.$r['id'].')',
                    'created_at' => $r['fecha'],
                    'updated_at' => $r['fecha'],
                ]);
                $creados++;
            }
        }

        $this->line("  Cobros corregidos contra el informe de Contagram: {$corregidos}");
        $this->line("  Ventas con el desglose de cobros rearmado: {$rearmadas} ({$creados} cobros)".
            ($omitidas > 0 ? " — {$omitidas} omitidas por medio sin cuenta" : ''));
    }

    /**
     * Pagos que el export `Cuentas/` **sí trae** pero el importador salteó por su corte del 05/08
     * ("del 06/08 en adelante manda el CRM"). Ese corte es correcto para los cobros —el CRM los
     * genera solo al convertir órdenes de Mercado Libre— pero **no para los pagos a proveedores**,
     * que la app no genera: quedaron en `pagos` sin su movimiento, así que descontaban de la deuda
     * del proveedor pero no de la caja. Por eso `Caja General Abajo` mostraba $1.200.000 que ya no
     * estaban (detectado por el usuario contra el Excel, donde ese pago deja el saldo en 0).
     *
     * Se cargan **sólo estos tres**, uno por uno y no por regla general, porque de los 31
     * movimientos posteriores al corte los otros 28 sí están cubiertos: 12 coinciden exacto con un
     * movimiento propio del CRM y 16 son los mismos cobros con **un centavo** de diferencia (el
     * Excel trae 253.464,20 donde el CRM tiene 253.464,19). Importarlos duplicaría $2,6 M en
     * Mercado Pago.
     *
     * El `legacy_id` es el que habría puesto el importador, así que si alguna vez se corre sin
     * corte no se duplican.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: float, 4: string}>
     */
    private const PAGOS_POST_CORTE = [
        ['TES-15-PAG-3382-20260806--120000000', 'Caja General Abajo', '2026-08-06', -1200000.00, 'Pompei SRL'],
        ['TES-7-PAG-3384-20260806--213580035', 'Mercado Pago', '2026-08-06', -2135800.35, 'MERCADO ENVIOS'],
        // Cheque propio a vencer: no descuenta caja hasta el 24/08, y el filtro por fecha de corte
        // de la pantalla ya lo resuelve solo.
        ['TES-2-PAG-3320-20260824--162295712', 'Cheque Propio', '2026-08-24', -1622957.12, 'Peisa'],
    ];

    private function movimientosPosterioresAlCorte(): void
    {
        $cuentas = CuentaTesoreria::pluck('id', 'nombre');
        $creados = 0;

        foreach (self::PAGOS_POST_CORTE as [$legacy, $cuenta, $fecha, $monto, $detalle]) {
            if (! isset($cuentas[$cuenta])) {
                $this->warn("  Cuenta \"{$cuenta}\" inexistente: se omite {$legacy}.");

                continue;
            }

            $creados += (int) $this->crearMovimiento($cuentas[$cuenta], $legacy, $fecha, 'pago', $monto,
                $detalle, 'Pago del export Cuentas/ que el importador salteó por el corte del 05/08');
        }

        if ($creados > 0) {
            $this->line("  Pagos posteriores al corte incorporados a tesorería: {$creados}");
        }
    }

    /**
     * Todo pago migrado posterior al corte del export tiene que impactar la caja.
     *
     * Los movimientos de tesorería salen sólo de `Cuentas/`, y **cada archivo tiene su propio
     * corte**: `Caja abajo` llega al 06/08 y `Caja local` al 05/08. Los pagos que el informe de
     * Movimientos de Proveedores trajo después de esa fecha quedaron en `pagos` sin movimiento:
     * descontaban de la deuda del proveedor pero no de la caja. Así `Caja General Abajo` mostraba
     * $1.200.000 y `Caja del Local` $500.000 que ya no estaban.
     *
     * Sólo entran los **posteriores al corte** y sólo si no hay ya un movimiento equivalente en esa
     * cuenta, fecha e importe. No hay riesgo de duplicar contra la app: los pagos a proveedores no
     * los genera nadie automáticamente (a diferencia de los cobros de Mercado Libre, que sí, y por
     * eso el corte original era correcto para ellos).
     */
    private function tesoreriaDePagosSinMovimiento(): void
    {
        $pagos = DB::table('pagos as pg')
            ->join('cuentas_tesoreria as ct', 'ct.id', '=', 'pg.cuenta_tesoreria_id')
            ->whereNull('pg.deleted_at')
            ->where('pg.fecha', '>', self::CORTE_EXPORT)
            ->where('pg.nota', 'like', 'Migrado de Contagram%')
            ->select('pg.id', 'pg.fecha', 'pg.monto', 'pg.cuenta_tesoreria_id', 'ct.nombre as cuenta')
            ->get();

        $creados = 0;

        foreach ($pagos as $pago) {
            $yaEsta = DB::table('movimientos_tesoreria')
                ->where('cuenta_tesoreria_id', $pago->cuenta_tesoreria_id)
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('origen_type', 'like', '%Pago%')->where('origen_id', $pago->id))
                ->exists()
                || DB::table('movimientos_tesoreria')
                    ->where('cuenta_tesoreria_id', $pago->cuenta_tesoreria_id)
                    ->whereNull('deleted_at')
                    ->where('fecha', $pago->fecha)
                    ->whereRaw('ABS(ABS(monto) - ?) < 0.01', [$pago->monto])
                    ->exists();

            if ($yaEsta) {
                continue;
            }

            $creados += (int) $this->crearMovimiento($pago->cuenta_tesoreria_id, 'RECON-PAG-'.$pago->id,
                $pago->fecha, 'pago', -(float) $pago->monto, 'Pago a proveedor',
                'Pago posterior al corte del export: su movimiento de tesorería no vino en Cuentas/',
                \App\Models\Pago::class, $pago->id);
        }

        if ($creados > 0) {
            $this->line("  Pagos posteriores al corte que no impactaban la caja: {$creados} movimientos creados");
        }
    }

    /**
     * Re-sincroniza los movimientos de gastos que quedaron apuntando a otra cuenta, otro monto u
     * otra fecha que su gasto.
     *
     * Los dejó así `Pagos::conciliarGasto()`, que hasta el 13/08/2026 salía sin hacer nada cuando
     * el gasto ya tenía movimiento: editar un gasto conciliado no movía la caja. El código ya está
     * arreglado; esto limpia lo que quedó mal de antes. En el VPS era un solo caso, el gasto 9246
     * ("Ley 25413", $660,80 del 10/08/2026), cuyo movimiento descontaba de `Caja del Local` cuando
     * el gasto es de `Banco Credicoop`.
     */
    private function resincronizarGastos(): void
    {
        $desfasados = DB::table('movimientos_tesoreria as m')
            ->join('gastos as g', function ($j) {
                $j->on('g.id', '=', 'm.origen_id')->where('m.origen_type', '=', \App\Models\Gasto::class);
            })
            ->whereNull('m.deleted_at')
            ->whereNull('g.deleted_at')
            ->whereNotNull('g.cuenta_tesoreria_id')
            ->where('g.pendiente', false)
            ->where(function ($q) {
                $q->whereColumn('m.cuenta_tesoreria_id', '<>', 'g.cuenta_tesoreria_id')
                    ->orWhereColumn('m.fecha', '<>', 'g.fecha')
                    ->orWhereRaw('ABS(m.monto + g.monto) > 0.005');
            })
            ->get(['m.id', 'g.cuenta_tesoreria_id', 'g.fecha', 'g.monto']);

        foreach ($desfasados as $d) {
            if ($this->dryRun) {
                continue;
            }

            DB::table('movimientos_tesoreria')->where('id', $d->id)->update([
                'cuenta_tesoreria_id' => $d->cuenta_tesoreria_id,
                'fecha' => $d->fecha,
                'monto' => -(float) $d->monto,
            ]);
        }

        if ($desfasados->isNotEmpty()) {
            $this->line("  Movimientos de gastos re-sincronizados con su gasto: {$desfasados->count()}");
        }
    }

    /** Normaliza un nombre de cuenta/medio para compararlo: espacios colapsados y minúsculas. */
    private function clave(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }
}
