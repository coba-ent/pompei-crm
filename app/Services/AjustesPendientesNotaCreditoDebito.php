<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cantidad pendiente de ajuste en un comprobante (Venta o Compra).
 *
 * Spec 045 data-model.md "Regla derivada": cantidad facturada menos lo ya ajustado por NC/ND
 * previas no eliminadas de ese mismo comprobante.
 *
 * Spec 096: la unidad de ajuste es la LÍNEA del comprobante (`venta_items.id`/`compra_items.id`),
 * no el producto agregado. Antes, `itemsDisponibles()` agrupaba por `producto_id`: si el mismo
 * producto aparecía en varias líneas con precio/bonificación distintos (bug real, venta 24854: 3
 * líneas fundidas en 1, total propuesto a la mitad), se perdía esa información. Para no romper las
 * NC/ND ya creadas con el cálculo agregado viejo (sin referencia de línea), el cálculo de pendiente
 * de un producto es DUAL: agregado mientras ninguna nota existente de ese producto tenga referencia
 * de línea (fallback, FR-006), y por línea en cuanto exista al menos una que sí la tenga.
 */
class AjustesPendientesNotaCreditoDebito
{
    /**
     * Pendiente de una LÍNEA puntual del comprobante (modo por línea, FR-003).
     *
     * @param  NotaCreditoDebito|null  $excluir  Nota en edición: se excluye del "ya ajustado".
     */
    public function pendienteDeLinea(VentaItem|CompraItem $linea, ?NotaCreditoDebito $excluir = null): float
    {
        $yaAjustada = (float) $this->notasDelProducto($linea->producto_id, $this->comprobanteDeLinea($linea))
            ->reject(fn ($nota) => $excluir && $nota->id === $excluir->id)
            ->flatMap(fn ($nota) => $nota->items)
            ->filter(fn ($item) => $this->refiereALinea($item, $linea))
            ->sum('cantidad');

        return round((float) $linea->cantidad - $yaAjustada, 3);
    }

    /**
     * Pendiente de un producto AGREGADO en el comprobante (modo fallback, FR-006) — el cálculo
     * original de la spec 045, sin distinguir línea. Se usa cuando ninguna nota existente de ese
     * producto tiene la referencia de línea nueva.
     *
     * @param  NotaCreditoDebito|null  $excluir  Nota en edición (FR-005): se excluye del "ya ajustado".
     */
    public function pendiente(Venta|Compra $comprobante, int $productoId, ?NotaCreditoDebito $excluir = null): float
    {
        $facturada = (float) $comprobante->items()
            ->where('producto_id', $productoId)
            ->sum('cantidad');

        $yaAjustada = (float) $this->notasDelProducto($productoId, $comprobante)
            ->reject(fn ($nota) => $excluir && $nota->id === $excluir->id)
            ->flatMap(fn ($nota) => $nota->items)
            ->where('producto_id', $productoId)
            ->sum('cantidad');

        return round($facturada - $yaAjustada, 3);
    }

    /**
     * Tope de un renglón que llega en el request, eligiendo el modo igual que `itemsDisponibles()`.
     *
     * Existe para que la decisión del modo viva en UN solo lugar. El bug de la spec 099 nació
     * justamente de que la pantalla y la validación la tomaban cada una por su cuenta: en la compra
     * 2478 (3 líneas del mismo producto: +1, −1, +1, con la tercera ya ajustada) `itemsDisponibles()`
     * ofrecía la línea libre de $4.616.354 mientras la validación la rechazaba con "máximo
     * disponible 0", porque `pendiente()` suma todas las líneas del producto y la negativa se comía
     * una de las positivas.
     *
     * Un `itemOrigenId` que no pertenece a este comprobante NO lanza: cae al agregado, que es el
     * criterio más restrictivo. Un renglón manipulado tiene que quedar bloqueado, no producir un 500.
     *
     * @param  int|null  $itemOrigenId  Línea del comprobante que el renglón dice ajustar.
     * @param  NotaCreditoDebito|null  $excluir  Nota en edición: se excluye del "ya ajustado".
     */
    public function topeDelRenglon(
        Venta|Compra $comprobante,
        int $productoId,
        ?int $itemOrigenId,
        ?NotaCreditoDebito $excluir = null,
    ): float {
        if ($itemOrigenId !== null) {
            $linea = $comprobante->items()->whereKey($itemOrigenId)->first();

            if ($linea) {
                return $this->pendienteDeLinea($linea, $excluir);
            }
        }

        return $this->pendiente($comprobante, $productoId, $excluir);
    }

