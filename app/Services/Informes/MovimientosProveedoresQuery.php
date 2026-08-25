<?php

namespace App\Services\Informes;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de Cuenta Corriente Proveedores, para los exports de la spec 080 (PDF/Excel):
 * espejo de {@see MovimientosClientesQuery} sobre {@see LibroIvaComprasQuery} — compone el
 * detalle fiscal de Compra + NC/ND con las filas de Pago y Saldo Inicial (research.md D2), sin
 * columna Vendedor y con "Sellos" siempre en 0 (FR-017, no hay concepto modelado hoy).
 *
 * Compras no tiene comprobante fiscal ARCA propio (a diferencia de Ventas): "Tipo de
 * Comprobante"/"Punto de Venta" salen directo de `compras.tipo_comprobante`, sin Punto de Venta
 * (no hay esa columna en `compras`, research.md D5).
 */
class MovimientosProveedoresQuery
{
    private const OPERACIONES_DISPONIBLES = ['compra', 'pago', 'nota_credito', 'nota_debito', 'saldo_inicial'];

    public function __construct(private LibroIvaComprasQuery $libroIva) {}

    public function obtener(Request $request): Collection
    {
        $filas = collect()
            ->merge($this->filasComprasYNotas($request))
            ->merge($this->filasPagos($request))
            ->merge($this->filasSaldoInicial($request));

        $filas = $this->aplicarFiltroProveedor($filas, $request);
        $filas = $this->aplicarFiltroOperacion($filas, $request);

        return $filas->sortBy([
            ['emision', 'desc'],
            ['id', 'desc'],
        ])->values();
    }

    // -----------------------------------------------------------------------------------
    // Compra + NC/ND (desglose fiscal reutilizado del Libro IVA)
    // -----------------------------------------------------------------------------------

    private function filasComprasYNotas(Request $request): Collection
    {
        $fiscal = $this->libroIva->detalle($request)->get();

        $compraIds = [];
        $notaIds = [];
        foreach ($fiscal as $f) {
            // `compras.tipo_comprobante` es un string libre (no enum, a diferencia de Ventas), así
            // que no se puede distinguir Compra de NC/ND contra una lista cerrada de letras — se
            // distingue por el prefijo "NC"/"ND" que sólo lleva el tipo compuesto de las notas.
            if (str_starts_with((string) $f->tipo, 'NC') || str_starts_with((string) $f->tipo, 'ND')) {
                $notaIds[] = (int) $f->id;
            } else {
                $compraIds[] = (int) $f->id;
            }
        }

        $extraCompras = $compraIds === [] ? collect() : $this->extraCompras($compraIds)->keyBy('id');
        $extraNotas = $notaIds === [] ? collect() : $this->extraNotas($notaIds)->keyBy('id');

        return $fiscal->map(function ($f) use ($extraCompras, $extraNotas, $notaIds) {
            $esNota = in_array((int) $f->id, $notaIds, true);
            $extra = $esNota ? $extraNotas->get((int) $f->id) : $extraCompras->get((int) $f->id);

            return $this->filaFiscal($f, $extra, $esNota);
        });
    }

    private function extraCompras(array $compraIds): Collection
    {
        return DB::table('compras')
            ->leftJoin('categorias', 'categorias.id', '=', 'compras.categoria_id')
            ->whereIn('compras.id', $compraIds)
            ->selectRaw(
                'compras.id as id, compras.proveedor_id as proveedor_id, categorias.nombre as categoria, '.
                'compras.subtotal_sin_descuento as subtotal_sin_descuento, compras.descuento as descuento, '.
                'compras.subtotal_con_descuento as subtotal_con_descuento, compras.total as total_compra, '.
                'compras.tipo_comprobante as tipo_comprobante_compra, '.
                'COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0) as pagado, '.
                '(compras.total '.
                "+ COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0) ".
                "- COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0) ".
                '- COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0) '.
                \App\Services\Ingresos\SqlCredito::terminos('compras').' '.
                ') as a_pagar'
            )
            ->get();
    }

