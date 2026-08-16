<?php

namespace App\Services\Informes;

use App\Models\Compra;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Informe de Compras (spec 067, US1): query de detalle, filtros y KPIs.
 *
 * Vive fuera del controlador para que los tests de dinero exigidos por la constitución
 * (principio IV) ejerciten el cálculo sin pasar por HTTP, igual que hacen los del servicio
 * de Cuenta Corriente.
 *
 * ## Granularidad y qué se puede sumar
 *
 * El detalle es **una fila por `compra_items`** (data-model.md §2), más una fila por cada
 * NC/ND del período. Eso obliga a distinguir dos clases de columna:
 *
 * - **De ítem** (Cant., Precio, netos, IVA por alícuota): valen para esa fila y **suman**.
 * - **De comprobante** (Total Comprobante, Total Compra, subtotales, percepciones, imp.
 *   internos): se **repiten** en cada fila de la misma compra y **NO se pueden sumar por
 *   fila**. Los KPIs no las tocan: salen de {@see self::kpis()}, que agrupa por comprobante.
 *
 * Es la trampa principal de este informe y tiene test dedicado
 * (`test_total_comprobante_no_se_suma_por_fila`).
 */
class ComprasInformeQuery
{
    /** Filtros multi-valor: OR dentro del campo, AND contra el resto (FR-020). */
    private const ESTADOS_PAGO = ['a_pagar', 'parcial', 'pagado', 'vencido'];

    public function __construct(private DesgloseImpositivoCompra $desglose) {}

    // -----------------------------------------------------------------------------------
    // Detalle
    // -----------------------------------------------------------------------------------

    /**
     * Query de detalle completa: filas de ítem + filas de NC/ND, ya filtrada.
     *
     * Se devuelve como Query Builder (no Eloquent) para que DataTables pagine en SQL: con
     * 5.000 compras el informe no puede hidratar modelos ni traer el conjunto a memoria (SC-006).
     */
    public function detalle(Request $request): Builder
    {
        $items = $this->queryItems($request);
        $notas = $this->queryNotas($request);

        return DB::query()->fromSub($items->unionAll($notas), 'detalle');
    }

    /** Filas de `compra_items` con todas las columnas del contrato. */
    private function queryItems(Request $request): Builder
    {
        $query = DB::table('compra_items')
            ->join('compras', 'compras.id', '=', 'compra_items.compra_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor_id')
            ->leftJoin('condiciones_iva', 'condiciones_iva.id', '=', 'proveedores.condicion_iva_id')
            ->leftJoin('productos', 'productos.id', '=', 'compra_items.producto_id')
            ->leftJoin('tipos_producto', 'tipos_producto.id', '=', 'productos.tipo_producto_id')
            ->whereNull('compras.deleted_at')
            ->selectRaw($this->selectItems());

        $this->aplicarFiltros($query, $request);

        return $query;
    }

    /** Proyección de una fila de ítem. El orden importa: la UNION lo tiene que espejar. */
    private function selectItems(): string
    {
        $d = $this->desglose;

        $columnas = [
            'compras.id as id',
            'compras.fecha_emision as fecha',
            ExpresionSql::concatEspacio(['compras.tipo_comprobante', 'compras.nro_comprobante']).' as comprobante',
            'proveedores.nombre as proveedor',
            'compra_items.descripcion as producto_servicio',
            'compra_items.cantidad as cantidad',
            'compra_items.precio_unitario as precio',
            'compras.total as total_comprobante',

            'compras.fecha_vto_pago as vencimiento',
            'proveedores.cuit as cuit_dni',
            'condiciones_iva.nombre as tipo',
            'compras.tipo_comprobante as tipo_comprobante',
            // Punto de venta y número salen de partir "0001-00000123"; si el número no viene con
            // ese formato (compras migradas, comprobantes cargados a mano) se deja el crudo en
            // N° Factura y el punto de venta vacío, en vez de inventar un corte.
            ExpresionSql::antesDe('compras.nro_comprobante', '-').' as punto_venta',
            ExpresionSql::despuesDe('compras.nro_comprobante', '-').' as nro_factura',
            'productos.codigo as codigo',
            'tipos_producto.nombre as tipo_producto',
            // "Costo Actual": costo VIGENTE del producto, no el histórico de la compra (§5 del
            // data-model). La pantalla lo explica con un tooltip obligatorio (FR-012).
            'COALESCE(productos.costo, 0) * compra_items.cantidad as costo',

            'compras.subtotal_sin_descuento as subtotal_sin_descuento',
            'compras.descuento as descuento_monto',
            'compras.subtotal_con_descuento as subtotal_con_descuento',

            $d->sqlNeto('no_gravado').' as neto_no_gravado',
            $d->sqlNeto('exento').' as neto_exento',
            $d->sqlNeto('gravado').' as neto_gravado',
        ];

        foreach (DesgloseImpositivoCompra::ALICUOTAS as $alicuota => $clave) {
            $columnas[] = $d->sqlIva($alicuota)." as {$clave}";
        }

        $columnas = array_merge($columnas, [
            $d->sqlPercepcion('perc_iva').' as perc_iva',
            $d->sqlPercepcion('perc_iibb').' as perc_iibb',
            $d->sqlPercepcion(DesgloseImpositivoCompra::PERCEPCION_OTRAS).' as otras_percepciones',
            $d->sqlImpuestosInternos().' as imp_internos',
            'compras.total as total_compra',
            'COALESCE((SELECT '.ExpresionSql::groupConcat('e.nombre').' FROM etiquetas e '.
                'JOIN etiquetables et ON et.etiqueta_id = e.id '.
                "WHERE et.etiquetable_type = '".addslashes(Compra::class)."' AND et.etiquetable_id = compras.id), '') as etiquetas",
            // "Afecta Stock" no es una columna guardada: un ítem mueve stock si su producto es de
            // tipo `producto`. Los servicios y las líneas sin producto asociado (descripción
            // libre) no mueven nada.
            "CASE WHEN productos.tipo = 'producto' THEN 'Si' ELSE 'No' END as afecta_stock",
            "'compra' as operacion",
        ]);

        return implode(', ', $columnas);
    }