    /**
     * Mensaje del tope, nombrando la línea cuando el renglón la identifica (spec 099, FR-005).
     *
     * Con varias líneas del mismo producto, "máximo disponible 0" no le dice al usuario CUÁL
     * renglón topó — parece que el producto entero está agotado. El importe es lo que distingue
     * una línea de otra en pantalla.
     *
     * El importe se muestra sólo si la línea es de ESTE comprobante: nombrar el de una línea ajena
     * sería filtrar un dato de otro comprobante.
     */
    public function mensajeDeTope(Venta|Compra $comprobante, ?int $itemOrigenId, float $pendiente): string
    {
        $linea = $itemOrigenId !== null
            ? $comprobante->items()->whereKey($itemOrigenId)->first()
            : null;

        if ($linea === null) {
            return "La cantidad máxima disponible para ajustar es {$pendiente}.";
        }

        $importe = number_format((float) $linea->precio_unitario, 2, ',', '.');

        return "La cantidad máxima disponible para ajustar en esta línea (\${$importe}) es {$pendiente}.";
    }

    /**
     * @return array<int, array{producto_id:int, descripcion:string, pendiente:float, precio:float, descuento_pct:float, iva_pct:?string, item_origen_id:int}>
     */
    public function itemsDisponibles(Venta|Compra $comprobante): array
    {
        $productosPorLinea = $comprobante->items()->whereNotNull('producto_id')->get()
            ->groupBy('producto_id');

        $resultado = [];

        foreach ($productosPorLinea as $productoId => $lineas) {
            if ($this->productoEnModoPorLinea((int) $productoId, $comprobante)) {
                // FR-001/FR-002/FR-003: una fila por línea, cada una con su propio precio,
                // bonificación, IVA y pendiente — sin fusionar aunque compartan producto.
                foreach ($lineas as $linea) {
                    $pendiente = $this->pendienteDeLinea($linea);
                    if ($pendiente > 0) {
                        $resultado[] = $this->filaDesdeLinea($linea, $pendiente);
                    }
                }
            } else {
                // FR-006: fallback agregado — comportamiento idéntico al de antes de esta spec.
                $primero = $lineas->first();
                $pendiente = $this->pendiente($comprobante, (int) $productoId);
                if ($pendiente > 0) {
                    $resultado[] = $this->filaDesdeLinea($primero, $pendiente);
                }
            }
        }

        return $resultado;
    }

    /** @return array{producto_id:int, descripcion:string, pendiente:float, precio:float, descuento_pct:float, iva_pct:?string, item_origen_id:int} */
    private function filaDesdeLinea(VentaItem|CompraItem $linea, float $pendiente): array
    {
        return [
            'producto_id' => (int) $linea->producto_id,
            'descripcion' => $linea->descripcion,
            'pendiente' => $pendiente,
            // Precarga la página completa de NC/ND (spec 059) con el precio/descuento/IVA que ya
            // tenía ESA línea del comprobante de origen — el usuario puede editarlos igual si la
            // nota corresponde a un monto distinto.
            'precio' => (float) $linea->precio_unitario,
            'descuento_pct' => (float) ($linea->descuento_pct ?? 0),
            'iva_pct' => $linea->iva_pct,
            'item_origen_id' => (int) $linea->id,
        ];
    }

    /**
     * FR-006: un producto cae en "modo agregado" (fallback) SÓLO cuando existe alguna NC/ND ya
     * creada de ese producto en ese comprobante que NO trae la referencia de línea nueva — el
     * cálculo agregado viejo es necesario ahí porque no hay forma de saber qué línea puntual
     * ajustó. Sin notas previas, o con notas que sí traen la referencia, se usa modo por línea
     * (que es lo que corrige el bug: SC-002 exige una fila por línea del comprobante, no una por
     * producto, en el caso normal de "todavía no se ajustó nada").
     */
    private function productoEnModoPorLinea(int $productoId, Venta|Compra $comprobante): bool
    {
        return ! $this->notasDelProducto($productoId, $comprobante)
            ->flatMap(fn ($nota) => $nota->items)
            ->where('producto_id', $productoId)
            ->contains(fn ($item) => $item->venta_item_id === null && $item->compra_item_id === null);
    }

    /** @return Collection<int, NotaCreditoDebito> */
    private function notasDelProducto(int $productoId, Venta|Compra $comprobante): Collection
    {
        return $comprobante->notasCreditoDebito()
            ->with('items')
            ->get()
            ->filter(fn ($nota) => $nota->items->contains('producto_id', $productoId));
    }

