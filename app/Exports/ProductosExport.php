<?php

namespace App\Exports;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export de Productos a XLSX real (no CSV) con celdas numéricas tipadas —
 * spec de edición masiva vía Excel: un CSV escribe los precios como texto
 * plano ("7137.04"), y abrirlo/re-guardarlo con una app de planillas
 * (Excel, Google Sheets) obliga a esa app a *adivinar* el separador
 * decimal/miles según su configuración regional. Con una configuración
 * regional distinta a la que generó el archivo, ese texto se reinterpreta
 * mal y corrompe el valor (caso real: precios de varias listas quedaron con
 * 2-3 órdenes de magnitud de más tras un roundtrip CSV -> Google Sheets ->
 * XLSX -> reimportación). Un XLSX con celdas numéricas viaja con el valor
 * real (IEEE), sin pasar por texto ni depender de locale en ningún momento
 * del camino ida y vuelta.
 */
class ProductosExport implements FromQuery, WithHeadings, WithMapping
{
    /** @param  Collection<int, \App\Models\ListaPrecio>  $listas */
    /** @param  Collection<int, \App\Models\Deposito>  $depositos */
    public function __construct(
        private Builder $query,
        private Collection $listas,
        private Collection $depositos,
    ) {}

    public function query()
    {
        return $this->query->orderBy('nombre');
    }

    public function headings(): array
    {
        return [
            // "Id" primero: permite reimportar este mismo archivo mapeando Id + las
            // columnas editadas para que el importador (ImportadorFilas::resolverModoFila())
            // lo reconozca como actualización de estos productos en vez de crear duplicados.
            'Id', 'Nombre', 'Código/SKU', 'Tipo', 'Tipo de Producto', 'Proveedor', 'Precio venta',
            ...$this->listas->map(fn ($l) => $l->nombre)->all(),
            'IVA venta', 'Costo', 'IVA compra', 'Stock total',
            ...$this->depositos->map(fn ($d) => 'Stock '.$d->nombre)->all(),
            'Estado',
        ];
    }

    public function map($p): array
    {
        /** @var Producto $p */
        return [
            $p->id,
            $p->nombre,
            $p->codigo,
            $p->tipo,
            optional($p->tipoProducto)->nombre,
            optional($p->proveedor)->nombre,
            // Casts explícitos a float/int: los atributos `decimal:*` de Eloquent devuelven
            // string (para no perder precisión) — sin este cast, PhpSpreadsheet escribiría la
            // celda como texto igual que el CSV, perdiendo el punto entero de este export.
            (float) $p->precio_venta,
            ...$this->listas->map(fn ($l) => $p->{'precio_lista_'.$l->id} !== null ? (float) $p->{'precio_lista_'.$l->id} : null)->all(),
            Producto::etiquetaIva($p->iva_venta_pct),
            (float) $p->costo,
            Producto::etiquetaIva($p->iva_compra_pct),
            $p->esServicio() ? null : (float) ($p->stock_total ?? 0),
            ...$this->depositos->map(fn ($d) => $p->esServicio() ? null : (float) ($p->{'stock_deposito_'.$d->id} ?? 0))->all(),
            $p->activo ? 'Activo' : 'Inactivo',
        ];
    }
}
