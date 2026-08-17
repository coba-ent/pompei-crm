<?php

namespace App\Services\Informes;

use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Informe de Ventas (spec 068, US1/US2): detalle, filtros y KPIs.
 *
 * Espeja deliberadamente la forma de {@see ComprasInformeQuery}: los dos informes hermanos del
 * módulo tienen que leerse igual. Vive fuera del controlador para que los tests de dinero que
 * exige la constitución (principio IV) ejerciten el cálculo sin pasar por HTTP.
 *
 * ## Granularidad
 *
 * El detalle es **una fila por ítem** (`venta_items` y `nota_credito_debito_items`), no una por
 * comprobante. Eso parte las columnas en dos clases:
 *
 * - **De ítem** (Cant., Precio Unitario, Costo Total Actual, CMV Total, Precio Total Neto,
 *   Result.): valen para esa fila y **suman**.
 * - **De comprobante** (Total Comprobante): se **repite** en cada fila del mismo comprobante y
 *   **no se puede sumar por fila**. Por eso no es un KPI (data-model §Invariantes, punto 3).
 *
 * ## Signos
 *
 * Las filas de **Nota de Crédito** salen en negativo (cantidad, precios, neto, costo y CMV) y las
 * de **Nota de Débito** en positivo. El signo lo pone una sola expresión por rama, de modo que
 * `Result. = Precio Total Neto − CMV Total` valga para **todas** las filas sin una rama de
 * cálculo por tipo de comprobante (FR-016).
 *
 * La réplica del bug de Contagram sobre esa celda (R1) **no está acá**: vive confinada en
 * {@see \App\Exports\Informes\InformeVentasExport}, sobre la hoja legible del Excel y nada más.
 */
class VentasInformeQuery
{
    public function __construct(private CostoMercaderiaVendida $cmv) {}

    // -----------------------------------------------------------------------------------
    // Detalle
    // -----------------------------------------------------------------------------------

    /**
     * Detalle completo (ítems de venta + ítems de nota), ya filtrado.
     *
     * Se devuelve como Query Builder y no como Eloquent para que DataTables pagine en SQL: con
     * 5.000 ventas el informe no puede hidratar modelos ni traer el conjunto a memoria (SC-002).
     */
    public function detalle(Request $request): Builder
    {
        return DB::query()->fromSub(
            $this->queryItems($request)->unionAll($this->queryNotas($request)),
            'detalle'
        );
    }

