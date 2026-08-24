<?php

namespace App\Exports\Informes;

use App\Services\Informes\VentasInformeQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel del Informe de Ventas — "Exportar Excel Detallado" (spec 076, US2).
 *
 * A diferencia del export resumen (dos hojas, una divergencia deliberada del módulo), este **es
 * una sola hoja**, igual que en Contagram: es un archivo nuevo, sin coherencia previa que respetar
 * (research §R6). Estructura fija (`contracts/export-detallado.md §2`): 3 bloques de KPIs en las
 * filas 1-8, con blancos entre bloques, encabezado de 44 columnas en la fila 10 y el detalle desde
 * la fila 11.
 *
 * Reusa el mismo `VentasInformeQuery` que la pantalla y el export resumen (FR-013), así que sus
 * totales coinciden al centavo con los KPIs mostrados (SC-004).
 */
class InformeVentasDetalladoExport implements FromArray, WithStrictNullComparison, WithStyles, WithTitle
{
    private const CHUNK = 1000;

    /** Fila (base 1) donde arranca el encabezado de las 44 columnas. */
    private const FILA_ENCABEZADO = 10;

    private const RÓTULOS = [
        'Id', 'Emisión', 'Vencimiento', 'Categoría', 'Cliente', 'CUIT / DNI', 'ARCA', 'Tipo',
        'Tipo de Comprobante', 'Punto de Venta', 'N° Factura', 'Vendedor', 'Producto/Servicio',
        'Código', 'Tipo', 'Proveedor', 'Cantidad', 'Precio Unitario', 'Costo Total Actual',
        'CMV Total', 'Lista de Precios', 'Precio de Venta', 'Resultado', 'Subtotal sin Descuento',
        'Descuento en $', 'Subtotal con Descuento', 'Importe Neto No Gravado',
        'Importe Neto Exento', 'Importe Neto Gravado', 'IVA - 2,5%', 'IVA - 5%', 'IVA - 10,5%',
        'IVA - 21%', 'IVA - 27%', 'Exento', 'No Gravado', 'Perc. IVA', 'Perc. IIBB',
        'Imp. Internos', 'Total Venta', 'Etiquetas', 'Nota para el Cliente', 'Nota Interna',
        'Afecta Stock',
    ];

    public function __construct(private VentasInformeQuery $informe, private Request $request) {}

    public function title(): string
    {
        return 'Informe de Ventas Detallado';
    }

    public function array(): array
    {
        $kpis = $this->informe->kpis($this->request);

        // Cada bloque: una fila de RÓTULOS y, debajo, la fila de VALORES en las mismas columnas
        // (no rótulo-valor intercalados en la misma fila — así lo tiene el archivo real de
        // Contagram). La fila en blanco entre bloques va como `[null]` y NO `[]`: un array vacío
        // lo descarta el `flatMap` de Maatwebsite al aplanar filas, así que no aparece como fila
        // en blanco en el Excel sino que directamente desaparece, corriendo todo lo de abajo.
        $filas = [
            ['Total Ventas Creadas', 'Total Nota de Débito', 'Total Nota de Crédito', 'Total Ventas'],
            [$kpis['total_ventas_creadas'], $kpis['total_nota_debito'], $kpis['total_nota_credito'], $kpis['total_ventas']],
            [null],
            ['Cantidad de Productos/Servicios', 'Cantidad Ventas Creadas', 'Venta Promedio', 'Costo Actual'],
            [$kpis['cantidad_prod_serv'], $kpis['cantidad_ventas_creadas'], $kpis['venta_promedio'], $kpis['costo_actual']],
            [null],
            ['Precio Neto', 'Costo Mercadería Vendida', 'Resultado'],
            [$kpis['precio_neto'], $kpis['cmv'], $kpis['resultado']],
            [null],
            self::RÓTULOS,
        ];

        $this->informe->detalle($this->request)
            ->orderBy('detalle.fecha')
            ->orderBy('detalle.id')
            ->chunk(self::CHUNK, function ($chunk) use (&$filas) {
                foreach ($chunk as $fila) {
                    $filas[] = $this->fila($fila);
                }
            });

        return $filas;
    }

