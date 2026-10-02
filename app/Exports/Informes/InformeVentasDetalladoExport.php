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

    /**
     * Columnas del detalle cuyo total es la suma de la propia columna, verificado contra la base:
     * su suma por fila coincide exactamente con el KPI del informe. Son las unicas que pueden ir
     * como SUBTOTAL y seguir al filtro de Excel sin dar un numero inflado.
     */
    private const COL_CANTIDAD = 'Q';
    private const COL_COSTO_ACTUAL = 'S';
    private const COL_CMV = 'T';
    private const COL_PRECIO_NETO = 'V';
    private const COL_RESULTADO = 'W';

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

    /** Cuántas filas de detalle tiene el archivo; fija el rango de las fórmulas del encabezado. */
    private int $filasDeDetalle = 0;

    public function __construct(private VentasInformeQuery $informe, private Request $request) {}

    public function title(): string
    {
        return 'Informe de Ventas Detallado';
    }

    public function array(): array
    {
        $kpis = $this->informe->kpis($this->request);

        // El detalle se arma PRIMERO: las fórmulas del encabezado necesitan saber hasta qué fila
        // llega el rango, y eso recién se sabe después de recorrerlo.
        $detalle = [];

        $this->informe->detalle($this->request)
            ->orderBy('detalle.fecha')
            ->orderBy('detalle.id')
            ->chunk(self::CHUNK, function ($chunk) use (&$detalle) {
                foreach ($chunk as $fila) {
                    $detalle[] = $this->fila($fila);
                }
            });

        $this->filasDeDetalle = count($detalle);

        // Cada bloque: una fila de RÓTULOS y, debajo, la fila de VALORES en las mismas columnas
        // (no rótulo-valor intercalados en la misma fila — así lo tiene el archivo real de
        // Contagram). La fila en blanco entre bloques va como `[null]` y NO `[]`: un array vacío
        // lo descarta el `flatMap` de Maatwebsite al aplanar filas, así que no aparece como fila
        // en blanco en el Excel sino que directamente desaparece, corriendo todo lo de abajo.
        //
        // Los KPIs que son suma de una columna del detalle van como fórmula SUBTOTAL(109), la
        // única que ignora las filas ocultas por un autofiltro: al filtrar por un proveedor
        // dentro del Excel pasan a ser los de ese proveedor. Se verificó contra la base cuáles lo
        // son —su suma por fila coincide exactamente con el KPI— y cuáles no:
        //
        //   Cantidad, Costo Actual, CMV, Precio Neto y Resultado -> coinciden, van como fórmula.
        //   Total Ventas, Cantidad de Ventas y Venta Promedio    -> NO: el total de la venta se
        //       repite en cada una de sus líneas (1.805 filas para 1.297 comprobantes), así que
        //       sumarlo por fila lo infla. Quedan con el valor del informe completo y el rótulo lo
        //       dice, para que nadie lea un número filtrado como si fuera el del filtro.
        $filas = [
            ['Total Ventas Creadas (informe completo)', 'Total Nota de Débito (informe completo)', 'Total Nota de Crédito (informe completo)', 'Total Ventas (informe completo)'],
            [$kpis['total_ventas_creadas'], $kpis['total_nota_debito'], $kpis['total_nota_credito'], $kpis['total_ventas']],
            [null],
            ['Cantidad de Productos/Servicios', 'Cantidad Ventas Creadas (informe completo)', 'Venta Promedio (informe completo)', 'Costo Actual'],
            [$this->subtotal(self::COL_CANTIDAD), $kpis['cantidad_ventas_creadas'], $kpis['venta_promedio'], $this->subtotal(self::COL_COSTO_ACTUAL)],
            [null],
            ['Precio Neto', 'Costo Mercadería Vendida', 'Resultado'],
            [$this->subtotal(self::COL_PRECIO_NETO), $this->subtotal(self::COL_CMV), $this->subtotal(self::COL_RESULTADO)],
            [null],
            self::RÓTULOS,
        ];

        return array_merge($filas, $detalle);
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
    /**
     * Fórmula de total para una columna del detalle.
     *
     * SUBTOTAL(109) suma sólo las filas visibles, así que el total sigue al autofiltro: filtrando
     * por un proveedor el KPI pasa a ser el de ese proveedor. El rango arranca en la primera fila
     * de datos y llega hasta la última; se calcula al vuelo porque el detalle se pagina por chunks
     * y recién al final se sabe cuántas filas hay.
     *
     * El total puede diferir del KPI en centavos: el KPI suma en SQL sin redondear y la fórmula
     * suma las celdas ya redondeadas a 2 decimales. Es el redondeo acumulado de cientos de filas
     * (8 centavos sobre 10 millones en el peor caso medido) y es el comportamiento correcto para
     * un total que vive en la planilla: suma exactamente lo que el usuario ve.
     */
    private function subtotal(string $columna): string
    {
        $primera = self::FILA_ENCABEZADO + 1;
        $ultima = self::FILA_ENCABEZADO + $this->filasDeDetalle;

        if ($this->filasDeDetalle < 1) {
            return '0';
        }

        return "=SUBTOTAL(109,{$columna}{$primera}:{$columna}{$ultima})";
    }

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

            // Filtro ya activado sobre el encabezado del detalle y sus filas: el usuario filtra
            // por proveedor apenas abre el archivo, sin seleccionar el rango a mano —que es donde
            // se equivocaba y dejaba filas afuera—. Los bloques de KPIs de arriba quedan fuera del
            // rango para que sus textos no aparezcan entre los valores del desplegable.
            $sheet->setAutoFilter("A{$filaEncabezado}:{$ultima}{$ultimaFila}");
        }

        return [];
    }
}
