<?php

namespace App\Exports;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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
class ProductosExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithColumnFormatting
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
        return $this->query->orderBy('id');
    }

    /** Header con fondo oscuro y letra blanca, pedido explícito del usuario. */
    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B2B2B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        return [];
    }

    /**
     * Formato numérico explícito ('0' en vez de General) para Stock total/por depósito:
     * evita que un 0 real se vea vacío en Excel si la celda hereda algún formato tipo
     * contable con la sección de "cero" en blanco.
     */
    public function columnFormats(): array
    {
        $inicioStock = 7; // Id, Nombre, Código/SKU, Tipo, Tipo de Producto, Proveedor -> Stock total es la 7ma columna.
        $formatos = [];
        for ($i = 0; $i < 1 + $this->depositos->count(); $i++) {
            $formatos[$this->columnLetra($inicioStock + $i)] = '0';
        }

        return $formatos;
    }

    private function columnLetra(int $indiceBase1): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($indiceBase1);
    }

    public function headings(): array
    {
        return [
            // "Id" primero: permite reimportar este mismo archivo mapeando Id + las
            // columnas editadas para que el importador (ImportadorFilas::resolverModoFila())
            // lo reconozca como actualización de estos productos en vez de crear duplicados.
            'Id', 'Nombre', 'Código/SKU', 'Tipo', 'Tipo de Producto', 'Proveedor',
            'Stock total',
            ...$this->depositos->map(fn ($d) => 'Stock '.$d->nombre)->all(),
            'Costo', 'Precio venta',
            ...$this->listas->map(fn ($l) => $l->nombre)->all(),
            'IVA venta', 'IVA compra', 'Estado',
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
            $p->esServicio() ? null : (float) ($p->stock_total ?? 0),
            ...$this->depositos->map(fn ($d) => $p->esServicio() ? null : (float) ($p->{'stock_deposito_'.$d->id} ?? 0))->all(),
            // Casts explícitos a float/int: los atributos `decimal:*` de Eloquent devuelven
            // string (para no perder precisión) — sin este cast, PhpSpreadsheet escribiría la
            // celda como texto igual que el CSV, perdiendo el punto entero de este export.
            (float) $p->costo,
            (float) $p->precio_venta,
            ...$this->listas->map(fn ($l) => $p->{'precio_lista_'.$l->id} !== null ? (float) $p->{'precio_lista_'.$l->id} : null)->all(),
            Producto::etiquetaIva($p->iva_venta_pct),
            Producto::etiquetaIva($p->iva_compra_pct),
            $p->activo ? 'Activo' : 'Inactivo',
        ];
    }
}