    /** @return list<mixed> */
    private function fila(\stdClass $f): array
    {
        return [
            $f->id,
            $this->fechaExcel($f->fecha),
            $this->fechaExcel($f->vencimiento),
            $f->categoria,
            $f->cliente,
            $f->cuit_dni,
            $f->arca,
            $f->tipo_comprobante,
            $f->sigla_comprobante,
            $f->punto_venta,
            $f->nro_factura,
            $f->vendedor,
            $f->producto,
            $f->codigo,
            $f->tipo_producto,
            $f->proveedor,
            $this->num($f->cantidad, 3),
            $this->num($f->precio_unitario),
            $this->num($f->costo_total_actual),
            $this->num($f->cmv_total),
            $f->lista_precio,
            $this->num($f->precio_neto),
            $this->num($f->resultado),
            $this->num($f->subtotal_sin_descuento),
            $this->num($f->descuento_monto),
            $this->num($f->subtotal_con_descuento),
            $this->num($f->neto_no_gravado),
            $this->num($f->neto_exento),
            $this->num($f->neto_gravado),
            $this->num($f->iva_2_5),
            $this->num($f->iva_5),
            $this->num($f->iva_10_5),
            $this->num($f->iva_21),
            $this->num($f->iva_27),
            $this->num($f->exento_col),
            $this->num($f->no_gravado_col),
            $this->num($f->perc_iva),
            $this->num($f->perc_iibb),
            $this->num($f->imp_internos),
            $this->num($f->total_venta),
            $f->etiquetas === 'Sin etiquetas' ? '' : $f->etiquetas,
            $f->nota_cliente,
            $f->nota_interna,
            $f->afecta_stock,
        ];
    }

    /**
     * Fecha de Excel de verdad (serial numérico), no texto (FR-010a, invariante I8).
     *
     * El `DefaultValueBinder` de PhpSpreadsheet **no** convierte un `DateTimeInterface` a serial:
     * lo formatea como texto (`Y-m-d H:i:s`) y listo — se probó pasando un `DateTimeImmutable`
     * directo y el archivo generado lo escribía como string, no como fecha. Hay que convertirlo
     * explícitamente con `Date::PHPToExcel()`; el número de formato (`dd/mm/yyyy`) que hace que
     * Excel lo MUESTRE como fecha se aplica aparte, en `styles()`.
     */
    private function fechaExcel(mixed $valor): ?float
    {
        return $valor ? \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTimeImmutable((string) $valor)) : null;
    }

    private function num(mixed $valor, int $decimales = 2): ?float
    {
        return $valor === null ? null : round((float) $valor, $decimales);
    }

    public function styles(Worksheet $sheet)
    {
        $ultima = $sheet->getHighestColumn();
        $filaEncabezado = self::FILA_ENCABEZADO;

        $negrita = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2B2B2B']],
        ];

        // Las filas de RÓTULO de los 3 bloques de KPIs llevan el mismo estilo que el encabezado
        // de las 44 columnas — así lo tiene el archivo real de Contagram. Sólo la fila de rótulos,
        // nunca la de valores debajo.
        foreach ([1, 4, 7, $filaEncabezado] as $fila) {
            $sheet->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray($negrita);
        }

        $ultimaFila = $sheet->getHighestRow();
        $primeraFilaDatos = $filaEncabezado + 1;

        if ($ultimaFila >= $primeraFilaDatos) {
            // Columnas B (Emisión) y C (Vencimiento): formato de fecha, no texto (I8).
            $sheet->getStyle("B{$primeraFilaDatos}:C{$ultimaFila}")
                ->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        }

        return [];
    }
}
