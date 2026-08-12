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
                $this->reconstruirCajaChica();
                $this->vincularNotas();
                $this->recuperarNotasSinImporte();
                $this->vincularNotasCompra();
                $this->refecharPagos();
                $this->reconstruirPagos();

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

    /** Normaliza un nombre de cuenta/medio para compararlo: espacios colapsados y minúsculas. */
    private function clave(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }
}