    /** Rama de `venta_items`. El orden de las columnas lo tiene que espejar la rama de notas. */
    private function queryItems(Request $request): Builder
    {
        $cantidad = 'venta_items.cantidad';

        $query = DB::table('venta_items')
            ->join('ventas', 'ventas.id', '=', 'venta_items.venta_id')
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->leftJoin('productos', 'productos.id', '=', 'venta_items.producto_id')
            ->leftJoin('tipos_producto', 'tipos_producto.id', '=', 'productos.tipo_producto_id')
            // Dimensiones del pivot (spec 069). Son todos LEFT JOIN uno-a-uno, así que no
            // multiplican filas ni alteran los totales del detalle ya existente. Las etiquetas,
            // que sí serían muchos-a-muchos, van por subconsulta y no por join.
            ->leftJoin('categorias as cat_venta', 'cat_venta.id', '=', 'ventas.categoria_id')
            ->leftJoin('categorias as cat_padre', 'cat_padre.id', '=', 'cat_venta.categoria_padre_id')
            ->leftJoin('vendedores', 'vendedores.id', '=', 'ventas.vendedor_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'productos.proveedor_id')
            ->leftJoinSub($this->cmv->subconsulta(), CostoMercaderiaVendida::ALIAS,
                CostoMercaderiaVendida::ALIAS.'.producto_id', '=', 'venta_items.producto_id')
            ->whereNull('ventas.deleted_at')
            ->selectRaw($this->proyeccion(
                id: 'ventas.id',
                tipoOperacion: "'venta'",
                fecha: 'ventas.fecha_emision',
                comprobante: ExpresionSql::concatEspacio(['ventas.tipo_comprobante', 'ventas.nro_comprobante']),
                cliente: "COALESCE(clientes.nombre, 'Sin cliente')",
                producto: 'COALESCE(productos.nombre, venta_items.descripcion)',
                cantidad: $cantidad,
                precioUnitario: 'venta_items.precio_unitario',
                // `venta_items.subtotal` ya viene neto de IVA y con los dos descuentos aplicados
                // (el de línea y el general), que es exactamente "Precio Total Neto" (FR-016b).
                precioNeto: 'venta_items.subtotal',
                totalComprobante: 'ventas.total',
                productoId: 'venta_items.producto_id',
                clienteId: 'ventas.cliente_id',
                categoriaId: 'ventas.categoria_id',
                vendedorId: 'ventas.vendedor_id',
                usuarioId: 'ventas.creado_por_id',
                tipoComprobante: 'ventas.tipo_comprobante',
                nroComprobante: 'ventas.nro_comprobante',
                // `venta_items.subtotal` es el neto; el total con impuestos le suma el IVA de la
                // línea. Se reconstruye acá y no se toma de `ventas.total` porque ese es el del
                // comprobante entero.
                // `/ 100.0` y no `/ 100`: con enteros SQLite hace división ENTERA y 21/100 da 0, así
                // que el IVA desaparecía en los tests mientras en MySQL andaba bien. El test del
                // invariante lo detectó — dejarlo así habría hecho que producción y tests
                // calcularan distinto.
                totalConImpuestos: 'venta_items.subtotal * (1 + COALESCE(venta_items.iva_pct, 0) / 100.0)',
                descuentoPct: 'COALESCE(venta_items.descuento_pct, 0)',
                etiquetas: $this->sqlEtiquetas('ventas.id', Venta::class),
            ));

        $this->aplicarFiltrosVenta($query, $request);

        return $query;
    }

    /**
     * Rama de las notas de crédito/débito de venta.
     *
     * A diferencia del Informe de Compras —donde las notas aportan **una fila por nota** porque
     * sus ítems no guardan IVA—, acá los ítems de nota sí tienen `descuento_pct` e `iva_pct`, así
     * que la nota aporta una fila por ítem y el informe conserva la misma unidad en todo el
     * detalle (Clarifications).
     *
     * **La consulta arranca de `notas_credito_debito`, no de sus ítems, y el join es LEFT.** Las
     * notas migradas de Contagram no trajeron detalle (el export de origen no lo tenía), así que
     * con un INNER JOIN desaparecían del informe enteras: el KPI "Total Nota de Crédito" daba
     * $0,00 aunque la plata estuviera bien cargada en `monto`. Una nota sin ítems aporta ahora una
     * fila con cantidad y precio en cero, pero **con su `monto`**, que es lo que alimenta el KPI
     * (ver {@see self::kpis()}, que suma a nivel nota y no a nivel línea). Las columnas que sólo
     * viven en el ítem —unidades, costo, CMV— quedan en cero hasta que se migre ese detalle.
     */
    private function queryNotas(Request $request): Builder
    {
        $tabla = 'nota_credito_debito_items';
        $signo = "(CASE notas_credito_debito.tipo WHEN 'credito' THEN -1 ELSE 1 END)";
        $cantidad = "{$signo} * COALESCE({$tabla}.cantidad, 0)";

        $query = DB::table('notas_credito_debito')
            ->leftJoin($tabla, $tabla.'.nota_credito_debito_id', '=', 'notas_credito_debito.id')
            ->leftJoin('ventas', 'ventas.id', '=', 'notas_credito_debito.venta_id')
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->leftJoin('productos', 'productos.id', '=', $tabla.'.producto_id')
            ->leftJoin('tipos_producto', 'tipos_producto.id', '=', 'productos.tipo_producto_id')
            // Mismas dimensiones que la rama de ventas: la nota hereda categoría y vendedor de su
            // venta de origen, y el proveedor sale del producto de la línea.
            ->leftJoin('categorias as cat_venta', 'cat_venta.id', '=', 'ventas.categoria_id')
            ->leftJoin('categorias as cat_padre', 'cat_padre.id', '=', 'cat_venta.categoria_padre_id')
            ->leftJoin('vendedores', 'vendedores.id', '=', 'ventas.vendedor_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'productos.proveedor_id')
            ->leftJoinSub($this->cmv->subconsulta(), CostoMercaderiaVendida::ALIAS,
                CostoMercaderiaVendida::ALIAS.'.producto_id', '=', $tabla.'.producto_id')
            ->whereNull('notas_credito_debito.deleted_at')
            // Una nota cuya venta fue dada de baja no computa (FR-009); una nota **sin** venta
            // asociada sí, con su cliente vacío (edge case de la spec).
            ->where(fn (Builder $q) => $q->whereNull('notas_credito_debito.venta_id')->orWhereNull('ventas.deleted_at'))
            // Las notas de **compra** son egresos: no tienen nada que hacer en el Informe de Ventas.
            ->whereNull('notas_credito_debito.compra_id')
            ->selectRaw($this->proyeccion(
                id: 'notas_credito_debito.id',
                tipoOperacion: "CASE notas_credito_debito.tipo WHEN 'credito' THEN 'nc' ELSE 'nd' END",
                fecha: 'notas_credito_debito.fecha_emision',
                comprobante: ExpresionSql::concatEspacio(['notas_credito_debito.tipo_comprobante', 'notas_credito_debito.nro_comprobante']),
                cliente: "COALESCE(clientes.nombre, 'Sin cliente')",
                producto: "COALESCE(productos.nombre, notas_credito_debito.descripcion, '')",
                cantidad: $cantidad,
                precioUnitario: "{$signo} * COALESCE({$tabla}.precio, 0)",
                precioNeto: $this->sqlNetoNota($tabla, $signo),
                totalComprobante: "{$signo} * notas_credito_debito.monto",
                productoId: $tabla.'.producto_id',
                clienteId: 'ventas.cliente_id',
                categoriaId: 'ventas.categoria_id',
                vendedorId: 'ventas.vendedor_id',
                usuarioId: 'ventas.creado_por_id',
                tipoComprobante: 'notas_credito_debito.tipo_comprobante',
                nroComprobante: 'notas_credito_debito.nro_comprobante',
                // La nota ya trae su neto con signo; el IVA de la línea se le aplica igual que en
                // ventas. Una nota migrada sin detalle da 0 acá, como el resto de sus columnas
                // de ítem (ver el comentario de este método).
                totalConImpuestos: $this->sqlNetoNota($tabla, $signo)." * (1 + COALESCE({$tabla}.iva_pct, 0) / 100.0)",
                descuentoPct: "COALESCE({$tabla}.descuento_pct, 0)",
                etiquetas: $this->sqlEtiquetas('notas_credito_debito.id', NotaCreditoDebito::class),
            ));

        $this->aplicarFiltrosNota($query, $request);

        return $query;
    }

    /**
     * Neto de una línea de nota, con su signo.
     *
     * Los ítems de nota no guardan el subtotal calculado (a diferencia de `venta_items`), así que
     * hay que reproducir acá la misma cuenta que hace `CalculoComprobante` al grabar: bruto menos
     * el descuento de línea, por el factor del descuento general de la nota. Si el descuento
     * general está cargado como monto y no como porcentaje, el factor se deriva prorrateándolo
     * sobre el bruto de la nota entera, igual que en el alta.
     */
    private function sqlNetoNota(string $tabla, string $signo): string
    {
        // `COALESCE` en la línea: una nota sin ítems (migrada sin detalle) entra igual al informe
        // por el LEFT JOIN, y su neto es 0 —no NULL, que envenenaría el SUM del KPI—.
        $brutoLinea = "COALESCE({$tabla}.cantidad * {$tabla}.precio * (1 - COALESCE({$tabla}.descuento_pct, 0) / 100), 0)";

        $brutoNota = "(SELECT SUM(i2.cantidad * i2.precio * (1 - COALESCE(i2.descuento_pct, 0) / 100)) ".
            "FROM {$tabla} i2 WHERE i2.nota_credito_debito_id = notas_credito_debito.id)";

        $factor = "(CASE WHEN notas_credito_debito.descuento_general_tipo = 'monto' ".
            "THEN (CASE WHEN COALESCE({$brutoNota}, 0) > 0 ".
            "THEN 1 - COALESCE(notas_credito_debito.descuento_general_monto, 0) / {$brutoNota} ELSE 1 END) ".
            'ELSE 1 - COALESCE(notas_credito_debito.descuento_general_pct, 0) / 100 END)';

        return "{$signo} * {$brutoLinea} * {$factor}";
    }

    /**
     * Proyección homogénea de las dos ramas del `UNION ALL`.
     *
     * Las columnas se emiten **con alias en las dos ramas** aunque en una UNION los nombres los
     * fije la primera: sin los alias el SQL es ilegible y se rompe en cuanto alguien reordene las
     * ramas. El orden importa y tiene que ser idéntico.
     */
    /**
     * Etiquetas del comprobante como un solo texto, por subconsulta y NO por join.
     *
     * `etiquetables` es muchos-a-muchos: un comprobante con dos etiquetas duplicaría su fila en
     * el detalle y **rompería todos los totales del informe**. La subconsulta las concatena y
     * deja una sola fila. Mismo patrón que ya usa `ComprasInformeQuery`.
     */
    private function sqlEtiquetas(string $columnaId, string $clase): string
    {
        return 'COALESCE((SELECT '.ExpresionSql::groupConcat('e.nombre').' FROM etiquetas e '.
            'JOIN etiquetables et ON et.etiqueta_id = e.id '.
            'WHERE et.etiquetable_type = '.ExpresionSql::literal($clase)." AND et.etiquetable_id = {$columnaId}), 'Sin etiquetas')";
    }

    private function proyeccion(
        string $id,
        string $tipoOperacion,
        string $fecha,
        string $comprobante,
        string $cliente,
        string $producto,
        string $cantidad,
        string $precioUnitario,
        string $precioNeto,
        string $totalComprobante,
        string $productoId,
        string $clienteId,
        string $categoriaId,
        string $vendedorId,
        string $usuarioId,
        string $tipoComprobante,
        string $nroComprobante,
        string $totalConImpuestos,
        string $descuentoPct,
        string $etiquetas,
    ): string {
        $costoActual = "COALESCE(productos.costo, 0) * ({$cantidad})";
        $cmv = $this->cmv->sqlCmv($cantidad);

        return implode(', ', [
            "{$id} as id",
            "{$tipoOperacion} as tipo_operacion",
            "{$fecha} as fecha",
            "{$comprobante} as comprobante",
            "{$cliente} as cliente",
            "{$producto} as producto",
            "{$cantidad} as cantidad",
            "{$precioUnitario} as precio_unitario",
            "{$costoActual} as costo_total_actual",
            "{$cmv} as cmv_total",
            "{$precioNeto} as precio_neto",
            // Misma fórmula para ventas, NC y ND: sin ramas por tipo (FR-016).
            "({$precioNeto}) - ({$cmv}) as resultado",
            "{$totalComprobante} as total_comprobante",

            // Técnicas: no se muestran, sirven para ordenar y para el export.
            "{$productoId} as producto_id",
            "{$clienteId} as cliente_id",
            "{$categoriaId} as categoria_id",
            "{$vendedorId} as vendedor_id",
            "{$usuarioId} as usuario_id",
            "{$tipoComprobante} as tipo_comprobante",
            "{$nroComprobante} as nro_comprobante",

            // ---- Dimensiones y medidas del motor de tablas dinámicas (spec 069) ----
            //
            // Se agregan AL FINAL y sin tocar nada de arriba a propósito: esta proyección
            // alimenta el detalle y el export de la tanda 2, que ya están en producción.
            // Reordenar o renombrar una columna existente rompería esos dos.
            //
            // Cada dimensión resuelve su rótulo de "sin valor" acá, en SQL, y no en el cliente
            // (FR-018): un registro sin categoría tiene que agruparse bajo "Sin categoría", no
            // desaparecer del cruce.
            "COALESCE(cat_padre.nombre, cat_venta.nombre, 'Sin categoría') as categoria",
            "COALESCE(vendedores.nombre, 'Sin vendedor') as vendedor",
            "COALESCE(tipos_producto.nombre, 'Sin tipo de producto') as tipo_producto",
            "COALESCE(proveedores.nombre, 'Sin proveedor') as proveedor",
            "{$descuentoPct} as descuento_pct",
            "{$etiquetas} as etiquetas",

            // Importe de la línea CON impuestos, que es la medida "Total Venta" del pivot. No se
            // usa `total_comprobante`: ese se repite en cada línea y sumarlo lo contaría una vez
            // por ítem (FR-012b).
            "{$totalConImpuestos} as total_venta",

            // Para contar comprobantes DISTINTOS y no líneas (FR-012b, invariante 3).
            //
            // Lleva el TIPO adelante y no sólo el id: `ventas` y `notas_credito_debito` son tablas
            // distintas con secuencias propias, y hoy comparten 644 ids. Contando sólo el id, una
            // venta y una nota con el mismo número se fusionaban en un comprobante — medido: en
            // 2021 el conteo perdía 12 comprobantes.
            ExpresionSql::concatPlano([$tipoOperacion, "'-'", $id]).' as comprobante_id',
        ]);
    }

    // -----------------------------------------------------------------------------------
    // Filtros
    // -----------------------------------------------------------------------------------

    /** Filtros sobre la rama de ventas: los de comprobante + los de ítem. */
    private function aplicarFiltrosVenta(Builder $query, Request $request): void
    {
        $this->aplicarFiltrosComprobante($query, $request, 'ventas.fecha_emision', 'ventas.id', ['venta']);
        $this->aplicarFiltrosItem($query, $request, 'venta_items.producto_id');

        if ($request->filled('nota_cliente')) {
            $query->where('ventas.nota_cliente', 'like', '%'.$request->input('nota_cliente').'%');
        }

        if ($request->filled('nota_interna')) {
            $query->where('ventas.nota_interna', 'like', '%'.$request->input('nota_interna').'%');
        }

        if ($request->filled('tipo_comprobante')) {
            $query->whereIn('ventas.tipo_comprobante', (array) $request->input('tipo_comprobante'));
        }

        if ($request->filled('nro_comprobante')) {
            $query->where('ventas.nro_comprobante', 'like', '%'.$request->input('nro_comprobante').'%');
        }
    }

    /** Ídem sobre la rama de notas, con las columnas propias de la nota donde corresponde. */
    private function aplicarFiltrosNota(Builder $query, Request $request): void
    {
        $this->aplicarFiltrosComprobante(
            $query, $request,
            'notas_credito_debito.fecha_emision',
            'notas_credito_debito.id',
            ['nc', 'nd'],
        );
        $this->aplicarFiltrosItem($query, $request, 'nota_credito_debito_items.producto_id');

        // Una nota no tiene "Nota Cliente": filtrar por ese campo la excluye, que es lo correcto.
        if ($request->filled('nota_cliente')) {
            $query->whereRaw('1 = 0');
        }

        if ($request->filled('nota_interna')) {
            $query->where('notas_credito_debito.nota_interna', 'like', '%'.$request->input('nota_interna').'%');
        }

        if ($request->filled('tipo_comprobante')) {
            $query->whereIn('notas_credito_debito.tipo_comprobante', (array) $request->input('tipo_comprobante'));
        }

        if ($request->filled('nro_comprobante')) {
            $query->where('notas_credito_debito.nro_comprobante', 'like', '%'.$request->input('nro_comprobante').'%');
        }
    }

    /**
     * Filtros de nivel comprobante. Todos cuelgan de `ventas`, que en la rama de notas entra por
     * `LEFT JOIN`: una nota sin venta asociada queda naturalmente fuera de cualquier filtro de
     * cliente, vendedor o categoría, que es lo esperable.
     *
     * @param  list<string>  $operacionesDeLaRama  qué valores de "Tipo" produce esta rama
     */
    private function aplicarFiltrosComprobante(
        Builder $query,
        Request $request,
        string $columnaFecha,
        string $columnaId,
        array $operacionesDeLaRama,
    ): void {
        // El rango se aplica **dentro de cada rama** y no sobre la unión: si no, la UNION
        // materializa el histórico completo antes de recortar (research R9, SC-002).
        $rango = $this->rango($request);
        $query->whereDate($columnaFecha, '>=', $rango['desde'])
            ->whereDate($columnaFecha, '<=', $rango['hasta']);

        if ($request->filled('id')) {
            $query->where($columnaId, (int) $request->input('id'));
        }

        // "Tipo" = tipo de **operación** (Venta / NC / ND), distinto de "Tipo y N° de Factura",
        // que es el comprobante fiscal (FR-017b).
        if ($request->filled('tipo_operacion')) {
            $pedidos = array_map('strval', (array) $request->input('tipo_operacion'));

            if (array_intersect($pedidos, $operacionesDeLaRama) === []) {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('cliente_id')) {
            $query->whereIn('ventas.cliente_id', (array) $request->input('cliente_id'));
        }

        if ($request->filled('vendedor_id')) {
            $query->whereIn('ventas.vendedor_id', (array) $request->input('vendedor_id'));
        }

        if ($request->filled('usuario_id')) {
            $query->whereIn('ventas.creado_por_id', (array) $request->input('usuario_id'));
        }

        if ($request->filled('categoria_id')) {
            // Por la **raíz**, igual que en Gastos: elegir "Online" trae también sus hijas.
            $ids = (array) $request->input('categoria_id');
            $query->whereExists(fn (Builder $q) => $q->from('categorias as cat_v')
                ->whereColumn('cat_v.id', 'ventas.categoria_id')
                ->where(fn (Builder $qq) => $qq->whereIn('cat_v.id', $ids)->orWhereIn('cat_v.categoria_padre_id', $ids)));
        }

        if ($request->filled('etiqueta_id')) {
            $ids = (array) $request->input('etiqueta_id');
            $query->whereExists(fn (Builder $q) => $q->from('etiquetables')
                ->whereColumn('etiquetables.etiquetable_id', 'ventas.id')
                ->where('etiquetables.etiquetable_type', Venta::class)
                ->whereIn('etiquetables.etiqueta_id', $ids));
        }

        if ($request->filled('facturado')) {
            $existe = fn (Builder $q) => $q->from('comprobantes_fiscales')
                ->whereColumn('comprobantes_fiscales.comprobantable_id', 'ventas.id')
                ->where('comprobantes_fiscales.comprobantable_type', Venta::class)
                ->whereNotNull('comprobantes_fiscales.cae');

            $request->input('facturado') === 'si' ? $query->whereExists($existe) : $query->whereNotExists($existe);
        }

        if ($request->filled('remitos')) {
            $existe = fn (Builder $q) => $q->from('remitos')->whereColumn('remitos.venta_id', 'ventas.id');

            $request->input('remitos') === 'si' ? $query->whereExists($existe) : $query->whereNotExists($existe);
        }

        if ($request->filled('tipo_remito') || $request->filled('nro_remito') || $request->filled('transportista_id')) {
            $query->whereExists(function (Builder $q) use ($request) {
                $q->from('remitos')->whereColumn('remitos.venta_id', 'ventas.id');

                if ($request->filled('tipo_remito')) {
                    $q->whereIn('remitos.tipo', (array) $request->input('tipo_remito'));
                }

                if ($request->filled('nro_remito')) {
                    $q->where('remitos.nro_remito', 'like', '%'.$request->input('nro_remito').'%');
                }

                if ($request->filled('transportista_id')) {
                    $q->whereIn('remitos.transportista_id', (array) $request->input('transportista_id'));
                }
            });
        }

        if ($request->filled('estado_cobro')) {
            $this->filtrarPorEstadoCobro($query, (array) $request->input('estado_cobro'));
        }
    }

    /** Filtros que dependen del ítem, comunes a las dos ramas. */
    private function aplicarFiltrosItem(Builder $query, Request $request, string $columnaProducto): void
    {
        if ($request->filled('producto_id')) {
            $query->whereIn($columnaProducto, (array) $request->input('producto_id'));
        }

        if ($request->filled('tipo_producto_id')) {
            $query->whereIn('productos.tipo_producto_id', (array) $request->input('tipo_producto_id'));
        }

        if ($request->filled('proveedor_id')) {
            $query->whereIn('productos.proveedor_id', (array) $request->input('proveedor_id'));
        }

        // "Productos": líneas con producto de catálogo vs. conceptos escritos a mano.
        if ($request->filled('solo_productos')) {
            $request->input('solo_productos') === 'si'
                ? $query->whereNotNull($columnaProducto)
                : $query->whereNull($columnaProducto);
        }
    }

    /**
     * Estado del Cobro, derivado de los cobros contra el total de la venta.
     *
     * El criterio replica el del listado de Ventas: si divergiera, el informe y la pantalla de
     * origen no conciliarían. La tolerancia de medio centavo evita que un redondeo deje una venta
     * saldada como "Parcial".
     */
    private function filtrarPorEstadoCobro(Builder $query, array $estados): void
    {
        $estados = array_values(array_intersect($estados, ['pendiente', 'parcial', 'cobrado']));

        if ($estados === []) {
            return;
        }

        $cobrado = 'COALESCE((SELECT SUM(c.monto) FROM cobros c WHERE c.venta_id = ventas.id AND c.deleted_at IS NULL), 0)';
        $aCobrar = "(ventas.total - {$cobrado})";

        $query->where(function (Builder $q) use ($estados, $cobrado, $aCobrar) {
            foreach ($estados as $estado) {
                $q->orWhere(fn (Builder $qq) => match ($estado) {
                    'cobrado' => $qq->whereRaw("{$cobrado} > 0")->whereRaw("{$aCobrar} <= 0.005"),
                    'parcial' => $qq->whereRaw("{$cobrado} > 0")->whereRaw("{$aCobrar} > 0.005"),
                    default => $qq->whereRaw("{$cobrado} <= 0"),
                });
            }
        });
    }

    /**
     * Rango de emisión efectivo. Por defecto, el **mes calendario completo** en curso (FR-003):
     * incluye los días futuros del mes, tal como se verificó en Contagram.
     *
     * Se aceptan los dos juegos de nombres: `desde`/`hasta` del contrato de esta spec y
     * `fecha_desde`/`fecha_hasta`, que es como los manda el front de la Tanda 1.
     *
     * @return array{desde: string, hasta: string}
     */
    public function rango(Request $request): array
    {
        $desde = $request->filled('desde') ? $request->input('desde') : $request->input('fecha_desde');
        $hasta = $request->filled('hasta') ? $request->input('hasta') : $request->input('fecha_hasta');

        return [
            'desde' => $desde ?: now()->startOfMonth()->toDateString(),
            'hasta' => $hasta ?: now()->endOfMonth()->toDateString(),
        ];
    }

    // -----------------------------------------------------------------------------------
    // KPIs
    // -----------------------------------------------------------------------------------

    /**
     * Los 11 valores de los 3 bloques (FR-010), siempre sobre el **conjunto filtrado completo** y
     * nunca sobre la página visible (FR-017).
     *
     * Los importes de comprobante (`ventas.total`, `notas.monto`) salen de queries agrupadas por
     * comprobante, no de sumar la columna repetida del detalle: una venta de 10 ítems tiene que
     * aportar su total una sola vez. Las cantidades, el costo, el neto y el CMV sí salen del nivel
     * ítem, que es donde viven.
     *
     * @return array<string, float|int>
     */
    public function kpis(Request $request): array
    {
        $idsVentas = $this->queryItems($request)->distinct()->select('ventas.id');

        $ventas = DB::table('ventas')
            ->whereNull('ventas.deleted_at')
            ->whereIn('ventas.id', $idsVentas)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(ventas.total), 0) as total')
            ->first();

        $idsNotas = $this->queryNotas($request)->distinct()->select('notas_credito_debito.id');

        $notas = DB::table('notas_credito_debito')
            ->whereIn('notas_credito_debito.id', $idsNotas)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN notas_credito_debito.tipo = 'debito' THEN notas_credito_debito.monto ELSE 0 END), 0) as nd, ".
                "COALESCE(SUM(CASE WHEN notas_credito_debito.tipo = 'credito' THEN notas_credito_debito.monto ELSE 0 END), 0) as nc"
            )
            ->first();

