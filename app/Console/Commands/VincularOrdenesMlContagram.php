<?php

namespace App\Console\Commands;

use App\Enums\MercadoLibre\EstadoConversion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Vuelve a atar cada orden de Mercado Libre con la venta que le corresponde en la base nueva.
 *
 * Las órdenes se copiaron del VPS **sin** `venta_id` a propósito: ahí apuntaban a ventas creadas
 * por el CRM, cuyos id acá pertenecen a ventas de Contagram completamente distintas.
 *
 * El puente es el **"Listado de Órdenes de Mercado Libre"**, que trae `Orden ID` + `Nombre` +
 * `Apellido`. Hace falta porque `ml_ordenes` sólo guarda el apodo (`comprador_apodo`) y Contagram
 * guarda al cliente con el nombre real — `STRICKERKARIN20230409152855` contra `Karin Stricker`.
 * Sin ese dato el único criterio es importe+fecha, que se equivoca: el 30/07 hay 21 ventas del
 * mismo importe el mismo día.
 *
 * Dos detalles del importe que no se pueden ignorar:
 *
 * 1. **Contagram redondea**: la orden de $389.934,68 queda facturada en $389.785,63 ($149,05 de
 *    diferencia). Por eso la tolerancia es relativa (0,5%) y no de centavos.
 * 2. **Contagram agrupa**: dos órdenes del mismo comprador pueden salir en una sola factura
 *    ($14.204,19 + $27.755,20 = $41.959,39, exacto). El segundo paso las detecta, pero
 *    `ml_ordenes.venta_id` es **UNIQUE**, así que sólo la primera orden del grupo se puede vincular
 *    y la otra queda suelta a propósito — el comando la lista con la venta que le corresponde para
 *    que quede constancia.
 *
 * Las órdenes **canceladas** en ML no tienen venta y no son un faltante.
 */
class VincularOrdenesMlContagram extends Command
{
    protected $signature = 'migracion:vincular-ordenes-ml
        {--dir=* : Carpeta(s) con el Listado de Órdenes de Mercado Libre}
        {--extra=* : Pares orden:venta a forzar a mano (ej. 414:24469)}
        {--facturada-en=* : Pares orden:venta de órdenes SIN venta propia, facturadas dentro de esa venta (ej. 101:24399)}
        {--dry-run : Sólo reporta, no escribe}';

    protected $description = 'Asocia las órdenes de Mercado Libre con su venta usando el listado de órdenes';

    /** Contagram redondea el total facturado; nunca por más de unos pesos sobre el total de ML. */
    private const TOLERANCIA = 0.005;

    /** La factura sale el mismo día o unos días después de la orden, nunca mucho antes. */
    private const DIAS_ANTES = 1;
    private const DIAS_DESPUES = 7;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $nombres = $this->nombresDelListado();

        if ($nombres === []) {
            $this->error('No se leyó ninguna orden del listado. Revisá --dir.');

            return self::FAILURE;
        }

        $ordenes = DB::table('ml_ordenes')
            ->select('id', 'ml_order_id', 'total', 'fecha_creada', 'comprador_apodo', 'estado_orden')
            ->orderBy('fecha_creada')->get();

        $ventas = DB::table('ventas as v')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->whereNull('v.deleted_at')
            ->where('v.fecha_emision', '>=', '2026-06-01')
            ->select('v.id', 'v.total', 'v.fecha_emision', 'c.nombre as cliente')
            ->get();

        $this->line('Órdenes: '.$ordenes->count().' · con nombre real: '.
            $ordenes->filter(fn ($o) => isset($nombres[(string) $o->ml_order_id]))->count().
            ' · ventas candidatas: '.$ventas->count());

        $vinculos = [];   // orden_id => ['venta' => id, 'via' => ..., 'nombre' => ...]

        // Paso 1 — una sola venta del mismo comprador, mismo importe (con tolerancia), fecha compatible.
        foreach ($ordenes as $o) {
            $nombre = $nombres[(string) $o->ml_order_id] ?? null;

            if ($nombre === null) {
                continue;
            }

            $hit = $ventas->filter(fn ($v) => $this->mismoNombre($nombre, $v->cliente)
                && $this->fechaCompatible($o->fecha_creada, $v->fecha_emision)
                && $this->mismoImporte((float) $o->total, (float) $v->total));

            if ($hit->count() === 1) {
                $vinculos[$o->id] = ['venta' => $hit->first()->id, 'via' => 'nombre+importe', 'nombre' => $nombre];
            }
        }

