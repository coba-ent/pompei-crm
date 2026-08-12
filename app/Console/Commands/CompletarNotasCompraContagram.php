<?php

namespace App\Console\Commands;

use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\NotaCreditoDebitoItem;
use App\Models\Producto;
use App\Services\Migracion\ComprasContagram;
use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Completa las notas de crédito/débito de compra que la migración dejó a medias.
 *
 * `MigrarComprasContagram::importarNota()` las creó sólo con monto, tipo y fecha. Este comando les
 * agrega lo que el Excel siempre tuvo y no se levantó —número de comprobante, renglones y
 * percepciones— y, sobre todo, **reconstruye el vínculo con la compra que ajustan**.
 *
 * Ese vínculo no está en el export: la columna "Documento que Ajusta" que Contagram muestra en
 * pantalla no se exporta, y el `N° Factura` de una nota es su propio número, no el de la factura
 * (verificado con la ND 15 → `A-0057-00067262`, mientras su compra es `A-0011-04800615`).
 *
 * La llave está en el otro archivo: `Compras c/ pago` trae, por compra, `Total NC` y `Total ND`.
 * Buscando qué subconjunto de notas del mismo proveedor suma exactamente ese total, el vínculo
 * queda **deducido, no adivinado**: o cierra al centavo, o esa compra se deja sin tocar.
 *
 * Cuando hay más de una combinación posible (notas de importe idéntico) no se elige ninguna: se
 * reportan como ambiguas para resolverlas a mano. Ver §8d del registro de casos.
 */
class CompletarNotasCompraContagram extends Command
{
    protected $signature = 'migracion:completar-notas-compra
        {--dry-run : No escribe nada; sólo reporta qué cambiaría}';

    protected $description = 'Completa las NC/ND de compra migradas: número, renglones, percepciones y su compra';

    /** Máximo de notas por combinación. Ninguna compra del histórico tiene más de 4. */
    private const MAX_NOTAS_POR_COMPRA = 4;

    private array $productos = [];

    public function handle(LectorExcelContagram $lector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $servicio = new ComprasContagram($lector, public_path('imports'));

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— COMPLETANDO NOTAS DE COMPRA —');

        $this->productos = Producto::whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();

        // Se leen todos los años de una: una nota puede estar en el export de un año distinto al
        // de la factura que ajusta, así que restringir las candidatas al año dejaba casos afuera.
        $notas = [];
        $compras = [];
        foreach (ComprasContagram::ANIOS as $anio) {
            $this->line("Leyendo {$anio}…");
            foreach ($servicio->delAnio($anio) as $legacyId => $c) {
                $c['familia'] === 'FC' ? $compras[$legacyId] = $c : $notas[$legacyId] = $c;
            }
        }
        $this->line(sprintf('Notas en los Excel: %d — Compras: %d', count($notas), count($compras)));

        [$mapeo, $ambiguas, $sinSolucion] = $this->emparejar($notas, $compras);

        $stats = array_fill_keys(
            ['completadas', 'vinculadas', 'items_creados', 'con_percepciones', 'no_estaban', 'sin_cambios'], 0
        );

        foreach ($notas as $legacyId => $nota) {
            $modelo = NotaCreditoDebito::where('legacy_id', $legacyId)->first();

            if (! $modelo) {
                $stats['no_estaban']++;

                continue;
            }

            $compraId = isset($mapeo[$legacyId])
                ? Compra::where('legacy_id', $mapeo[$legacyId])->value('id')
                : null;

            $cambios = $this->completar($modelo, $nota, $compraId, $dryRun, $stats);
            $cambios === [] ? $stats['sin_cambios']++ : $stats['completadas']++;
        }

        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], collect($stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());

        $this->avisar('Grupos ambiguos (no se tocaron)', $ambiguas);
        $this->avisar('Grupos sin solución (no se tocaron)', $sinSolucion);