    /**
     * Filas de NC/ND: **una por nota**, no una por ítem.
     *
     * Los ítems de una NC/ND (`nota_credito_debito_items`) no guardan `iva_pct`, así que su
     * desglose impositivo no es derivable ítem por ítem; lo único fiscalmente cierto es el
     * `monto` de la nota. Se emite entonces una fila por nota, con las columnas de desglose en
     * cero y el importe en Total.
     *
     * El signo lo pone **una sola expresión** para los dos tipos (FR-016): NC resta, ND suma,
     * sin una rama de cálculo por tipo de comprobante — que es justo el bug que el relevamiento
     * encontró en Contagram y que esta spec decide no replicar.
     */
    private function queryNotas(Request $request): Builder
    {
        $signo = "(CASE notas_credito_debito.tipo WHEN 'credito' THEN -1 ELSE 1 END)";
        $monto = "{$signo} * notas_credito_debito.monto";
        // Las columnas de IVA por alícuota van en cero pero **con su alias**: aunque en una UNION
        // los nombres los fija la primera consulta, dejarlas anónimas hace ilegible el SQL y
        // rompe en cuanto alguien reordene las ramas.
        $ivaEnCero = collect(DesgloseImpositivoCompra::ALICUOTAS)
            ->map(fn (string $clave) => "0 as {$clave}")
            ->implode(', ');

        $query = DB::table('notas_credito_debito')
            ->join('compras', 'compras.id', '=', 'notas_credito_debito.compra_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor_id')
            ->leftJoin('condiciones_iva', 'condiciones_iva.id', '=', 'proveedores.condicion_iva_id')
            ->whereNull('notas_credito_debito.deleted_at')
            ->whereNull('compras.deleted_at')
            ->whereNotNull('notas_credito_debito.compra_id')
            ->selectRaw(
                'notas_credito_debito.id as id, '.
                'notas_credito_debito.fecha_emision as fecha, '.
                ExpresionSql::concatEspacio(['notas_credito_debito.tipo_comprobante', 'notas_credito_debito.nro_comprobante']).' as comprobante, '.
                'proveedores.nombre as proveedor, '.
                'notas_credito_debito.descripcion as producto_servicio, '.
                'NULL as cantidad, NULL as precio, '.
                "{$monto} as total_comprobante, ".
                'NULL as vencimiento, proveedores.cuit as cuit_dni, condiciones_iva.nombre as tipo, '.
                'notas_credito_debito.tipo_comprobante as tipo_comprobante, '.
                ExpresionSql::antesDe('notas_credito_debito.nro_comprobante', '-').' as punto_venta, '.
                ExpresionSql::despuesDe('notas_credito_debito.nro_comprobante', '-').' as nro_factura, '.
                'NULL as codigo, NULL as tipo_producto, 0 as costo, '.
                "{$monto} as subtotal_sin_descuento, 0 as descuento_monto, {$monto} as subtotal_con_descuento, ".
                '0 as neto_no_gravado, 0 as neto_exento, 0 as neto_gravado, '.
                $ivaEnCero.', '.
                '0 as perc_iva, 0 as perc_iibb, 0 as otras_percepciones, 0 as imp_internos, '.
                "{$monto} as total_compra, ".
                "'' as etiquetas, 'No' as afecta_stock, ".
                "CASE notas_credito_debito.tipo WHEN 'credito' THEN 'nota_credito' ELSE 'nota_debito' END as operacion"
            );

        // Sólo los filtros que tienen sentido sobre una nota: los de ítem (producto, tipo de
        // producto, descripción) la excluirían siempre, que no es lo que espera quien filtra
        // por proveedor y quiere ver también sus notas.
        $this->aplicarFiltrosComprobante($query, $request, 'notas_credito_debito.fecha_emision');

        return $query;
    }