    private function refiereALinea($item, VentaItem|CompraItem $linea): bool
    {
        return $linea instanceof VentaItem
            ? $item->venta_item_id === $linea->id
            : $item->compra_item_id === $linea->id;
    }

    private function comprobanteDeLinea(VentaItem|CompraItem $linea): Venta|Compra
    {
        return $linea instanceof VentaItem ? $linea->venta : $linea->compra;
    }

    /**
     * Cabecera del comprobante de origen para precargar el alta de una NC/ND (spec 095).
     *
     * La nota nace como espejo del comprobante: hasta ahora sólo se precargaban los ítems y el
     * resto de la cabecera quedaba vacía, así que una nota sobre una venta con descuento general
     * nacía por el importe SIN descuento — de más. Acá se arma lo que falta.
     *
     * @return array{tipoComprobante:?string, descuentoGeneralTipo:?string, descuentoGeneralPct:?float, descuentoGeneralMonto:?float, fechaEmision:?string, fechaVencimiento:?string, servicioDesde:?string, servicioHasta:?string, tercero:?array{id:int, nombre:string}, categoria:?array{id:int, nombre:string}, conceptos:array<int, array{tipo:string, concepto:string, monto:float}>}
     */
    public function cabeceraComprobante(Venta|Compra $comprobante): array
    {
        $esVenta = $comprobante instanceof Venta;

        // FR-005: cada fecha usa la del comprobante y, si no está cargada, cae en la de emisión.
        $fechaEmision = $this->aIso($comprobante->fecha_emision);
        $respaldo = fn ($fecha) => $this->aIso($fecha) ?? $fechaEmision;

        // Ventas y Compras nombran distinto la misma fecha (cobro vs. pago).
        $vencimiento = $esVenta ? $comprobante->fecha_vto_cobro : $comprobante->fecha_vto_pago;

        $tercero = $esVenta ? $comprobante->cliente : $comprobante->proveedor;
        $categoria = $comprobante->categoria;

        // FR-002: el descuento general se hereda CON su modalidad. En modo monto se pasa el importe
        // tal cual: convertirlo a un porcentaje equivalente introduciría un error de redondeo en un
        // documento fiscal.
        $descuentoTipo = $comprobante->descuento_general_tipo ?: 'porcentaje';
        $descuentoPct = $comprobante->descuento_general_pct;
        $descuentoMonto = $comprobante->descuento_general_monto;

        return [
            // FR-004: si el comprobante no tiene tipo, se manda null y el campo queda vacío.
            // No se infiere ninguno: una nota con el tipo cruzado no se arregla editándola.
            'tipoComprobante' => $comprobante->tipo_comprobante ?: null,
            'descuentoGeneralTipo' => $descuentoTipo,
            'descuentoGeneralPct' => $descuentoTipo === 'porcentaje' && $descuentoPct !== null
                ? (float) $descuentoPct
                : null,
            'descuentoGeneralMonto' => $descuentoTipo === 'monto' && $descuentoMonto !== null
                ? (float) $descuentoMonto
                : null,
            'fechaEmision' => $fechaEmision,
            'fechaVencimiento' => $respaldo($vencimiento),
            'servicioDesde' => $respaldo($comprobante->servicio_desde),
            'servicioHasta' => $respaldo($comprobante->servicio_hasta),
            'tercero' => $tercero
                ? ['id' => (int) $tercero->id, 'nombre' => (string) $tercero->nombre]
                : null,
            'categoria' => $categoria
                ? ['id' => (int) $categoria->id, 'nombre' => (string) $categoria->nombre]
                : null,
            // FR-007: los conceptos del comprobante ya vienen con la misma forma
            // {tipo, concepto, monto} que la nota usa en su columna JSON `impuestos`.
            'conceptos' => $comprobante->conceptos
                ->map(fn ($c) => [
                    'tipo' => (string) $c->tipo,
                    'concepto' => (string) $c->concepto,
                    'monto' => (float) $c->monto,
                ])
                ->values()
                ->all(),
        ];
    }

    /** Fechas hacia el front siempre en ISO (`YYYY-MM-DD`); el helper AppFecha las muestra en dd/mm/aaaa. */
    private function aIso($fecha): ?string
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        return $fecha instanceof \DateTimeInterface
            ? $fecha->format('Y-m-d')
            : Carbon::parse($fecha)->format('Y-m-d');
    }
}