        // Paso 2 — varias órdenes del mismo comprador que suman el total de una venta.
        $sueltas = [];
        $agrupadasSueltas = [];   // orden => venta que le corresponde pero que ya tomó otra orden
        foreach ($ordenes as $o) {
            $nombre = $nombres[(string) $o->ml_order_id] ?? null;

            if ($nombre === null || isset($vinculos[$o->id])) {
                continue;
            }

            $sueltas[$this->normalizar($nombre)][] = [$o, $nombre];
        }

        foreach ($sueltas as $grupo) {
            if (count($grupo) < 2) {
                continue;
            }

            $suma = array_sum(array_map(fn ($g) => (float) $g[0]->total, $grupo));
            $nombre = $grupo[0][1];

            $venta = $ventas->first(fn ($v) => $this->mismoNombre($nombre, $v->cliente)
                && $this->mismoImporte($suma, (float) $v->total));

            if ($venta === null) {
                continue;
            }

            // Sólo la primera: el UNIQUE de `venta_id` no admite dos órdenes en la misma venta.
            foreach ($grupo as $i => [$o, $nom]) {
                if ($i === 0) {
                    $vinculos[$o->id] = ['venta' => $venta->id, 'via' => 'agrupada', 'nombre' => $nom];
                } else {
                    $agrupadasSueltas[$o->id] = $venta->id;
                }
            }
        }

        // Paso 3 — los pares que el usuario resolvió a ojo contra Contagram. Mandan sobre lo anterior:
        // cuando el grupo se agrupó en una factura, el paso 2 elige la primera orden por fecha, pero
        // Contagram marca "Venta" en una en particular (la o124 de $27.755,20, no la o123 de
        // $14.204,19). Si un extra reclama una venta que ya tomó otra orden, la otra se libera.
        foreach ((array) $this->option('extra') as $par) {
            [$orden, $venta] = array_map('intval', explode(':', $par));

            foreach ($vinculos as $otro => $v) {
                if ($v['venta'] === $venta && $otro !== $orden) {
                    unset($vinculos[$otro]);
                    $agrupadasSueltas[$otro] = $venta;
                }
            }

            $vinculos[$orden] = ['venta' => $venta, 'via' => 'manual', 'nombre' => $nombres[(string) $ordenes->firstWhere('id', $orden)?->ml_order_id] ?? '?'];
        }

        // Contagram deja "Pendiente" a la orden que facturó dentro de otra. No tiene venta propia y
        // no la va a tener nunca, así que se marca a mano para que no quede en `lista` — que es el
        // único estado que habilita al cron a crearle una venta.
        foreach ((array) $this->option('facturada-en') as $par) {
            [$orden, $venta] = array_map('intval', explode(':', $par));
            unset($vinculos[$orden]);
            $agrupadasSueltas[$orden] = $venta;
        }

        // Las ventas se reparten: sólo el paso 2 puede repetir una, y ahí es a propósito.
        $porVenta = [];
        foreach ($vinculos as $ordenId => $v) {
            $porVenta[$v['venta']][] = $ordenId;
        }

        foreach ($porVenta as $ventaId => $ordenIds) {
            if (count($ordenIds) > 1 && $vinculos[$ordenIds[0]]['via'] === 'nombre+importe') {
                $this->error("Venta {$ventaId} reclamada por las órdenes ".implode(', ', $ordenIds).' — no se escribe nada.');

                return self::FAILURE;
            }
        }

        $sinVincular = $ordenes->reject(fn ($o) => isset($vinculos[$o->id]));

        $this->newLine();
        $this->table(['Vía', 'Órdenes'], [
            ['nombre+importe', count(array_filter($vinculos, fn ($v) => $v['via'] === 'nombre+importe'))],
            ['agrupada', count(array_filter($vinculos, fn ($v) => $v['via'] === 'agrupada'))],
            ['manual', count(array_filter($vinculos, fn ($v) => $v['via'] === 'manual'))],
            ['sin vincular', $sinVincular->count()],
        ]);

