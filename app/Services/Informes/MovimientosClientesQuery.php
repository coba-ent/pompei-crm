<?php

namespace App\Services\Informes;

use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de Cuenta Corriente Clientes, para los exports de la spec 080 (PDF/Excel): compone
 * {@see LibroIvaVentasQuery::detalle()} (Venta + NC/ND, con desglose fiscal completo, spec 077) con
 * las filas de Cobro y Saldo Inicial que el Libro IVA no tiene (research.md D2), y enriquece las
 * filas de Venta/NC/ND con las columnas que el Libro IVA no expone (Categoría, Tipo de
 * Comprobante/Punto de Venta separados, Vendedor, Subtotal sin/con Descuento, Descuento, Total
 * Venta, Cobrado, A Cobrar — research.md D3/D4/D5).
 *
 * Devuelve una `Collection` de arrays asociativos con las 34 columnas de data-model.md, ya
 * ordenada por Emisión desc / Id desc (mismo orden que la pantalla). No reimplementa ninguna
 * clasificación fiscal: los importes de neto/IVA/percepciones vienen tal cual del Libro IVA.
 */
class MovimientosClientesQuery
{
    /** Operaciones que expone el filtro "Operación" de la pantalla (igual a CuentaCorrienteController). */
    private const OPERACIONES_DISPONIBLES = ['venta', 'cobro', 'nota_credito', 'nota_debito', 'saldo_inicial'];

    public function __construct(private LibroIvaVentasQuery $libroIva) {}

    public function obtener(Request $request): Collection
    {
        $filas = collect()
            ->merge($this->filasVentasYNotas($request))
            ->merge($this->filasCobros($request))
            ->merge($this->filasSaldoInicial($request));

        $filas = $this->aplicarFiltroCliente($filas, $request);
        $filas = $this->aplicarFiltroOperacion($filas, $request);

        return $filas->sortBy([
            ['emision', 'desc'],
            ['id', 'desc'],
        ])->values();
    }

    // -----------------------------------------------------------------------------------
    // Venta + NC/ND (desglose fiscal reutilizado del Libro IVA)
    // -----------------------------------------------------------------------------------

    private function filasVentasYNotas(Request $request): Collection
    {
        // A diferencia del Libro IVA del Contador, la pantalla de Movimientos muestra TODAS las
        // ventas del período (ARCA + manuales) — se fuerza el universo completo acá para no
        // heredar el default `arca=true/manuales=false` de `LibroIvaVentasQuery::filtrarArcaManuales()`,
        // que dejaría afuera cualquier venta sin comprobante fiscal aprobado.
        $requestFiscal = clone $request;
        $requestFiscal->query->add(['arca' => true, 'manuales' => true]);
        $fiscal = $this->libroIva->detalle($requestFiscal)->get();

        $ventaIds = [];
        $notaIds = [];
        foreach ($fiscal as $f) {
            if (in_array($f->tipo, ['A', 'B', 'C', 'E'], true)) {
                $ventaIds[] = (int) $f->id;
            } else {
                $notaIds[] = (int) $f->id;
            }
        }

        $extraVentas = $ventaIds === [] ? collect() : $this->extraVentas($ventaIds)->keyBy('id');
        $extraNotas = $notaIds === [] ? collect() : $this->extraNotas($notaIds)->keyBy('id');

        return $fiscal->map(function ($f) use ($extraVentas, $extraNotas) {
            $esNota = ! in_array($f->tipo, ['A', 'B', 'C', 'E'], true);
            $extra = $esNota ? $extraNotas->get((int) $f->id) : $extraVentas->get((int) $f->id);

            return $this->filaFiscal($f, $extra, $esNota);
        });
    }

