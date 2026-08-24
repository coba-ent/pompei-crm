<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Actualiza las ventas que se editaron en Contagram **después** del export con el que se importaron.
 *
 * Los Excel son una foto: una venta cargada en julio puede editarse en agosto —cambiarle el
 * producto, el precio o el descuento— y el archivo con el que se importó sigue mostrando el valor
 * original. Contra un "Informe de Ventas Detallado" fresco del 01/07 al 06/08 aparecieron 7 casos
 * por $149.398,60; el más claro es la venta 24019, que pasó de "Botiquín tríptico 63 /
 * $191.033,86" a "Botiquín 40 Recto / $115.409,99".
 *
 * **Lee el detallado directo y no reusa `ComprobantesContagram`** a propósito: ese servicio toma el
 * total de cabecera del `c/ cobro` (§3.9), que es justamente el archivo viejo cuyas ediciones no
 * llegaron. Acá el total tiene que salir del informe nuevo.
 *
 * Actualiza cabecera **e ítems**, y **no toca los cobros**: lo cobrado es un hecho aparte del
 * contenido del comprobante. Tampoco toca tesorería, que viene de los extractos.
 */
class RefrescarVentasEditadas extends Command
{
    protected $signature = 'migracion:refrescar-ventas
        {--dir=* : Carpeta(s) con el Informe de Ventas Detallado más reciente}
        {--dry-run : Sólo reporta las diferencias}';

    protected $description = 'Actualiza las ventas que se editaron en Contagram después del export';

    private const COLUMNAS_IVA = [
        '2.5' => 'IVA - 2,5%', '5' => 'IVA - 5%', '10.5' => 'IVA - 10,5%',
        '21' => 'IVA - 21%', '27' => 'IVA - 27%',
    ];

    public function handle(LectorExcelContagram $lector): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $archivos = [];
        foreach ((array) $this->option('dir') as $d) {
            $archivos = array_merge($archivos, glob(rtrim($d, '/\\').'/*.xlsx') ?: []);
        }

        if ($archivos === []) {
            $this->error('Falta --dir con la carpeta del informe.');

            return self::FAILURE;
        }

        // Agrupa los renglones por comprobante. Las notas quedan afuera: tienen su propia serie de
        // Id y compararlas contra una venta del mismo número da falsos positivos.
        $grupos = [];
        foreach ($archivos as $path) {
            foreach ($lector->leer($path)['filas'] as $fila) {
                $id = $lector->texto($fila['Id'] ?? null);
                $tipo = (string) ($lector->texto($fila['Tipo de Comprobante'] ?? null) ?? '');

                if ($id === null || str_starts_with($tipo, 'NC') || str_starts_with($tipo, 'ND')) {
                    continue;
                }

                $grupos[$id][] = $fila;
            }
        }

        $this->line('Comprobantes en el informe: '.count($grupos));

        $filas = [];
        $itemsNuevos = 0;