        $this->newLine();
        $this->line('Sin vincular:');
        foreach ($sinVincular as $o) {
            $nota = isset($agrupadasSueltas[$o->id])
                ? "  <- facturada en la venta {$agrupadasSueltas[$o->id]}, que ya tomó la otra orden del grupo"
                : '';

            $this->line(sprintf('  o%-4d %s  %14s  %-10s %s%s',
                $o->id, substr($o->fecha_creada, 0, 10), number_format((float) $o->total, 2),
                $o->estado_orden, $nombres[(string) $o->ml_order_id] ?? $o->comprador_apodo, $nota));
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY RUN: no se escribió nada.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($vinculos, $agrupadasSueltas) {
            // Primero se sueltan, después se asignan: si no, una orden que en una corrida anterior
            // tomó la venta del grupo choca contra el UNIQUE cuando la reclama la correcta.
            // Las que Contagram facturó dentro de otra orden quedan pagadas y sin venta propia.
            // `requiere_atencion` es el estado honesto: no habilita crear venta (habilitaCrearVenta()
            // sólo es true en `lista`), así que el cron no las va a duplicar.
            foreach ($agrupadasSueltas as $ordenId => $ventaId) {
                DB::table('ml_ordenes')->where('id', $ordenId)->update([
                    'venta_id' => null,
                    'estado_conversion' => EstadoConversion::RequiereAtencion->value,
                    'motivo_detalle' => "Facturada en la venta {$ventaId} junto con otra orden del mismo comprador.",
                ]);
            }

            foreach ($vinculos as $ordenId => $v) {
                DB::table('ml_ordenes')->where('id', $ordenId)->update([
                    'venta_id' => $v['venta'],
                    'estado_conversion' => EstadoConversion::Convertida->value,
                    // Puede traer el motivo de una corrida donde esta orden era la suelta del grupo.
                    'motivo_detalle' => null,
                ]);

                // La venta también apunta a la orden: es lo que evita que el cron la vuelva a crear.
                DB::table('ventas')->where('id', $v['venta'])->update(['origen' => 'mercadolibre']);
            }

            // Red de seguridad: ninguna orden puede quedar diciendo "convertida" sin venta. Pasa con
            // las que el VPS había convertido y acá no encontraron factura propia.
            DB::table('ml_ordenes')
                ->whereNull('venta_id')
                ->where('estado_conversion', EstadoConversion::Convertida->value)
                ->update([
                    'estado_conversion' => EstadoConversion::RequiereAtencion->value,
                    'motivo_detalle' => DB::raw("COALESCE(motivo_detalle, 'Sin venta propia en Contagram: revisar si se facturó dentro de otra orden.')"),
                ]);
        });

        $this->newLine();
        $this->info('Vinculadas: '.count($vinculos).' órdenes.');

        return self::SUCCESS;
    }

    /** @return array<string, string> Orden ID => "Nombre Apellido" */
    private function nombresDelListado(): array
    {
        $nombres = [];

        foreach ((array) $this->option('dir') as $dir) {
            foreach (glob(rtrim($dir, '/\\').'/*.xlsx') ?: [] as $path) {
                $lector = IOFactory::createReaderForFile($path);
                $lector->setReadDataOnly(true);
                $filas = $lector->load($path)->getActiveSheet()->toArray(null, false, false, false);

                $cab = array_flip(array_map('strval', array_shift($filas)));

                foreach ($filas as $fila) {
                    $orden = trim((string) ($fila[$cab['Orden ID']] ?? ''));

                    if ($orden === '') {
                        continue;
                    }

                    $nombres[$orden] = trim(($fila[$cab['Nombre']] ?? '').' '.($fila[$cab['Apellido']] ?? ''));
                }
            }
        }

        return $nombres;
    }

    private function normalizar(?string $s): string
    {
        $s = strtolower(trim((string) $s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return preg_replace('/[^a-z]/', '', $s);
    }

    /**
     * Contagram invierte nombre y apellido con total libertad ("OTERO DARIO" contra "Dario Otero"),
     * así que además de comparar el texto se comparan las letras ordenadas.
     */
    private function mismoNombre(string $orden, ?string $cliente): bool
    {
        $a = $this->normalizar($orden);
        $b = $this->normalizar($cliente);

        if ($a === '' || strlen($b) < 5) {
            return false;
        }

        $letrasA = str_split($a); sort($letrasA);
        $letrasB = str_split($b); sort($letrasB);

        return $a === $b
            || implode('', $letrasA) === implode('', $letrasB)
            || str_contains($b, $a)
            || str_contains($a, $b);
    }

    private function mismoImporte(float $orden, float $venta): bool
    {
        return abs($venta - $orden) <= max(1.0, $orden * self::TOLERANCIA);
    }

    private function fechaCompatible(string $orden, string $venta): bool
    {
        $dias = (strtotime(substr($venta, 0, 10)) - strtotime(substr($orden, 0, 10))) / 86400;

        return $dias >= -self::DIAS_ANTES && $dias <= self::DIAS_DESPUES;
    }
}