    // -----------------------------------------------------------------------------------
    // Filtros
    // -----------------------------------------------------------------------------------

    /** Los 12 filtros del contrato: AND entre campos, OR dentro de cada campo multi-valor. */
    public function aplicarFiltros(Builder $query, Request $request): void
    {
        $this->aplicarFiltrosComprobante($query, $request, 'compras.fecha_emision');

        if ($request->filled('producto_servicio')) {
            $query->where('compra_items.descripcion', 'like', '%'.$request->input('producto_servicio').'%');
        }

        if ($request->filled('producto_id')) {
            $query->whereIn('compra_items.producto_id', (array) $request->input('producto_id'));
        }

        if ($request->filled('tipo_producto_id')) {
            $query->whereIn('productos.tipo_producto_id', (array) $request->input('tipo_producto_id'));
        }
    }

    /** Filtros que aplican a la cabecera del comprobante (sirven a compras y a notas). */
    private function aplicarFiltrosComprobante(Builder $query, Request $request, string $columnaFecha): void
    {
        $rango = $this->rango($request);
        $query->whereDate($columnaFecha, '>=', $rango['desde'])
            ->whereDate($columnaFecha, '<=', $rango['hasta']);

        if ($request->filled('id')) {
            $query->where('compras.id', (int) $request->input('id'));
        }

        if ($request->filled('proveedor_id')) {
            $query->whereIn('compras.proveedor_id', (array) $request->input('proveedor_id'));
        }

        if ($request->filled('categoria_id')) {
            $query->whereIn('compras.categoria_id', (array) $request->input('categoria_id'));
        }

        if ($request->filled('usuario_id')) {
            $query->whereIn('compras.creado_por_id', (array) $request->input('usuario_id'));
        }

        if ($request->filled('tipo_comprobante')) {
            $query->whereIn('compras.tipo_comprobante', (array) $request->input('tipo_comprobante'));
        }

        if ($request->filled('nro_comprobante')) {
            $query->where('compras.nro_comprobante', 'like', '%'.$request->input('nro_comprobante').'%');
        }

        if ($request->filled('observacion')) {
            $query->where('compras.nota_interna', 'like', '%'.$request->input('observacion').'%');
        }

        if ($request->filled('etiqueta_id')) {
            $ids = (array) $request->input('etiqueta_id');
            $query->whereExists(fn (Builder $q) => $q->from('etiquetables')
                ->whereColumn('etiquetables.etiquetable_id', 'compras.id')
                ->where('etiquetables.etiquetable_type', Compra::class)
                ->whereIn('etiquetables.etiqueta_id', $ids));
        }

        if ($request->filled('facturado')) {
            $facturado = $request->input('facturado');
            $existe = fn (Builder $q) => $q->from('comprobantes_fiscales')
                ->whereColumn('comprobantes_fiscales.comprobantable_id', 'compras.id')
                ->where('comprobantes_fiscales.comprobantable_type', Compra::class);

            $facturado === 'si' ? $query->whereExists($existe) : $query->whereNotExists($existe);
        }

        if ($request->filled('estado_pago')) {
            $this->filtrarPorEstadoPago($query, (array) $request->input('estado_pago'));
        }
    }

    /**
     * Mismo criterio de estado que el listado de Compras (`CompraController::aplicarFiltros`):
     * si acá divergiera, el informe y la pantalla de origen no conciliarían (SC-004).
     */
    private function filtrarPorEstadoPago(Builder $query, array $estados): void
    {
        $estados = array_values(array_intersect($estados, self::ESTADOS_PAGO));

        if ($estados === []) {
            return;
        }

        $aPagar = $this->sqlAPagar();

        $query->where(function (Builder $q) use ($estados, $aPagar) {
            $pagado = $this->sqlPagado();

            foreach ($estados as $estado) {
                $q->orWhere(function (Builder $qq) use ($estado, $pagado, $aPagar) {
                    match ($estado) {
                        'pagado' => $qq->whereRaw("{$pagado} > 0")->whereRaw("{$aPagar} <= 0.005"),
                        'parcial' => $qq->whereRaw("{$pagado} > 0")->whereRaw("{$aPagar} > 0.005"),
                        'vencido' => $qq->whereNotNull('compras.fecha_vto_pago')
                            ->whereDate('compras.fecha_vto_pago', '<', now())
                            ->whereRaw("{$aPagar} > 0.005"),
                        default => $qq->whereRaw("{$pagado} <= 0"),
                    };
                });
            }
        });
    }

