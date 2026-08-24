/**
 * Informe de Ventas (spec 068, US1/US2).
 *
 * Todo pasa por AJAX: cambiar el rango, aplicar filtros o exportar nunca recargan la página
 * (FR-004). jQuery, DataTables, Select2, Toastr y daterangepicker los carga el template por
 * pagelevel `informe-ventas`.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[informe-ventas] jQuery no está disponible.');
        return;
    }

    const cfg = window.InformeVentasConfig || {};
    const rutas = cfg.rutas || {};

    if (window.toastr) {
        window.toastr.options = {
            closeButton: true, progressBar: true, positionClass: 'toast-top-right',
            preventDuplicates: true, newestOnTop: true, timeOut: 4000, extendedTimeOut: 1500,
        };
    }

    function avisar(mensaje, tipo) {
        if (window.toastr) { window.toastr[tipo || 'error'](mensaje); } else { console.error(mensaje); }
    }

    $(function () {
        const $tabla = $('#tabla-informe-ventas');
        if (!$tabla.length) { return; }

        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }

        const money = (v) => (v === null || v === undefined || v === '')
            ? ''
            : new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
        // Formato contable: negativos en rojo y entre paréntesis, SÓLO en pantalla (FR-016). Los
        // archivos exportados siguen con números negativos crudos, para que Excel los sume.
        const moneyContable = (v) => {
            if (v === null || v === undefined || v === '') { return ''; }
            const numero = Number(v);
            const formateado = money(Math.abs(numero));

            return numero < 0
                ? '<span class="text-danger">(' + formateado + ')</span>'
                : formateado;
        };
        const cantidad = (v) => (v === null || v === undefined || v === '')
            ? ''
            : new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 3 }).format(v);
        const fecha = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('/') : '');
        // FR-014: en PANTALLA la columna de comprobante muestra el tipo de OPERACIÓN, no el tipo y
        // número de comprobante fiscal. Los exports mantienen su columna "Comprobante" (tipo +
        // número) tal cual, porque ya tienen una columna aparte para el tipo de operación — cambiar
        // acá la fuente (`tipo_operacion`, ya proyectada) evita duplicar el criterio en el export.
        const OPERACION = { venta: 'Venta', nc: 'Nota de Crédito', nd: 'Nota de Débito' };
        const operacion = (v) => OPERACION[v] || v || '';
        // FR-015: sólo en pantalla, el código del producto va ANTES del nombre.
        const productoPantalla = (v, tipo, fila) => (fila.codigo ? fila.codigo + ' - ' + v : v);

        // Catálogos chicos: los <option> ya vienen renderizados por Blade.
        ['#filtro-tipo-producto', '#filtro-vendedor', '#filtro-categoria', '#filtro-etiqueta',
            '#filtro-tipo-comprobante', '#filtro-usuario', '#filtro-estado-cobro',
            '#filtro-tipo-operacion', '#filtro-tipo-remito', '#filtro-transportista',
        ].forEach((sel) => initSelect2($(sel), { placeholder: 'Todos', allowClear: true }));

        ['#filtro-solo-productos', '#filtro-facturado', '#filtro-remitos']
            .forEach((sel) => initSelect2($(sel), { placeholder: 'Todos' }));

        // Catálogos grandes: Select2 con `ajax` en vez de volcar miles de <option> en el HTML
        // (regla #5 de CLAUDE.md).
        function select2Remoto(selector, url, texto) {
            initSelect2($(selector), {
                placeholder: 'Todos', allowClear: true, multiple: true,
                ajax: {
                    url: url, delay: 250,
                    data: (params) => ({ q: params.term }),
                    processResults: (data) => ({ results: (data.data || []).map(texto) }),
                },
            });
        }

        select2Remoto('#filtro-cliente', rutas.clientesOpciones, (c) => ({ id: c.id, text: c.nombre }));
        select2Remoto('#filtro-producto', rutas.productosOpciones,
            (p) => ({ id: p.id, text: p.codigo ? p.codigo + ' — ' + p.nombre : p.nombre }));
        select2Remoto('#filtro-proveedor', rutas.proveedoresOpciones, (p) => ({ id: p.id, text: p.nombre }));

        // --- Rango de Emisión: arranca en el mes calendario actual completo (FR-003) ---
        const inicial = window.RangoEmision.mesActual();
        let fechaDesde = inicial.desde;
        let fechaHasta = inicial.hasta;

        const $rango = $('#filtro-rango-emision');
        $rango.val(window.RangoEmision.etiqueta(fechaDesde, fechaHasta));

        if ($.fn.daterangepicker) {
            $rango.daterangepicker(window.RangoEmision.opciones({
                startDate: window.moment(fechaDesde), endDate: window.moment(fechaHasta),
            }));
            $rango.on('apply.daterangepicker', function (e, picker) {
                fechaDesde = picker.startDate.format('YYYY-MM-DD');
                fechaHasta = picker.endDate.format('YYYY-MM-DD');
                $(this).val(window.RangoEmision.etiqueta(fechaDesde, fechaHasta));
                recargar();
            });
            // "Borrar filtro" vuelve al mes actual y no a "sin rango": un informe de ventas sin
            // acotar barrería el histórico entero en cada apertura.
            $rango.on('cancel.daterangepicker', function () {
                const m = window.RangoEmision.mesActual();
                fechaDesde = m.desde; fechaHasta = m.hasta;
                $(this).val(window.RangoEmision.etiqueta(fechaDesde, fechaHasta));
                recargar();
            });
            $('#btn-limpiar-rango-emision').on('click', function () {
                $rango.trigger('cancel.daterangepicker');
            });
        }

        function filtros() {
            return {
                desde: fechaDesde,
                hasta: fechaHasta,
                id: $('#filtro-id').val(),
                producto_id: $('#filtro-producto').val(),
                tipo_producto_id: $('#filtro-tipo-producto').val(),
                cliente_id: $('#filtro-cliente').val(),
                solo_productos: $('#filtro-solo-productos').val(),
                facturado: $('#filtro-facturado').val(),
                vendedor_id: $('#filtro-vendedor').val(),
                categoria_id: $('#filtro-categoria').val(),
                proveedor_id: $('#filtro-proveedor').val(),
                etiqueta_id: $('#filtro-etiqueta').val(),
                tipo_comprobante: $('#filtro-tipo-comprobante').val(),
                nro_comprobante: $('#filtro-nro-comprobante').val(),
                usuario_id: $('#filtro-usuario').val(),
                nota_cliente: $('#filtro-nota-cliente').val(),
                nota_interna: $('#filtro-nota-interna').val(),
                estado_cobro: $('#filtro-estado-cobro').val(),
                tipo_operacion: $('#filtro-tipo-operacion').val(),
                remitos: $('#filtro-remitos').val(),
                tipo_remito: $('#filtro-tipo-remito').val(),
                nro_remito: $('#filtro-nro-remito').val(),
                transportista_id: $('#filtro-transportista').val(),
            };
        }

        const tabla = $tabla.DataTable({
            processing: true,
            serverSide: true,
            language: {
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                infoEmpty: 'Sin ventas en el período',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No hay ventas en el período seleccionado',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: {
                url: rutas.data,
                data: (d) => $.extend(d, filtros()),
                error: function (xhr) {
                    avisar((xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo cargar el informe.');
                },
            },
            columns: [
                { data: 'id', name: 'id' },
                { data: 'fecha', name: 'fecha', render: fecha },
                { data: 'tipo_operacion', name: 'tipo_operacion', defaultContent: '', render: operacion },
                { data: 'cliente', name: 'cliente', defaultContent: '' },
                { data: 'producto', name: 'producto', defaultContent: '', render: productoPantalla },
                { data: 'cantidad', name: 'cantidad', className: 'text-end', render: cantidad },
                { data: 'precio_unitario', name: 'precio_unitario', className: 'text-end', render: moneyContable },
                { data: 'costo_total_actual', name: 'costo_total_actual', className: 'text-end', render: moneyContable },
                { data: 'cmv_total', name: 'cmv_total', className: 'text-end', render: moneyContable },
                { data: 'precio_neto', name: 'precio_neto', className: 'text-end', render: moneyContable },
                { data: 'resultado', name: 'resultado', className: 'text-end', render: moneyContable },
                // Importe DE LA LÍNEA, no el del comprobante repetido (spec 076, FR-001). El
                // rótulo de cabecera sigue diciendo "Total Comprobante" (FR-017): es sólo la
                // columna de datos la que cambia.
                { data: 'total_venta', name: 'total_venta', className: 'text-end', render: moneyContable },
            ],
            // Fecha descendente: lo más reciente arriba (FR-017).
            order: [[1, 'desc']],
            stateSave: true,
        });

        function actualizarKpis() {
            $.getJSON(rutas.stats, filtros())
                .done(function (k) {
                    $('#kpi-creadas').text('$ ' + money(k.total_ventas_creadas));
                    $('#kpi-nd').text('$ ' + money(k.total_nota_debito));
                    $('#kpi-nc').text('$ ' + money(k.total_nota_credito));
                    $('#kpi-total').text('$ ' + money(k.total_ventas));
                    $('#kpi-unidades').text(cantidad(k.cantidad_prod_serv));
                    $('#kpi-cantidad').text(k.cantidad_ventas_creadas);
                    $('#kpi-promedio').text('$ ' + money(k.venta_promedio));
                    $('#kpi-costo').text('$ ' + money(k.costo_actual));
                    $('#kpi-neto').text('$ ' + money(k.precio_neto));
                    $('#kpi-cmv').text('$ ' + money(k.cmv));
                    $('#kpi-resultado').text('$ ' + money(k.resultado));
                })
                .fail(function (xhr) {
                    avisar((xhr.responseJSON && xhr.responseJSON.message) || 'No se pudieron calcular los totales.');
                });
        }

        // Los KPIs tienen que reflejar exactamente los mismos filtros que la tabla, así que se
        // recalculan junto con ella y no sólo al tocar "Buscar".
        function recargar() {
            tabla.ajax.reload();
            actualizarKpis();
        }

        actualizarKpis();

        $('#btn-aplicar-filtros').on('click', recargar);
        $('#btn-limpiar-filtros').on('click', function () {
            $('#panel-filtros input[type="text"]').val('');
            $('#panel-filtros select').val(null).trigger('change.select2');
            recargar();
        });

        // --- Exportación: mismos filtros que la pantalla (FR-006) ---
        const query = () => $.param(filtros(), true);

        $('#btn-exportar').on('click', function () {
            // La descarga del Excel no puede ir por AJAX (el navegador tiene que recibir el
            // archivo), pero tampoco navega: se dispara sobre la misma URL con los filtros.
            window.location.assign(rutas.exportar + '?' + query());
        });

        $('#btn-exportar-detallado').on('click', function () {
            window.location.assign(rutas.exportarDetallado + '?' + query());
        });

        $('#btn-exportar-pdf').on('click', function () {
            const url = rutas.pdf + '?' + query();
            // El modal compartido es la vía principal; `window.open` sólo entra como fallback
            // (regla #4 de CLAUDE.md).
            if (window.AppPdf) {
                window.AppPdf.abrir(url, 'Informe de Ventas');
            } else {
                window.open(url, '_blank');
            }
        });

        if (window.bootstrap && window.bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new window.bootstrap.Tooltip(el);
            });
        }

        // Pestañas Rankings / "Arma tu Informe" (spec 069). El cruce usa EXACTAMENTE los mismos
        // filtros que la tabla de detalle, por eso se le pasa `filtros` y no una copia.
        if (window.InformesPivotPantalla) {
            window.InformesPivotPantalla.iniciar({
                informe: 'ventas',
                rutas: rutas,
                filtros: filtros,
                tablaDetalle: '#tabla-informe-ventas',
                datoPorDefecto: 'total_venta',
            });
        }

    });
})();