    private function extraVentas(array $ventaIds): Collection
    {
        $puntoVenta = "(SELECT pv.numero FROM comprobantes_fiscales cf ".
            'LEFT JOIN puntos_venta pv ON pv.id = cf.punto_venta_id '.
            'WHERE cf.comprobantable_id = ventas.id AND cf.comprobantable_type = '.ExpresionSql::literal(Venta::class).
            " AND cf.estado = 'aprobado' AND cf.deleted_at IS NULL ORDER BY cf.id DESC LIMIT 1)";

        return DB::table('ventas')
            ->leftJoin('categorias', 'categorias.id', '=', 'ventas.categoria_id')
            ->leftJoin('vendedores', 'vendedores.id', '=', 'ventas.vendedor_id')
            ->whereIn('ventas.id', $ventaIds)
            ->selectRaw(
                'ventas.id as id, ventas.cliente_id as cliente_id, categorias.nombre as categoria, vendedores.nombre as vendedor, '.
                'ventas.subtotal_sin_descuento as subtotal_sin_descuento, ventas.descuento as descuento, '.
                'ventas.subtotal_con_descuento as subtotal_con_descuento, ventas.total as total_venta, '.
                'ventas.tipo_comprobante as tipo_comprobante_venta, '.
                "{$puntoVenta} as punto_venta, ".
                'COALESCE((SELECT SUM(c.monto) FROM cobros c WHERE c.venta_id = ventas.id AND c.deleted_at IS NULL), 0) as cobrado, '.
                '(ventas.total '.
                "+ COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.venta_id = ventas.id AND n.tipo = 'debito' AND n.deleted_at IS NULL), 0) ".
                "- COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.venta_id = ventas.id AND n.tipo = 'credito' AND n.deleted_at IS NULL), 0) ".
                '- COALESCE((SELECT SUM(c.monto) FROM cobros c WHERE c.venta_id = ventas.id AND c.deleted_at IS NULL), 0) '.
                \App\Services\Ingresos\SqlCredito::terminos('ventas').' '.
                ') as a_cobrar'
            )
            ->get();
    }

