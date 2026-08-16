/**
 * Informe de Compras (spec 067, US1).
 *
 * Todo pasa por AJAX: cambiar el rango, aplicar filtros, mostrar/ocultar columnas o exportar
 * nunca recargan la página (FR-008). jQuery, DataTables (+ Buttons/colVis), Select2, Toastr y
 * daterangepicker los carga el template por pagelevel `informe-compras`.
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[informe-compras] jQuery no está disponible.');
        return;
    }

    const cfg = window.InformeComprasConfig || {};
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

    /** Índices de las columnas del desglose impositivo, ocultas por defecto. */
    const COLUMNAS_POR_DEFECTO = 8; // Id..Total Comprobante

    $(function () {
        const $tabla = $('#tabla-informe-compras');
        if (!$tabla.length) { return; }

        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }

        const money = (v) => (v === null || v === undefined || v === '')
            ? ''
            : new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
        const cantidad = (v) => (v === null || v === undefined || v === '')
            ? ''
            : new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 3 }).format(v);
        const fecha = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('/') : '');

        initSelect2($('#filtro-tipo-producto'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-etiqueta'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-categoria'), { placeholder: 'Todas', allowClear: true });
        initSelect2($('#filtro-usuario'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-estado-pago'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-tipo-comprobante'), { placeholder: 'Todos', allowClear: true });
        initSelect2($('#filtro-facturado'), { placeholder: 'Todos', allowClear: true });

        // Catálogos grandes: Select2 con `ajax` en vez de volcar miles de <option> en el HTML.
        initSelect2($('#filtro-proveedor'), {
            placeholder: 'Todos', allowClear: true, multiple: true,
            ajax: {
                url: rutas.proveedoresOpciones, delay: 250,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({ results: (data.data || []).map((p) => ({ id: p.id, text: p.nombre })) }),
            },
        });
        initSelect2($('#filtro-producto'), {
            placeholder: 'Todos', allowClear: true, multiple: true,
            ajax: {
                url: rutas.productosOpciones, delay: 250,
                data: (params) => ({ q: params.term }),
                processResults: (data) => ({
                    results: (data.data || []).map((p) => ({ id: p.id, text: p.codigo ? p.codigo + ' — ' + p.nombre : p.nombre })),
                }),
            },
        });

        // --- Rango de Emisión: arranca en Mes actual (FR-004b), no vacío ---
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
            // "Borrar filtro" vuelve al mes actual y no a "sin rango": un informe de compras sin
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
                fecha_desde: fechaDesde,
                fecha_hasta: fechaHasta,
                id: $('#filtro-id').val(),
                producto_servicio: $('#filtro-producto-servicio').val(),
                tipo_producto_id: $('#filtro-tipo-producto').val(),
                etiqueta_id: $('#filtro-etiqueta').val(),
                producto_id: $('#filtro-producto').val(),
                facturado: $('#filtro-facturado').val(),
                categoria_id: $('#filtro-categoria').val(),
                proveedor_id: $('#filtro-proveedor').val(),
                tipo_comprobante: $('#filtro-tipo-comprobante').val(),
                nro_comprobante: $('#filtro-nro-comprobante').val(),
                usuario_id: $('#filtro-usuario').val(),
                observacion: $('#filtro-observacion').val(),
                estado_pago: $('#filtro-estado-pago').val(),
            };
        }

        const columnas = [
            { data: 'id', name: 'id' },
            { data: 'fecha', name: 'fecha', render: fecha },
            { data: 'comprobante', name: 'comprobante', defaultContent: '' },
            { data: 'proveedor', name: 'proveedor', defaultContent: '' },
            { data: 'producto_servicio', name: 'producto_servicio', defaultContent: '' },
            { data: 'cantidad', name: 'cantidad', className: 'text-end', render: cantidad },
            { data: 'precio', name: 'precio', className: 'text-end', render: money },
            { data: 'total_comprobante', name: 'total_comprobante', className: 'text-end', render: money },
        ];

        // Columnas opcionales: llegan siempre en el JSON (contrato) y se muestran con colvis.
        const opcionales = [
            ['vencimiento', fecha], ['cuit_dni', null], ['tipo', null], ['tipo_comprobante', null],
            ['punto_venta', null], ['nro_factura', null], ['codigo', null], ['tipo_producto', null],
            ['costo', money], ['subtotal_sin_descuento', money], ['descuento_monto', money],
            ['subtotal_con_descuento', money], ['neto_no_gravado', money], ['neto_exento', money],
            ['neto_gravado', money], ['iva_2_5', money], ['iva_5', money], ['iva_10_5', money],
            ['iva_21', money], ['iva_27', money], ['perc_iva', money], ['perc_iibb', money],
            ['otras_percepciones', money], ['imp_internos', money], ['total_compra', money],
            ['etiquetas', null], ['afecta_stock', null], ['operacion', null],
        ];

        opcionales.forEach(function (par) {
            const col = { data: par[0], name: par[0], defaultContent: '', visible: false };
            if (par[1]) { col.render = par[1]; col.className = 'text-end'; }
            columnas.push(col);
        });

        const tabla = $tabla.DataTable({
            processing: true,
            serverSide: true,
            language: {
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                infoEmpty: 'Sin compras en el período',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No hay compras en el período seleccionado',
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
            columns: columnas,
            order: [[1, 'desc']],
            stateSave: true,
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                },
            ],
        });

        $tabla.one('init.dt', function () {
            tabla.buttons().container().appendTo('#dt-buttons-compras');
        });

        function actualizarKpis() {
            $.getJSON(rutas.stats, filtros())
                .done(function (k) {
                    $('#kpi-creadas').text('$ ' + money(k.total_compras_creadas));
                    $('#kpi-nd').text('$ ' + money(k.total_nota_debito));
                    $('#kpi-nc').text('$ ' + money(k.total_nota_credito));
                    $('#kpi-total').text('$ ' + money(k.total_compras));
                    $('#kpi-unidades').text(cantidad(k.cantidad_prod_serv));
                    $('#kpi-cantidad').text(k.cantidad_compras_creadas);
                    $('#kpi-promedio').text('$ ' + money(k.compra_promedio));
                    $('#kpi-costo').text('$ ' + money(k.costo_actual));
                })
                .fail(function (xhr) {
                    avisar((xhr.responseJSON && xhr.responseJSON.message) || 'No se pudieron calcular los totales.');
                });
        }

        function recargar() {
            tabla.ajax.reload();
            actualizarKpis();
        }

        // Los KPIs tienen que reflejar exactamente los mismos filtros que la tabla, así que se
        // recalculan cada vez que la tabla vuelve a pedir datos y no sólo al tocar "Buscar".
        actualizarKpis();

        $('#btn-aplicar-filtros').on('click', recargar);
        $('#btn-limpiar-filtros').on('click', function () {
            $('#panel-filtros input[type="text"]').val('');
            $('#panel-filtros select').val(null).trigger('change.select2');
            recargar();
        });

        // --- Exportación: mismos filtros que la pantalla ---
        const query = () => $.param(filtros(), true);

        $('#btn-exportar').on('click', function () {
            // La descarga del Excel no puede ir por AJAX (el navegador tiene que recibir el
            // archivo), pero tampoco navega: se dispara sobre la misma URL con los filtros.
            window.location.assign(rutas.exportar + '?' + query());
        });

        $('#btn-exportar-pdf').on('click', function () {
            const url = rutas.pdf + '?' + query();
            if (window.AppPdf) {
                window.AppPdf.abrir(url, 'Informe de Compras');
            } else {
                window.open(url, '_blank');
            }
        });

        if (window.bootstrap && window.bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new window.bootstrap.Tooltip(el);
            });
        }
    });
})();