    private function sqlPagado(): string
    {
        return 'COALESCE((SELECT SUM(p.monto) FROM pagos p WHERE p.compra_id = compras.id AND p.deleted_at IS NULL), 0)';
    }

    private function sqlNotas(string $tipo): string
    {
        return "COALESCE((SELECT SUM(n.monto) FROM notas_credito_debito n WHERE n.compra_id = compras.id AND n.tipo = '{$tipo}' AND n.deleted_at IS NULL), 0)";
    }

    private function sqlAPagar(): string
    {
        return '(compras.total + '.$this->sqlNotas('debito').' - '.$this->sqlNotas('credito').' - '.$this->sqlPagado().')';
    }

    /** Rango de emisión efectivo. Por defecto, el mes actual (FR-004b). */
    public function rango(Request $request): array
    {
        return [
            'desde' => $request->filled('fecha_desde') ? $request->input('fecha_desde') : now()->startOfMonth()->toDateString(),
            'hasta' => $request->filled('fecha_hasta') ? $request->input('fecha_hasta') : now()->endOfMonth()->toDateString(),
        ];
    }

    // -----------------------------------------------------------------------------------
    // KPIs
    // -----------------------------------------------------------------------------------

    /**
     * Los 8 KPIs del contrato, con la ecuación
     * `Total Compras = Creadas + ND − NC` (FR-010).
     *
     * Los importes de comprobante salen de una query **agrupada por compra**, nunca sumando la
     * columna repetida del detalle: una compra de 10 ítems tiene que sumar su total una sola vez.
     * Las cantidades y el Costo Actual sí salen del nivel ítem, que es donde viven.
     *
     * @return array<string, float|int>
     */
    public function kpis(Request $request): array
    {
        // Ids de las compras que sobreviven a los filtros (incluidos los de ítem). DISTINCT
        // porque una compra aparece una vez por ítem.
        $idsCompras = $this->queryItems($request)->distinct()->select('compras.id');

        $totales = DB::table('compras')
            ->whereNull('compras.deleted_at')
            ->whereIn('compras.id', $idsCompras)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(compras.total), 0) as total')
            ->first();

        $rango = $this->rango($request);

        $notas = DB::table('notas_credito_debito')
            ->join('compras', 'compras.id', '=', 'notas_credito_debito.compra_id')
            ->whereNull('notas_credito_debito.deleted_at')
            ->whereNull('compras.deleted_at')
            ->whereIn('notas_credito_debito.compra_id', $idsCompras)
            ->whereDate('notas_credito_debito.fecha_emision', '>=', $rango['desde'])
            ->whereDate('notas_credito_debito.fecha_emision', '<=', $rango['hasta'])
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN notas_credito_debito.tipo = 'debito' THEN notas_credito_debito.monto ELSE 0 END), 0) as nd, ".
                "COALESCE(SUM(CASE WHEN notas_credito_debito.tipo = 'credito' THEN notas_credito_debito.monto ELSE 0 END), 0) as nc"
            )
            ->first();

        // Cantidad de Prod./Serv. es la **suma de cantidades**, no el conteo de líneas: 10
        // unidades en una sola línea son 10, no 1. Un ítem con cantidad negativa (bonificación
        // del proveedor) resta con su signo, igual que resta en los importes.
        $items = DB::query()
            ->fromSub($this->queryItems($request), 'i')
            ->selectRaw('COALESCE(SUM(i.cantidad), 0) as unidades, COALESCE(SUM(i.costo), 0) as costo_actual')
            ->first();

        $creadas = round((float) $totales->total, 2);
        $nd = round((float) $notas->nd, 2);
        $nc = round((float) $notas->nc, 2);
        $cantidadCompras = (int) $totales->cantidad;
        $totalCompras = round($creadas + $nd - $nc, 2);

        return [
            'total_compras_creadas' => $creadas,
            'total_nota_debito' => $nd,
            'total_nota_credito' => $nc,
            'total_compras' => $totalCompras,
            'cantidad_prod_serv' => round((float) $items->unidades, 3),
            'cantidad_compras_creadas' => $cantidadCompras,
            // Sin compras no hay promedio: se devuelve 0, nunca una división por cero.
            'compra_promedio' => $cantidadCompras > 0 ? round($totalCompras / $cantidadCompras, 2) : 0.0,
            'costo_actual' => round((float) $items->costo_actual, 2),
        ];
    }
}