    private function extraNotas(array $notaIds): Collection
    {
        return DB::table('notas_credito_debito')
            ->join('ventas', 'ventas.id', '=', 'notas_credito_debito.venta_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'ventas.categoria_id')
            ->whereIn('notas_credito_debito.id', $notaIds)
            ->selectRaw('notas_credito_debito.id as id, ventas.cliente_id as cliente_id, categorias.nombre as categoria, notas_credito_debito.venta_id as venta_id, notas_credito_debito.descripcion as descripcion')
            ->get();
    }

    /** @return array<string, mixed> */
    private function filaFiscal($f, $extra, bool $esNota): array
    {
        $operacion = $esNota
            ? (str_starts_with($f->tipo, 'NC') ? 'nota_credito' : 'nota_debito')
            : 'venta';

        $comun = [
            'id' => (int) $f->id,
            'emision' => (string) $f->emision,
            'cliente' => $f->contraparte,
            'cliente_id' => $extra->cliente_id ?? null,
            'cuit' => $f->cuit,
            'operacion' => $operacion,
            'categoria' => $extra->categoria ?? null,
            'medio_cobro' => null,
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
        ];

        if ($esNota) {
            return array_merge($comun, [
                'nro_comprobante' => $f->nro_comprobante,
                'tipo_comprobante' => null,
                'punto_venta' => null,
                'id_venta' => null,
                'vendedor' => null,
                'subtotal_sin_descuento' => null,
                'descuento' => null,
                'subtotal_con_descuento' => null,
                'total_venta' => null,
                'cobrado' => null,
                'a_cobrar' => null,
            ]);
        }

        return array_merge($comun, [
            'nro_comprobante' => $f->nro_comprobante,
            'tipo_comprobante' => $extra->tipo_comprobante_venta ?? null,
            'punto_venta' => $extra->punto_venta ?? null,
            'id_venta' => null,
            'vendedor' => $extra->vendedor ?? null,
            'subtotal_sin_descuento' => $extra ? (float) $extra->subtotal_sin_descuento : null,
            'descuento' => $extra ? (float) $extra->descuento : null,
            'subtotal_con_descuento' => $extra ? (float) $extra->subtotal_con_descuento : null,
            'total_venta' => $extra ? (float) $extra->total_venta : null,
            'cobrado' => $extra ? (float) $extra->cobrado : null,
            'a_cobrar' => $extra ? (float) $extra->a_cobrar : null,
        ]);
    }

    // -----------------------------------------------------------------------------------
    // Cobros (columnas fiscales en blanco, FR-010)
    // -----------------------------------------------------------------------------------

    private function filasCobros(Request $request): Collection
    {
        $query = DB::table('cobros')
            ->join('ventas', 'ventas.id', '=', 'cobros.venta_id')
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->leftJoin('cuentas_tesoreria', 'cuentas_tesoreria.id', '=', 'cobros.cuenta_tesoreria_id')
            ->whereNull('cobros.deleted_at')
            ->whereNull('ventas.deleted_at')
            ->selectRaw(
                'cobros.id as id, cobros.fecha as emision, ventas.cliente_id as cliente_id, '.
                "COALESCE(clientes.nombre, 'Sin cliente') as cliente, clientes.cuit as cuit, ".
                'cuentas_tesoreria.nombre as medio_cobro, cobros.nota as descripcion, '.
                'ventas.nro_comprobante as nro_comprobante, '.
                'ventas.id as id_venta, cobros.monto as cobrado'
            );

        [$desde, $hasta] = [$request->input('fecha_desde'), $request->input('fecha_hasta')];
        if ($desde) {
            $query->whereDate('cobros.fecha', '>=', $desde);
        }
        if ($hasta) {
            $query->whereDate('cobros.fecha', '<=', $hasta);
        }

        return $query->get()->map(fn ($c) => [
            'id' => (int) $c->id,
            'emision' => (string) $c->emision,
            'cliente' => $c->cliente,
            'cliente_id' => $c->cliente_id,
            'cuit' => $c->cuit,
            'operacion' => 'cobro',
            'categoria' => null,
            'medio_cobro' => $c->medio_cobro,
            'descripcion' => $c->descripcion,
            'tipo_comprobante' => 'Recibo',
            'punto_venta' => null,
            'nro_comprobante' => $c->nro_comprobante,
            'aplicada_nro_factura' => null,
            'fecha_factura_aplicada' => null,
            'id_venta' => (int) $c->id_venta,
            'vendedor' => null,
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
            'total_venta' => null,
            'cobrado' => (float) $c->cobrado,
            'a_cobrar' => null,
        ]);
    }

    // -----------------------------------------------------------------------------------
    // Saldo Inicial
    // -----------------------------------------------------------------------------------

    private function filasSaldoInicial(Request $request): Collection
    {
        $query = DB::table('clientes')->where('clientes.saldo_inicial', '!=', 0);

        [$desde, $hasta] = [$request->input('fecha_desde'), $request->input('fecha_hasta')];
        if ($desde) {
            $query->whereDate('clientes.saldo_inicial_fecha', '>=', $desde);
        }
        if ($hasta) {
            $query->whereDate('clientes.saldo_inicial_fecha', '<=', $hasta);
        }

        return $query->selectRaw('clientes.id as id, clientes.saldo_inicial_fecha as emision, clientes.id as cliente_id, clientes.nombre as cliente, clientes.cuit as cuit, clientes.saldo_inicial as a_cobrar')
            ->get()
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'emision' => (string) $s->emision,
                'cliente' => $s->cliente,
                'cliente_id' => $s->cliente_id,
                'cuit' => $s->cuit,
                'operacion' => 'saldo_inicial',
                'categoria' => null,
                'medio_cobro' => null,
                'descripcion' => null,
                'tipo_comprobante' => null,
                'punto_venta' => null,
                'nro_comprobante' => null,
                'aplicada_nro_factura' => null,
                'fecha_factura_aplicada' => null,
                'id_venta' => null,
                'vendedor' => null,
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
                'total_venta' => null,
                'cobrado' => null,
                'a_cobrar' => (float) $s->a_cobrar,
            ]);
    }

    // -----------------------------------------------------------------------------------
    // Filtros de pantalla (cliente_id[], operacion[])
    // -----------------------------------------------------------------------------------

    private function aplicarFiltroCliente(Collection $filas, Request $request): Collection
    {
        $clientes = array_filter(array_map('intval', (array) $request->input('cliente_id', [])));
        if ($clientes === []) {
            return $filas;
        }

        return $filas->filter(function (array $f) use ($clientes) {
            $clienteId = $f['cliente_id'] ?? null;

            // Las filas de Venta/NC/ND no traen `cliente_id` propio (vienen del Libro IVA, que
            // sólo expone el nombre) — se resuelve consultando `ventas.cliente_id` sólo si hace
            // falta filtrar, evitando el costo en el caso común (sin filtro de cliente).
            return $clienteId !== null && in_array((int) $clienteId, $clientes, true);
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