    private function extraNotas(array $notaIds): Collection
    {
        return DB::table('notas_credito_debito')
            ->join('compras', 'compras.id', '=', 'notas_credito_debito.compra_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'compras.categoria_id')
            ->whereIn('notas_credito_debito.id', $notaIds)
            ->selectRaw('notas_credito_debito.id as id, compras.proveedor_id as proveedor_id, categorias.nombre as categoria, notas_credito_debito.compra_id as compra_id, notas_credito_debito.descripcion as descripcion')
            ->get();
    }

    /** @return array<string, mixed> */
    private function filaFiscal($f, $extra, bool $esNota): array
    {
        $operacion = $esNota
            ? (str_starts_with($f->tipo, 'NC') ? 'nota_credito' : 'nota_debito')
            : 'compra';

        $comun = [
            'id' => (int) $f->id,
            'emision' => (string) $f->emision,
            'proveedor' => $f->contraparte,
            'proveedor_id' => $extra->proveedor_id ?? null,
            'cuit' => $f->cuit,
            'operacion' => $operacion,
            'categoria' => $extra->categoria ?? null,
            'medio_pago' => null,
            'descripcion' => $esNota ? ($extra->descripcion ?? null) : null,
            'aplicada_nro_factura' => null,
            'fecha_factura_aplicada' => null,
            'neto_no_gravado' => (float) $f->neto_no_gravado,
            'neto_gravado' => (float) $f->neto_gravado,
            'iva_2_5' => (float) $f->iva_2_5,
            'iva_5' => (float) $f->iva_5,
            'iva_10_5' => (float) $f->iva_10_5,
            'iva_21' => (float) $f->iva_21,
            'iva_27' => (float) $f->iva_27,
            'exento' => (float) $f->neto_exento,
            'no_gravado' => (float) $f->neto_no_gravado,
            'perc_iva' => (float) $f->perc_iva,
            'perc_iibb' => (float) $f->perc_iibb,
            'imp_internos' => (float) $f->imp_internos,
            'imp_municipales' => (float) $f->imp_municipales,
            'sellos' => 0.0,
        ];

        if ($esNota) {
            return array_merge($comun, [
                'nro_comprobante' => $f->nro_comprobante,
                'tipo_comprobante' => null,
                'punto_venta' => null,
                'id_compra' => null,
                'subtotal_sin_descuento' => null,
                'descuento' => null,
                'subtotal_con_descuento' => null,
                'total_compra' => null,
                'pagado' => null,
                'a_pagar' => null,
            ]);
        }

        return array_merge($comun, [
            'nro_comprobante' => $f->nro_comprobante,
            'tipo_comprobante' => $extra->tipo_comprobante_compra ?? null,
            'punto_venta' => null,
            'id_compra' => null,
            'subtotal_sin_descuento' => $extra ? (float) $extra->subtotal_sin_descuento : null,
            'descuento' => $extra ? (float) $extra->descuento : null,
            'subtotal_con_descuento' => $extra ? (float) $extra->subtotal_con_descuento : null,
            'total_compra' => $extra ? (float) $extra->total_compra : null,
            'pagado' => $extra ? (float) $extra->pagado : null,
            'a_pagar' => $extra ? (float) $extra->a_pagar : null,
        ]);
    }

    // -----------------------------------------------------------------------------------
    // Pagos (columnas fiscales en blanco, FR-010)
    // -----------------------------------------------------------------------------------

    private function filasPagos(Request $request): Collection
    {
        $query = DB::table('pagos')
            ->join('compras', 'compras.id', '=', 'pagos.compra_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor_id')
            ->leftJoin('cuentas_tesoreria', 'cuentas_tesoreria.id', '=', 'pagos.cuenta_tesoreria_id')
            ->whereNull('pagos.deleted_at')
            ->whereNull('compras.deleted_at')
            ->selectRaw(
                'pagos.id as id, pagos.fecha as emision, compras.proveedor_id as proveedor_id, '.
                "COALESCE(proveedores.nombre, 'Sin proveedor') as proveedor, proveedores.cuit as cuit, ".
                'cuentas_tesoreria.nombre as medio_pago, pagos.nota as descripcion, '.
                'compras.nro_comprobante as nro_comprobante, '.
                'compras.id as id_compra, pagos.monto as pagado'
            );

        [$desde, $hasta] = [$request->input('fecha_desde'), $request->input('fecha_hasta')];
        if ($desde) {
            $query->whereDate('pagos.fecha', '>=', $desde);
        }
        if ($hasta) {
            $query->whereDate('pagos.fecha', '<=', $hasta);
        }

        return $query->get()->map(fn ($p) => [
            'id' => (int) $p->id,
            'emision' => (string) $p->emision,
            'proveedor' => $p->proveedor,
            'proveedor_id' => $p->proveedor_id,
            'cuit' => $p->cuit,
            'operacion' => 'pago',
            'categoria' => null,
            'medio_pago' => $p->medio_pago,
            'descripcion' => $p->descripcion,
            'tipo_comprobante' => 'Orden de Pago',
            'punto_venta' => null,
            'nro_comprobante' => $p->nro_comprobante,
            'aplicada_nro_factura' => null,
            'fecha_factura_aplicada' => null,
            'id_compra' => (int) $p->id_compra,
            'subtotal_sin_descuento' => null,
            'descuento' => null,
            'subtotal_con_descuento' => null,
            'neto_no_gravado' => null,
            'neto_gravado' => null,
            'iva_2_5' => null,
            'iva_5' => null,
            'iva_10_5' => null,
            'iva_21' => null,
            'iva_27' => null,
            'exento' => null,
            'no_gravado' => null,
            'perc_iva' => null,
            'perc_iibb' => null,
            'imp_internos' => null,
            'imp_municipales' => null,
            'sellos' => null,
            'total_compra' => null,
            'pagado' => (float) $p->pagado,
            'a_pagar' => null,
        ]);
    }

    // -----------------------------------------------------------------------------------
    // Saldo Inicial
    // -----------------------------------------------------------------------------------

    private function filasSaldoInicial(Request $request): Collection
    {
        $query = DB::table('proveedores')
            ->where('proveedores.saldo_inicial', '!=', 0)
            // Los proveedores de ajuste de conciliación no se listan (mismo criterio que
            // CuentaCorrienteProveedorController::queryMovimientos()).
            ->where('proveedores.nombre', 'not like', \App\Models\Proveedor::PREFIJO_AJUSTE);

        [$desde, $hasta] = [$request->input('fecha_desde'), $request->input('fecha_hasta')];
        if ($desde) {
            $query->whereDate('proveedores.saldo_inicial_fecha', '>=', $desde);
        }
        if ($hasta) {
            $query->whereDate('proveedores.saldo_inicial_fecha', '<=', $hasta);
        }

        return $query->selectRaw('proveedores.id as id, proveedores.saldo_inicial_fecha as emision, proveedores.id as proveedor_id, proveedores.nombre as proveedor, proveedores.cuit as cuit, proveedores.saldo_inicial as a_pagar')
            ->get()
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'emision' => (string) $s->emision,
                'proveedor' => $s->proveedor,
                'proveedor_id' => $s->proveedor_id,
                'cuit' => $s->cuit,
                'operacion' => 'saldo_inicial',
                'categoria' => null,
                'medio_pago' => null,
                'descripcion' => null,
                'tipo_comprobante' => null,
                'punto_venta' => null,
                'nro_comprobante' => null,
                'aplicada_nro_factura' => null,
                'fecha_factura_aplicada' => null,
                'id_compra' => null,
                'subtotal_sin_descuento' => null,
                'descuento' => null,
                'subtotal_con_descuento' => null,
                'neto_no_gravado' => null,
                'neto_gravado' => null,
                'iva_2_5' => null,
                'iva_5' => null,
                'iva_10_5' => null,
                'iva_21' => null,
                'iva_27' => null,
                'exento' => null,
                'no_gravado' => null,
                'perc_iva' => null,
                'perc_iibb' => null,
                'imp_internos' => null,
                'imp_municipales' => null,
                'sellos' => null,
                'total_compra' => null,
                'pagado' => null,
                'a_pagar' => (float) $s->a_pagar,
            ]);
    }

    // -----------------------------------------------------------------------------------
    // Filtros de pantalla (proveedor_id[], operacion[])
    // -----------------------------------------------------------------------------------

    private function aplicarFiltroProveedor(Collection $filas, Request $request): Collection
    {
        $proveedores = array_filter(array_map('intval', (array) $request->input('proveedor_id', [])));
        if ($proveedores === []) {
            return $filas;
        }

        return $filas->filter(function (array $f) use ($proveedores) {
            $proveedorId = $f['proveedor_id'] ?? null;

            return $proveedorId !== null && in_array((int) $proveedorId, $proveedores, true);
        });
    }

    private function aplicarFiltroOperacion(Collection $filas, Request $request): Collection
    {
        $operaciones = array_values(array_intersect(
            array_filter((array) $request->input('operacion', [])),
            self::OPERACIONES_DISPONIBLES
        ));

        if ($operaciones === []) {
            return $filas;
        }

        return $filas->filter(fn (array $f) => in_array($f['operacion'], $operaciones, true));
    }
}