        if ($dryRun) {
            $this->info('DRY RUN: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    /**
     * Empareja notas con compras por suma de importes.
     *
     * @return array{0: array<string,string>, 1: array<int,string>, 2: array<int,string>}
     *         [legacyId de la nota => legacyId de la compra], ambiguas, sin solución
     */
    private function emparejar(array $notas, array $compras): array
    {
        $mapeo = [];
        $ambiguas = [];
        $sinSolucion = [];
        $reclamadas = [];

        foreach ($compras as $legacyCompra => $compra) {
            foreach ([['NC', $compra['total_nc']], ['ND', $compra['total_nd']]] as [$familia, $objetivo]) {
                if ($objetivo < 0.005) {
                    continue;
                }

                $candidatas = array_filter(
                    $notas,
                    fn ($n) => $n['familia'] === $familia
                        && $n['proveedor'] === $compra['proveedor']
                        && abs($n['total']) > 0.005
                );

                $combos = $this->combinaciones($candidatas, $objetivo);
                $etiqueta = sprintf('%s (%s) %s=%s', $legacyCompra,
                    mb_substr($compra['proveedor'], 0, 20), $familia, number_format($objetivo, 2));

                if (count($combos) !== 1) {
                    count($combos) > 1 ? $ambiguas[] = $etiqueta : $sinSolucion[] = $etiqueta;

                    continue;
                }

                foreach ($combos[0] as $legacyNota) {
                    // Una nota no puede ajustar dos compras. Si pasara, la deducción no sería
                    // confiable y hay que revisarla a mano en vez de quedarse con la última.
                    if (isset($reclamadas[$legacyNota])) {
                        $ambiguas[] = "{$legacyNota}: la reclaman {$reclamadas[$legacyNota]} y {$legacyCompra}";
                        unset($mapeo[$legacyNota]);

                        continue;
                    }
                    $reclamadas[$legacyNota] = $legacyCompra;
                    $mapeo[$legacyNota] = $legacyCompra;
                }
            }
        }

        return [$mapeo, $ambiguas, $sinSolucion];
    }

    /**
     * Subconjuntos de $items cuya suma da $objetivo (tolerancia de un centavo).
     *
     * @return array<int, array<int, string>>
     */
    private function combinaciones(array $items, float $objetivo): array
    {
        $salida = [];
        $claves = array_keys($items);
        $n = count($claves);

        $buscar = function (int $desde, array $acum, float $suma) use (&$buscar, $items, $claves, $objetivo, $n, &$salida) {
            if (abs($suma - $objetivo) < 0.05 && $acum !== []) {
                $salida[] = $acum;

                return;
            }
            if (count($acum) >= self::MAX_NOTAS_POR_COMPRA || $suma > $objetivo + 0.05) {
                return;
            }
            for ($i = $desde; $i < $n; $i++) {
                $buscar($i + 1, [...$acum, $claves[$i]], $suma + abs($items[$claves[$i]]['total']));
            }
        };

        $buscar(0, [], 0.0);

        return $salida;
    }

    /** @return array<string, mixed> los campos que cambiaron (vacío si no hubo nada que hacer) */
    private function completar(NotaCreditoDebito $modelo, array $nota, ?int $compraId, bool $dryRun, array &$stats): array
    {
        $cambios = [];

        $nro = $this->nroComprobante($nota);
        if ($nro !== null && $modelo->nro_comprobante !== $nro) {
            $cambios['nro_comprobante'] = $nro;
        }

        if ($compraId !== null && $modelo->compra_id !== $compraId) {
            $cambios['compra_id'] = $compraId;
            $stats['vinculadas']++;
        }

        // Se compara el contenido y no si está vacío: así el comando es idempotente y una segunda
        // corrida corrige lo que haya quedado a medias en la primera.
        if ($nota['conceptos'] !== [] && ($modelo->impuestos ?? []) != $nota['conceptos']) {
            $cambios['impuestos'] = $nota['conceptos'];
            $stats['con_percepciones']++;
        }

        $faltanItems = $nota['items'] !== [] && $modelo->items()->count() === 0;

        if ($cambios === [] && ! $faltanItems) {
            return [];
        }

        if ($dryRun) {
            if ($faltanItems) {
                $stats['items_creados'] += count($nota['items']);
            }

            return $cambios ?: ['items' => count($nota['items'])];
        }

        DB::transaction(function () use ($modelo, $nota, $cambios, $faltanItems, &$stats) {
            if ($cambios !== []) {
                $modelo->fill($cambios);
                // Sin `saveQuietly` los observers reescribirían `updated_at` y dispararían
                // auditoría por un dato que ya existía en Contagram desde el día uno.
                $modelo->saveQuietly();
            }

            if (! $faltanItems) {
                return;
            }

            foreach ($nota['items'] as $item) {
                $legacy = $item['producto_legacy_id'];
                $productoId = $legacy !== null ? ($this->productos[(string) $legacy] ?? null) : null;

                $renglon = new NotaCreditoDebitoItem([
                    'nota_credito_debito_id' => $modelo->id,
                    'producto_id' => $productoId,
                    'cantidad' => abs($item['cantidad']),
                    'precio' => abs($item['precio_unitario']),
                    'descuento_pct' => 0,
                    'iva_pct' => $item['iva_pct'],
                    'origen' => 'venta_original',
                ]);
                $renglon->created_at = $modelo->created_at;
                $renglon->updated_at = $modelo->created_at;
                $renglon->save();
                $stats['items_creados']++;
            }
        });

        return $cambios ?: ['items' => count($nota['items'])];
    }

    private function nroComprobante(array $c): ?string
    {
        if (! $c['punto_venta'] || ! $c['nro_factura']) {
            return null;
        }

        return sprintf('%04d-%08d', (int) $c['punto_venta'], (int) $c['nro_factura']);
    }

    private function avisar(string $titulo, array $lineas): void
    {
        if ($lineas === []) {
            return;
        }

        $this->newLine();
        $this->warn("{$titulo}: ".count($lineas));
        foreach ($lineas as $linea) {
            $this->line("   {$linea}");
        }
    }
}
