<?php

namespace App\Exports\Informes;

use App\Services\Informes\VentasInformeQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel del Informe de Ventas — "Exportar Resumen" (spec 068, US2): dos hojas.
 *
 * - **"Informe de Ventas Resumen"**: hoja legible, con los 3 bloques de KPIs y el detalle con los
 *   rótulos de columna del export real de Contagram, que difieren de los de la pantalla
 *   (Emisión/Fecha, Precio de Venta/Precio Total Neto, Resultado/Result., Total Venta/Total
 *   Comprobante). **Acá vive la réplica R1.**
 * - **"Ventas"**: hoja plana, una fila por ítem, sin KPIs ni secciones, con el `Resultado`
 *   **correcto** en todas las filas. Es la hoja pensada para reprocesar en otra planilla, así que
 *   no puede llevar el desvío.
 *
 * Contagram exporta este informe en una sola hoja; el patrón de doble hoja es una divergencia
 * deliberada del módulo, adoptada en la Tanda 1 (spec §Contexto).
 *
 * Reusa el mismo servicio y los mismos parámetros de filtro que `data`/`stats`, así que los
 * totales del archivo coinciden al centavo con los de la pantalla (SC-004).
 */
class InformeVentasExport implements WithMultipleSheets
{
    /** Se recorre en chunks: un período grande no entra en memoria de una sola vez. */
    private const CHUNK = 1000;

    public function __construct(private VentasInformeQuery $informe, private Request $request) {}

    public function sheets(): array
    {
        $filas = $this->filas();

        return [
            $this->hojaLegible($filas),
            $this->hojaPlana($filas),
        ];
    }

    /** @return list<\stdClass> */
    private function filas(): array
    {
        $acumulado = [];

        $this->informe->detalle($this->request)
            ->orderBy('detalle.fecha')
            ->orderBy('detalle.id')
            ->chunk(self::CHUNK, function ($chunk) use (&$acumulado) {
                foreach ($chunk as $fila) {
                    $acumulado[] = $fila;
                }
            });

        return $acumulado;
    }

    /** @param  list<\stdClass>  $filas */
    private function hojaLegible(array $filas): HojaInforme
    {
        $kpis = $this->informe->kpis($this->request);

        $datos = array_map(fn ($f) => [
            $f->id,
            $this->fecha($f->fecha),
            $f->cliente,
            // Sigla completa (FCA/FCB/FC, NCA/NCB/NC, NDA/NDB/ND) y no la letra sola (FR-021).
            $f->sigla_comprobante,
            $f->producto,
            $this->num($f->cantidad),
            $this->num($f->precio_unitario),
            $this->num($f->costo_total_actual),
            $this->num($f->cmv_total),
            $this->num($f->precio_neto),
            $this->resultadoComoContagram($f),
            $this->num($f->total_venta),
        ], $filas);

        // Los totales salen de los KPIs, **no** de sumar columnas del detalle: "Total
        // Comprobante" se repite en cada ítem de la misma venta y sumarla por fila la contaría
        // de más (data-model §Invariantes, punto 3).
        $datos[] = [];
        $datos[] = ['Total Ventas Creadas', $kpis['total_ventas_creadas']];
        $datos[] = ['Total Nota de Débito', $kpis['total_nota_debito']];
        $datos[] = ['Total Nota de Crédito', $kpis['total_nota_credito']];
        $datos[] = ['Total Ventas', $kpis['total_ventas']];
        $datos[] = ['Cantidad Prod./Serv.', $kpis['cantidad_prod_serv']];
        $datos[] = ['Cantidad Ventas Creadas', $kpis['cantidad_ventas_creadas']];
        $datos[] = ['Venta Promedio', $kpis['venta_promedio']];
        $datos[] = ['Costo Actual', $kpis['costo_actual']];
        $datos[] = ['Precio Neto', $kpis['precio_neto']];
        $datos[] = ['Costo Mercadería Vendida', $kpis['cmv']];
        $datos[] = ['Resultado', $kpis['resultado']];

        $total = count($datos);

        return new HojaInforme(
            'Informe de Ventas Resumen',
            [
                'Id', 'Emisión', 'Cliente', 'Tipo de Comprobante', 'Producto/Servicio', 'Cantidad',
                'Precio Unitario', 'Costo Total Actual', 'CMV Total', 'Precio de Venta',
                'Resultado', 'Total Venta',
            ],
            $datos,
            range($total - 10, $total),
        );
    }

    /**
     * RÉPLICA DELIBERADA R1 — NO "CORREGIR" (spec 068 §Réplicas, FR-022).
     *
     * En el Excel de Contagram, la celda `Resultado` de las filas de tipo **Nota de Crédito** usa
     * una rama que **suma** en vez de restar: una NC de -370,00 con CMV -200,00 sale -570,00 en
     * el archivo, mientras la pantalla muestra -170,00. Es un defecto de origen, y el usuario
     * decidió replicarlo para que los números coincidan al comparar contra la app original.
     *
     * El desvío está confinado a esta celda, de esta hoja: la pantalla, el PDF y la hoja plana
     * usan siempre `Precio − CMV`, y los KPIs no lo tocan porque salen del servicio y no de sumar
     * esta columna. `ReplicasContagramTest` fija por escrito esas dos cosas.
     */
    private function resultadoComoContagram(\stdClass $fila): ?float
    {
        if ($fila->tipo_operacion === 'nc') {
            return $this->num((float) $fila->precio_neto + (float) $fila->cmv_total);
        }

        return $this->num($fila->resultado);
    }

    /** @param  list<\stdClass>  $filas */
    private function hojaPlana(array $filas): HojaInforme
    {
        $operacion = ['venta' => 'Venta', 'nc' => 'Nota de Crédito', 'nd' => 'Nota de Débito'];

        $datos = array_map(fn ($f) => [
            $f->id,
            $operacion[$f->tipo_operacion] ?? $f->tipo_operacion,
            $this->fecha($f->fecha),
            $f->comprobante,
            // Sigla completa y no la letra sola (FR-021).
            $f->sigla_comprobante,
            $f->nro_comprobante,
            $f->cliente,
            $f->producto,
            $this->num($f->cantidad),
            $this->num($f->precio_unitario),
            $this->num($f->costo_total_actual),
            $this->num($f->cmv_total),
            $this->num($f->precio_neto),
            // Fórmula correcta en TODAS las filas: es la hoja destinada a reprocesamiento.
            $this->num($f->resultado),
            $this->num($f->total_venta),
        ], $filas);

        return new HojaInforme(
            'Ventas',
            [
                'Id', 'Tipo de Operación', 'Emisión', 'Comprobante', 'Tipo de Comprobante',
                'N° de Comprobante', 'Cliente', 'Producto/Servicio', 'Cantidad', 'Precio Unitario',
                'Costo Total Actual', 'CMV Total', 'Precio de Venta', 'Resultado', 'Total Venta',
            ],
            $datos,
        );
    }

    private function fecha(mixed $valor): ?string
    {
        return $valor ? date('d/m/Y', strtotime((string) $valor)) : null;
    }

    /** Celdas numéricas de verdad (no texto): el valor viaja sin depender del locale del lector. */
    private function num(mixed $valor): ?float
    {
        return $valor === null ? null : round((float) $valor, 2);
    }
}
