<?php

namespace App\Services\Informes\IvaDigital;

use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Completa cada fila de {@see \App\Services\Informes\LibroIvaVentasQuery::detalle()} /
 * {@see \App\Services\Informes\LibroIvaComprasQuery::detalle()} con los campos que esas queries no
 * exponen porque el informe en pantalla (spec 077) no los necesita: **importe total almacenado**
 * (FR-015) y **documento del cliente/proveedor** (FR-020).
 *
 * Deliberadamente no se tocan `LibroIvaVentasQuery`/`LibroIvaComprasQuery`: sus dos ramas
 * (comprobante y NC/ND) se unen con `UNION ALL` y deben mantener exactamente las mismas columnas en
 * las mismas posiciones para el contrato de spec 077 (DataTables + export). Agregar columnas ahí
 * rompería esa unión. En cambio, esta clase resuelve los datos faltantes con una consulta por lote
 * separada, keyed por `(tipo, id)` — mismo criterio que ya usa `LibroIvaVentasQuery::queryNotas()`
 * para distinguir un comprobante (`tipo` = letra) de una nota (`tipo` = 'NC'/'ND' + letra).
 */
class DatosFiscalesComprobante
{
    /**
     * @param  Collection<int, object>  $filas  filas de `detalle()`, ya materializadas
     * @return Collection<string, array{total: float, tipo_documento: ?string, documento: ?string}> keyed por "{tipo}:{id}"
     */
    public function resolverVentas(Collection $filas): Collection
    {
        $idsComprobante = $filas->filter(fn ($f) => ! $this->esNota($f->tipo))->pluck('id')->all();
        $idsNota = $filas->filter(fn ($f) => $this->esNota($f->tipo))->pluck('id')->all();

        $mapa = collect();

        if ($idsComprobante !== []) {
            Venta::with('cliente')->whereIn('id', $idsComprobante)->get()->each(function (Venta $v) use ($mapa) {
                $datos = $v->cliente?->datosFiscalesArca() ?? [];
                $mapa->put("comprobante:{$v->id}", [
                    'total' => (float) $v->total,
                    'tipo_documento' => $datos['tipo_documento'] ?? null,
                    'documento' => $datos['documento'] ?? null,
                ]);
            });
        }

        if ($idsNota !== []) {
            NotaCreditoDebito::with('venta.cliente')->whereIn('id', $idsNota)->get()->each(function (NotaCreditoDebito $n) use ($mapa) {
                $datos = $n->venta?->cliente?->datosFiscalesArca() ?? [];
                $signo = $n->tipo === 'credito' ? -1 : 1;
                $mapa->put("nota:{$n->id}", [
                    'total' => $signo * (float) $n->monto,
                    'tipo_documento' => $datos['tipo_documento'] ?? null,
                    'documento' => $datos['documento'] ?? null,
                ]);
            });
        }

        return $mapa;
    }

    /** @return Collection<string, array{total: float, cuit: ?string}> keyed por "{tipo}:{id}" */
    public function resolverCompras(Collection $filas): Collection
    {
        $idsComprobante = $filas->filter(fn ($f) => ! $this->esNota($f->tipo))->pluck('id')->all();
        $idsNota = $filas->filter(fn ($f) => $this->esNota($f->tipo))->pluck('id')->all();

        $mapa = collect();

        if ($idsComprobante !== []) {
            Compra::with('proveedor')->whereIn('id', $idsComprobante)->get()->each(function (Compra $c) use ($mapa) {
                $mapa->put("comprobante:{$c->id}", [
                    'total' => (float) $c->total,
                    'cuit' => $c->proveedor?->cuit,
                ]);
            });
        }

        if ($idsNota !== []) {
            NotaCreditoDebito::with('compra.proveedor')->whereIn('id', $idsNota)->get()->each(function (NotaCreditoDebito $n) use ($mapa) {
                $signo = $n->tipo === 'credito' ? -1 : 1;
                $mapa->put("nota:{$n->id}", [
                    'total' => $signo * (float) $n->monto,
                    'cuit' => $n->compra?->proveedor?->cuit,
                ]);
            });
        }

        return $mapa;
    }

    /** Clave del mapa para una fila de `detalle()`: distingue comprobante de NC/ND (research §1). */
    public function clave(object $fila): string
    {
        return ($this->esNota($fila->tipo) ? 'nota' : 'comprobante').':'.$fila->id;
    }

    /**
     * Neto gravado real por alícuota de un comprobante de venta, agrupando `venta_items` — evita
     * derivar el neto a partir del IVA ya redondeado (`iva / (pct/100)`), que arrastra el doble
     * redondeo que research.md Decisión 4 documenta entre el IVA por línea y el total del
     * comprobante y da un neto distinto en centavos al real.
     *
     * @return array<string, float> alícuota (clave de `iva_pct`) => neto
     */
    public function netoPorAlicuotaVenta(int $ventaId): array
    {
        return DB::table('venta_items')
            ->where('venta_id', $ventaId)
            ->groupBy('iva_pct')
            ->selectRaw('iva_pct, SUM(subtotal) as neto')
            ->pluck('neto', 'iva_pct')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** Igual que {@see netoPorAlicuotaVenta}, sobre `compra_items`. */
    public function netoPorAlicuotaCompra(int $compraId): array
    {
        return DB::table('compra_items')
            ->where('compra_id', $compraId)
            ->groupBy('iva_pct')
            ->selectRaw('iva_pct, SUM(subtotal) as neto')
            ->pluck('neto', 'iva_pct')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function esNota(string $tipo): bool
    {
        return str_starts_with($tipo, 'NC') || str_starts_with($tipo, 'ND');
    }
}
