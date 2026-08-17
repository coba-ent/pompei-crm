<?php

namespace App\Console\Commands;

use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Baja a la línea la bonificación que la importación dejó sumada en la cabecera.
 *
 * Contagram registra la bonificación **por renglón** ("% Bonif." en el detalle de venta). El
 * importador la guardaba en `ventas.descuento` sin descontarla de `venta_items.subtotal`, porque
 * `ComprobantesContagram::armarItem()` trataba un "Subtotal con Descuento" **en cero** como una
 * celda vacía y caía al fallback `cantidad × precio` — que es el precio de lista. En un renglón
 * bonificado al 100% el cero es el dato correcto, no un hueco.
 *
 * Efecto del defecto: 27 ventas de 23.785 con el neto de línea inflado en $1.733.682. **Los totales
 * están bien**: no toca `ventas.total`, ni cobros, ni tesorería, ni stock. Lo único que se movía era
 * lo derivado de la línea — "Precio Neto" y "Resultado" del Informe de Ventas y las medidas del
 * pivot (spec 069), que es donde se descubrió comparando el cruce contra el KPI.
 *
 * La causa raíz ya está corregida en el importador; este comando repara lo ya importado.
 *
 * Dos caminos para reconstruir cada línea, en este orden:
 *
 *  1. **Desde el origen**: el export por ítem trae "Subtotal sin Descuento" / "Subtotal con
 *     Descuento" por renglón. Se prefiere el "Informe de Ventas Detallado" más fresco que
 *     contenga el Id (refleja ediciones posteriores, igual que `migracion:refrescar-ventas`) y
 *     recién después `Ventas {año}.xlsx`. Se exige que la cantidad de renglones y los subtotales
 *     coincidan con los ítems guardados; si no alinean, no se toca.
 *  2. **Regla del 100%**: en 2021 el export no pobla ninguna columna de subtotal. Ahí, si la venta
 *     tiene total 0 y su descuento de cabecera es igual al neto de las líneas, la bonificación
 *     total es la única lectura posible y se aplica 100% a todos los renglones.
 *
 * Idempotente: el criterio de detección (`subtotal_sin_descuento = subtotal_con_descuento` con
 * descuento > 0) deja de cumplirse una vez corregida la venta.
 */
class CorregirBonificacionPorLinea extends Command
{
    protected $signature = 'migracion:corregir-bonificacion
        {--aplicar : Escribe los cambios (por defecto sólo reporta)}
        {--imports= : Carpeta con Ventas {año}.xlsx (por defecto public/imports/Ventas)}
        {--dir=* : Carpeta(s) con Informe de Ventas Detallado, para las ventas editadas}
        {--sql= : En vez de escribir, vuelca el plan como UPDATEs con guarda a este archivo}';

    protected $description = 'Baja a la línea la bonificación que la importación dejó en la cabecera';