        foreach ($grupos as $id => $renglones) {
            $venta = Venta::where('legacy_id', "2026-FC-{$id}")->first();

            if ($venta === null) {
                continue;
            }

            // El `Total Venta` se repite en cada renglón cuando es dato de cabecera y difiere cuando
            // viene por renglón. **Si difiere hay que sumar TODOS los renglones, no los valores
            // distintos**: la venta 24330 tiene tres renglones de 58.314,23 + 58.314,23 + 38.720,00
            // y sumar los únicos daba 97.034,23 en vez de 155.348,46 — que es además el importe con
            // el que se emitió su CAE (B 0009-00000003).
            $valores = array_map(
                fn ($f) => round((float) ($lector->numero($f['Total Venta'] ?? null) ?? 0), 2), $renglones);
            $distintos = array_unique($valores);
            $total = count($distintos) === 1 ? reset($distintos) : array_sum($valores);

            if (abs((float) $venta->total - $total) < 0.02) {
                continue;
            }

            $items = array_map(fn ($f) => $this->item($lector, $f), $renglones);
            $subtotal = round(array_sum(array_column($items, 'subtotal')), 2);
            $sinDescuento = round(array_sum(array_column($items, '_sin_descuento')), 2);

            $filas[] = [$venta->id, $venta->fecha_emision?->format('Y-m-d'),
                number_format((float) $venta->total, 2), number_format($total, 2),
                number_format($total - (float) $venta->total, 2), count($items)];
            $itemsNuevos += count($items);

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($venta, $total, $subtotal, $sinDescuento, $items) {
                $venta->update([
                    'total' => $total,
                    'subtotal_sin_descuento' => $sinDescuento,
                    'descuento' => round($sinDescuento - $subtotal, 2),
                    'subtotal_con_descuento' => $subtotal,
                ]);

                // Los renglones se reemplazan enteros: al editar la venta pudo cambiar el producto,
                // no sólo el importe.
                VentaItem::where('venta_id', $venta->id)->delete();

                foreach ($items as $item) {
                    unset($item['_sin_descuento']);
                    // `$item` no trae `costo_unitario`, así que la línea recreada queda en NULL
                    // y sigue cayendo al promedio de compras. Es lo correcto: esto refresca ventas
                    // históricas de Contagram, que nunca tuvieron costo congelado (spec 075).
                    $vi = new VentaItem($item + ['venta_id' => $venta->id]);
                    $vi->created_at = $venta->created_at;
                    $vi->updated_at = $venta->created_at;
                    $vi->save();
                }
            });
        }

        $this->newLine();
        $this->table(['Venta', 'Fecha', 'Total viejo', 'Total nuevo', 'Diferencia', 'Ítems'], $filas);
        $this->line('Ventas actualizadas: '.count($filas)." · renglones nuevos: {$itemsNuevos}");

        if ($dryRun) {
            $this->info('DRY RUN: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function item(LectorExcelContagram $lector, array $f): array
    {
        $cantidad = (float) ($lector->numero($f['Cantidad'] ?? null) ?? 1);
        $precio = (float) ($lector->numero($f['Precio Unitario'] ?? null) ?? 0);

        // El renglón puede estar bonificado, y el detallado lo trae desglosado. `cantidad × precio`
        // es el precio de lista: usarlo solo dejaba el neto inflado con el total correcto (el mismo
        // defecto que tenía `ComprobantesContagram::armarItem`, corregido el 16/08/2026).
        $sinDescuento = $lector->numero($f['Subtotal sin Descuento'] ?? null) ?? round($cantidad * $precio, 2);
        $subtotal = round($lector->numero($f['Subtotal con Descuento'] ?? null) ?? $sinDescuento, 2);
        $descuentoPct = $sinDescuento > 0.005
            ? max(0.0, min(100.0, round(($sinDescuento - $subtotal) / $sinDescuento * 100, 2)))
            : 0.0;

        $iva = 0.0;
        foreach (self::COLUMNAS_IVA as $pct => $columna) {
            if (abs((float) ($lector->numero($f[$columna] ?? null) ?? 0)) > 0.005) {
                $iva = (float) $pct;
                break;
            }
        }

        // El Id del producto es el primer token numérico del código, igual que en el import.
        $codigo = $lector->texto($f['Código'] ?? null);
        $legacy = ($codigo !== null && preg_match('/^(\d+)/', $codigo, $m)) ? (int) $m[1] : null;

        return [
            'producto_id' => $legacy === null ? null : Producto::whereKey($legacy)->value('id'),
            'descripcion' => $lector->texto($f['Producto/Servicio'] ?? null) ?? 'Sin descripción',
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'descuento_pct' => $descuentoPct,
            'iva_pct' => $iva,
            'subtotal' => $subtotal,
            // Sólo para el subtotal de cabecera; se saca antes de construir el VentaItem.
            '_sin_descuento' => round($sinDescuento, 2),
            'subtotal_con_iva' => round($subtotal * (1 + $iva / 100), 2),
        ];
    }
}