        // Nivel ítem: una sola pasada sobre la unión ya filtrada.
        $lineas = DB::query()
            ->fromSub($this->queryItems($request)->unionAll($this->queryNotas($request)), 'd')
            ->selectRaw(
                'COALESCE(SUM(d.cantidad), 0) as unidades, '.
                'COALESCE(SUM(d.costo_total_actual), 0) as costo_actual, '.
                'COALESCE(SUM(d.precio_neto), 0) as precio_neto, '.
                'COALESCE(SUM(d.cmv_total), 0) as cmv'
            )
            ->first();

        $creadas = round((float) $ventas->total, 2);
        $nd = round((float) $notas->nd, 2);
        $nc = round((float) $notas->nc, 2);
        $cantidadVentas = (int) $ventas->cantidad;
        $totalVentas = round($creadas + $nd - $nc, 2);

        $precioNeto = round((float) $lineas->precio_neto, 2);
        $cmvTotal = round((float) $lineas->cmv, 2);

        return [
            'total_ventas_creadas' => $creadas,
            'total_nota_debito' => $nd,
            // Se muestra en positivo y se **resta** en la ecuación (data-model §KPIs).
            'total_nota_credito' => $nc,
            'total_ventas' => $totalVentas,

            'cantidad_prod_serv' => round((float) $lineas->unidades, 3),
            'cantidad_ventas_creadas' => $cantidadVentas,
            // Sin ventas no hay promedio: se devuelve 0, nunca una división por cero (FR-012).
            'venta_promedio' => $cantidadVentas > 0 ? round($totalVentas / $cantidadVentas, 2) : 0.0,
            'costo_actual' => round((float) $lineas->costo_actual, 2),

            'precio_neto' => $precioNeto,
            'cmv' => $cmvTotal,
            'resultado' => round($precioNeto - $cmvTotal, 2),
        ];
    }
}