    public function handle(LectorExcelContagram $lector): int
    {
        $aplicar = (bool) $this->option('aplicar');

        // Detección por el SÍNTOMA y no por la forma de la cabecera: la venta tiene descuento y su
        // desglose por línea no cierra contra el total. El primer recorte que se probó
        // (`subtotal_sin_descuento = subtotal_con_descuento`) dejaba afuera las ventas donde el
        // importador sí había separado los subtotales de cabecera pero igual no había bajado la
        // bonificación al renglón — 109 de las 136, incluida la más grande de todas.
        $desglose = '(SELECT COALESCE(SUM(i.subtotal_con_iva), 0) FROM venta_items i WHERE i.venta_id = ventas.id)';

        $afectadas = DB::table('ventas')
            ->whereNull('deleted_at')
            ->where('descuento', '>', 0.005)
            ->whereRaw("ABS({$desglose} - ventas.total) > 1")
            ->orderByDesc('descuento')
            ->get(['id', 'legacy_id', 'descuento', 'total']);

        if ($afectadas->isEmpty()) {
            $this->info('No hay ventas con la bonificación colgada de la cabecera. Nada que hacer.');

            return self::SUCCESS;
        }

        $this->info("Ventas afectadas: {$afectadas->count()}");

        $fuentes = $this->fuentes($lector, $afectadas);

        $planItems = [];
        $planVentas = [];
        $filas = [];
        $sinResolver = [];

        foreach ($afectadas as $venta) {
            $idOrigen = $this->idOrigen($venta->legacy_id);
            $items = DB::table('venta_items')->where('venta_id', $venta->id)->orderBy('id')->get();
            $neto = round($items->sum(fn ($i) => (float) $i->subtotal), 2);

            $origen = $fuentes[$idOrigen]['archivo'] ?? '(sin archivo)';
            $lineas = $this->desdeElOrigen($lector, $items, $fuentes[$idOrigen]['filas'] ?? []);

            if ($lineas === null) {
                $lineas = $this->porRegla100($items, $neto, $venta);
                $origen = $lineas === null ? $origen : 'regla 100% (total 0)';
            }

            if ($lineas === null) {
                $sinResolver[] = "venta {$venta->id} ({$venta->legacy_id}): no se puede reconstruir ".
                    'desde '.$origen.' y no es un 100% inequívoco';

                continue;
            }

            $netoNuevo = 0.0;
            foreach ($lineas as $itemId => [$sinDesc, $conDesc, $ivaPct]) {
                $iva = is_numeric($ivaPct) ? (float) $ivaPct : 0.0;
                $planItems[$itemId] = [
                    'descuento_pct' => $sinDesc > 0.005
                        ? max(0.0, min(100.0, round(($sinDesc - $conDesc) / $sinDesc * 100, 2)))
                        : 0.0,
                    'subtotal' => $conDesc,
                    'subtotal_con_iva' => round($conDesc * (1 + $iva / 100), 2),
                    // Valor que la línea tiene que tener AHORA para que el cambio sea válido. Es la
                    // guarda del volcado SQL: si la fila ya cambió, ese UPDATE no toca nada.
                    '_antes' => $sinDesc,
                ];
                $netoNuevo += $conDesc;
            }
            $netoNuevo = round($netoNuevo, 2);

            $planVentas[$venta->id] = [
                'subtotal_sin_descuento' => $neto,
                'subtotal_con_descuento' => $netoNuevo,
                'descuento' => round($neto - $netoNuevo, 2),
                // Guarda del volcado SQL, igual que `_antes` en las líneas. No se escribe nunca.
                '_total' => (float) $venta->total,
            ];

            $filas[] = [$venta->id, substr($origen, 0, 44), count($lineas),
                number_format($neto, 2), number_format($netoNuevo, 2),
                number_format($neto - $netoNuevo, 2)];
        }

        $this->newLine();
        $this->table(['Venta', 'Fuente', 'Líneas', 'Neto antes', 'Neto después', 'Baja'], $filas);

        $baja = array_sum(array_map(
            fn ($v) => $v['subtotal_sin_descuento'] - $v['subtotal_con_descuento'], $planVentas
        ));
        $this->line('Líneas a modificar: '.count($planItems).
            ' · baja del Precio Neto: '.number_format($baja, 2));

        foreach ($sinResolver as $p) {
            $this->warn("NO SE TOCA — $p");
        }

        $plata = fn () => [
            'Total de ventas' => (float) DB::table('ventas')->whereNull('deleted_at')->sum('total'),
            'Cobros' => (float) DB::table('cobros')->sum('monto'),
            'Movimientos de tesorería' => (float) DB::table('movimientos_tesoreria')->sum('monto'),
            'Movimientos de stock' => (int) DB::table('movimientos_stock')->count(),
        ];
        $antes = $plata();

        if ($archivoSql = $this->option('sql')) {
            $this->volcarSql($archivoSql, $planItems, $planVentas);

            return self::SUCCESS;
        }

        if (! $aplicar) {
            $this->newLine();
            $this->info('DRY RUN: no se escribió nada. Agregar --aplicar para ejecutar.');

            return self::SUCCESS;
        }

        // En tests el archivo no sirve para nada (la base se descarta al terminar) y ensucia
        // `storage/app` con un backup por cada caso que corre.
        if (! app()->runningUnitTests()) {
            $backup = storage_path('app/backup_bonificacion_'.date('Ymd_His').'.json');
            file_put_contents($backup, json_encode([
                'venta_items' => DB::table('venta_items')->whereIn('id', array_keys($planItems))->get(),
                'ventas' => DB::table('ventas')->whereIn('id', array_keys($planVentas))
                    ->get(['id', 'subtotal_sin_descuento', 'descuento', 'subtotal_con_descuento']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("Backup: {$backup}");
        }

        // `DB::table` a propósito y no Eloquent: no hay observers que disparar. Los de venta mueven
        // stock, y acá no cambia ni una unidad — sólo el importe bonificado de cada renglón.
        DB::transaction(function () use ($planItems, $planVentas) {
            foreach ($planItems as $itemId => $cambios) {
                DB::table('venta_items')->where('id', $itemId)
                    ->update(collect($cambios)->except('_antes')->all() + ['updated_at' => now()]);
            }
            foreach ($planVentas as $ventaId => $cambios) {
                DB::table('ventas')->where('id', $ventaId)
                    ->update(collect($cambios)->except('_total')->all() + ['updated_at' => now()]);
            }
        });

        $this->newLine();
        $this->line('Control de que no se movió nada más:');
        foreach ($plata() as $etiqueta => $valor) {
            $igual = abs($antes[$etiqueta] - $valor) < 0.005;
            $this->line(sprintf('  %-26s %18s → %18s   %s', $etiqueta,
                number_format($antes[$etiqueta], 2), number_format($valor, 2),
                $igual ? 'IGUAL' : '*** CAMBIÓ ***'));

            if (! $igual) {
                $this->error('Algo movió una cifra que este comando no debería tocar. Revisar el backup.');

                return self::FAILURE;
            }
        }

        $this->info('Listo.');

        return self::SUCCESS;
    }

    /**
     * Vuelca el plan como SQL para correrlo en otro servidor.
     *
     * Existe porque el servidor de producción **no tiene los Excel de origen** (son cientos de MB),
     * así que allá el comando no podría reconstruir casi nada. El plan se calcula acá, contra una
     * copia de esa base, y se lleva ya resuelto.
     *
     * Cada UPDATE lleva su **guarda**: sólo escribe si la fila todavía tiene el valor que tenía
     * cuando se calculó el plan. Si producción se movió desde entonces, esa fila simplemente no se
     * toca en vez de pisarse con un dato calculado sobre otra versión.
     */
    private function volcarSql(string $archivo, array $planItems, array $planVentas): void
    {
        $n = fn (float $v) => number_format($v, 2, '.', '');

        $sql = ["-- Bonificación por línea: plan generado el ".date('d/m/Y H:i')." desde ".
            config('database.connections.'.config('database.default').'.database').".",
            '-- '.count($planItems).' líneas en '.count($planVentas).' ventas.',
            '-- Cada UPDATE tiene guarda: si la fila ya cambió, no se toca.',
            '-- NO modifica ventas.total, cobros, tesorería ni stock.',
            '',
            'START TRANSACTION;',
            ''];

        foreach ($planItems as $itemId => $c) {
            $sql[] = sprintf(
                'UPDATE venta_items SET descuento_pct = %s, subtotal = %s, subtotal_con_iva = %s, '.
                'updated_at = NOW() WHERE id = %d AND ABS(subtotal - %s) < 0.02;',
                $n($c['descuento_pct']), $n($c['subtotal']), $n($c['subtotal_con_iva']),
                $itemId, $n($c['_antes'])
            );
        }

        $sql[] = '';

        foreach ($planVentas as $ventaId => $c) {
            $sql[] = sprintf(
                'UPDATE ventas SET subtotal_sin_descuento = %s, descuento = %s, '.
                'subtotal_con_descuento = %s, updated_at = NOW() WHERE id = %d AND ABS(total - %s) < 0.02;',
                $n($c['subtotal_sin_descuento']), $n($c['descuento']), $n($c['subtotal_con_descuento']),
                $ventaId, $n($c['_total'])
            );
        }

        $sql[] = '';
        $sql[] = '-- Control: estas tres cifras tienen que ser IDÉNTICAS a las de antes de correr esto.';
        $sql[] = 'SELECT ROUND(SUM(total),2) AS total_ventas FROM ventas WHERE deleted_at IS NULL;';
        $sql[] = 'SELECT ROUND(SUM(monto),2) AS cobros FROM cobros;';
        $sql[] = 'SELECT ROUND(SUM(monto),2) AS tesoreria FROM movimientos_tesoreria;';
        $sql[] = '';
        $sql[] = '-- Si las tres coinciden: COMMIT;   si no: ROLLBACK;';
        $sql[] = '';

        file_put_contents($archivo, implode("\n", $sql)."\n");

        $this->info("Plan escrito en {$archivo} — ".count($planItems).' líneas, '.
            count($planVentas)." ventas.\nSe abre con START TRANSACTION y NO tiene COMMIT: ".
            'hay que revisar las cifras de control y confirmar a mano.');
    }

    /**
     * Renglones de origen por Id de Contagram: primero los detallado más frescos, después el año.
     *
     * @return array<string, array{archivo: string, filas: list<array<string, mixed>>}>
     */
    private function fuentes(LectorExcelContagram $lector, $afectadas): array
    {
        $buscados = [];
        foreach ($afectadas as $v) {
            $buscados[$this->idOrigen($v->legacy_id)] = $v;
        }

        $detallado = [];
        foreach ((array) $this->option('dir') as $dir) {
            $detallado = array_merge($detallado, glob(rtrim($dir, '/\\').'/*.xlsx') ?: []);
        }
        rsort($detallado);

        $fuentes = [];
        foreach ($detallado as $path) {
            $datos = $lector->leer($path);

            // En esas carpetas conviven extractos de cuentas, informes de gastos y de movimientos.
            // Sin este filtro, un archivo de otra cosa con una columna `Id` se toma como fuente de
            // renglones de venta y arruina el apareo.
            if (array_diff(['Producto/Servicio', 'Subtotal sin Descuento', 'Subtotal con Descuento'],
                $datos['header']) !== []) {
                continue;
            }

            $delArchivo = [];
            foreach ($datos['filas'] as $fila) {
                $id = (string) ($fila['Id'] ?? '');
                if (isset($buscados[$id]) && ! isset($fuentes[$id])) {
                    $delArchivo[$id][] = $fila;
                }
            }
            foreach ($delArchivo as $id => $filas) {
                $fuentes[$id] = ['archivo' => basename($path), 'filas' => $filas];
            }
        }

        // Lo que no apareció en ningún detallado se busca en el export del año.
        $base = rtrim((string) ($this->option('imports') ?: public_path('imports/Ventas')), '/\\');
        $porAnio = [];
        foreach ($buscados as $id => $v) {
            if (! isset($fuentes[$id])) {
                $porAnio[explode('-', $v->legacy_id)[0]][$id] = true;
            }
        }

        // `Ventas 2023.xlsx` no trae encabezado (quedó pegado al final); se le presta el de 2022,
        // que es idéntico en los seis años. Mismo criterio que `MigrarVentasContagram`.
        $canonico = is_file("{$base}/Ventas 2022.xlsx")
            ? $lector->leer("{$base}/Ventas 2022.xlsx")['header']
            : null;

        foreach ($porAnio as $anio => $ids) {
            $path = "{$base}/Ventas {$anio}.xlsx";
            if (! is_file($path)) {
                $this->warn("No está {$path}: esas ventas sólo podrán resolverse por la regla del 100%.");

                continue;
            }

            foreach ($lector->leer($path, $canonico)['filas'] as $fila) {
                $id = (string) ($fila['Id'] ?? '');
                if (isset($ids[$id])) {
                    $fuentes[$id]['archivo'] = "Ventas {$anio}.xlsx";
                    $fuentes[$id]['filas'][] = $fila;
                }
            }
        }

        return $fuentes;
    }

    /**
     * Alinea los renglones del Excel con los ítems guardados y devuelve [sinDesc, conDesc, iva].
     *
     * Devuelve `null` si no alinean: en ese caso es preferible no tocar la venta a repartir un
     * descuento adivinado sobre líneas equivocadas.
     *
     * @return array<int, array{float, float, string|null}>|null
     */
    private function desdeElOrigen(LectorExcelContagram $lector, $items, array $filas): ?array
    {
        if (count($filas) !== $items->count() || $filas === []) {
            return null;
        }

        $lineas = [];
        foreach ($items as $i => $item) {
            $sinDesc = $lector->numero($filas[$i]['Subtotal sin Descuento'] ?? null);
            $conDesc = $lector->numero($filas[$i]['Subtotal con Descuento'] ?? null);

            // El subtotal guardado tiene que ser el "sin descuento" del renglón: es lo que dejó el
            // defecto. Si no coincide, el orden no es el mismo y no hay cómo aparearlos.
            if ($sinDesc === null || $conDesc === null
                || abs(round($sinDesc, 2) - (float) $item->subtotal) > 0.02) {
                return null;
            }

            $lineas[$item->id] = [round($sinDesc, 2), round($conDesc, 2), $item->iva_pct];
        }

        return $lineas;
    }

    /**
     * Total 0 con el descuento de cabecera igual al neto ⇒ todos los renglones al 100%.
     *
     * @return array<int, array{float, float, string|null}>|null
     */
    private function porRegla100($items, float $neto, object $venta): ?array
    {
        if (abs((float) $venta->total) > 0.005 || abs($neto - (float) $venta->descuento) > 0.05) {
            return null;
        }

        $lineas = [];
        foreach ($items as $item) {
            $lineas[$item->id] = [(float) $item->subtotal, 0.0, $item->iva_pct];
        }

        return $lineas;
    }

    /** "2026-FC-24209" => "24209" */
    private function idOrigen(string $legacyId): string
    {
        $partes = explode('-', $legacyId);

        return end($partes);
    }
}
