/**
 * Informe de Stock — DataTable server-side de sólo lectura + filtros + KPIs.
 *
 * Reutiliza el patrón de Productos: jQuery, Toastr, DataTables, Select2 y
 * bootstrap-daterangepicker cargados globalmente por el template NexaDash
 * (config/dz.php pagelevel 'informe-stock').
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[informe-stock] jQuery no está disponible.');
        return;
    }

    const cfg = window.InformeStockConfig || {};
    const rutas = cfg.rutas || {};

    // Configuración global reutilizable de Toastr.
    if (window.toastr) {
        window.toastr.options = {
            closeButton: true,
            progressBar: true,
            positionClass: 'toast-top-right',
            preventDuplicates: true,
            newestOnTop: true,
            timeOut: 4000,
            extendedTimeOut: 1500,
        };
    }

    const CSRF = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

    $(function () {
        const $tabla = $('#tabla-informe-stock');
        if (!$tabla.length) {
            return;
        }

        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }
        function refreshSelect2($el) {
            if (hasSelect2 && $el && $el.length && $el.hasClass('select2-hidden-accessible')) {
                $el.trigger('change.select2');
            }
        }

        function etiquetaProducto(p) {
            return p.codigo ? (p.nombre + ' (' + p.codigo + ')') : p.nombre;
        }

        // --- Selects dinámicos con Select2 (Usuario, Operación, Proveedor, Tipo de Producto, Estado) ---
        initSelect2($('#filtro-usuario'));
        initSelect2($('#filtro-operacion'));
        initSelect2($('#filtro-proveedor'));
        initSelect2($('#filtro-tipo-producto'));
        initSelect2($('#filtro-estado'), { minimumResultsForSearch: Infinity });

        // Picker de producto con Select2 + búsqueda remota (igual que el de Ajuste de Stock global).
        initSelect2($('#filtro-producto'), {
            placeholder: 'Todos',
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: rutas.opciones,
                dataType: 'json',
                delay: 250,
                data: function (params) { return { q: params.term || '' }; },
                processResults: function (data) {
                    return { results: (data.data || []).map(function (p) { return { id: p.id, text: etiquetaProducto(p) }; }) };
                },
            },
        });

        // --- Rango de fechas (bootstrap-daterangepicker) ---
        let fechaDesde = '';
        let fechaHasta = '';
        if ($.fn.daterangepicker) {
            $('#filtro-rango-fechas').daterangepicker({
                autoUpdateInput: false,
                opens: 'left',
                locale: {
                    format: 'DD/MM/YYYY',
                    applyLabel: 'Aplicar',
                    cancelLabel: 'Limpiar',
                    fromLabel: 'Desde',
                    toLabel: 'Hasta',
                    customRangeLabel: 'Personalizado',
                    daysOfWeek: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa'],
                    monthNames: [
                        'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
                    ],
                },
            });
            $('#filtro-rango-fechas').on('apply.daterangepicker', function (e, picker) {
                fechaDesde = picker.startDate.format('YYYY-MM-DD');
                fechaHasta = picker.endDate.format('YYYY-MM-DD');
                $(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
                tabla.ajax.reload();
                refrescarStats();
            });
            $('#filtro-rango-fechas').on('cancel.daterangepicker', function () {
                fechaDesde = '';
                fechaHasta = '';
                $(this).val('');
                tabla.ajax.reload();
                refrescarStats();
            });
        }

        // --- DataTable server-side ---
        const tabla = $tabla.DataTable({
            processing: true,
            serverSide: true,
            responsive: true,
            language: {
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ movimientos',
                infoEmpty: 'Sin movimientos',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron movimientos',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: {
                url: rutas.data,
                data: function (d) {
                    d.usuario_id = $('#filtro-usuario').val();
                    d.operacion = $('#filtro-operacion').val();
                    d.proveedor_id = $('#filtro-proveedor').val();
                    d.tipo_producto_id = $('#filtro-tipo-producto').val();
                    d.producto_id = $('#filtro-producto').val();
                    d.estado = $('#filtro-estado').val();
                    d.fecha_desde = fechaDesde;
                    d.fecha_hasta = fechaHasta;
                },
            },
            columns: [
                {
                    data: 'fecha', name: 'fecha',
                    render: function (val) { return val ? String(val).slice(0, 10).split('-').reverse().join('/') : ''; },
                },
                {
                    data: 'tipo', name: 'tipo',
                    render: function (val) {
                        const etiquetas = { ajuste: 'Ajuste', transferencia: 'Transferencia', entrada: 'Entrada', salida: 'Salida' };
                        return etiquetas[val] || val;
                    },
                },
                { data: 'descripcion', name: 'descripcion', defaultContent: '' },
                { data: 'producto', name: 'producto', defaultContent: '' },
                {
                    data: 'cantidad', name: 'cantidad', className: 'text-end',
                    render: function (val) { return new Intl.NumberFormat('es-AR').format(val || 0); },
                },
                {
                    data: 'stock_saldo', name: 'stock_saldo', className: 'text-end',
                    render: function (val) { return new Intl.NumberFormat('es-AR').format(val || 0); },
                },
                { data: 'usuario', name: 'usuario', defaultContent: '' },
            ],
            order: [[0, 'asc']],
        });

        // --- Filtros ---
        $('#btn-aplicar-filtros').on('click', function () { tabla.ajax.reload(); refrescarStats(); });
        $('#filtro-usuario, #filtro-operacion, #filtro-proveedor, #filtro-tipo-producto, #filtro-estado, #filtro-producto').on('change', function () {
            tabla.ajax.reload();
            refrescarStats();
        });
        $('#btn-limpiar-filtros').on('click', function () {
            $('#filtro-usuario, #filtro-operacion, #filtro-proveedor, #filtro-tipo-producto').val('').trigger('change.select2');
            $('#filtro-estado').val('todos').trigger('change.select2');
            $('#filtro-producto').val(null).trigger('change');
            $('#filtro-rango-fechas').val('');
            fechaDesde = '';
            fechaHasta = '';
            tabla.ajax.reload();
            refrescarStats();
        });

        // --- KPIs (recalculados según filtros de producto vigentes) ---
        function refrescarStats() {
            if (!rutas.stats) {
                return;
            }
            $.getJSON(rutas.stats, {
                proveedor_id: $('#filtro-proveedor').val(),
                tipo_producto_id: $('#filtro-tipo-producto').val(),
                producto_id: $('#filtro-producto').val(),
                estado: $('#filtro-estado').val(),
            }).done(function (s) {
                const fmt = function (n) { return new Intl.NumberFormat('es-AR').format(n); };
                const fmtMoney = function (n) { return new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2 }).format(n); };
                $('#stat-unidades').text(fmt(s.unidades_en_stock));
                $('#stat-costo-total').text('$ ' + fmtMoney(s.costo_total));
                $('#stat-valor-venta-total').text('$ ' + fmtMoney(s.valor_venta_total));
            });
        }

        // Pre-selección de "Productos" al llegar desde "Movimientos" de Productos
        // (querystring ?producto_id=): la opción ya viene pre-renderizada en el
        // <option> inicial del select (ver informes/stock/index.blade.php), sólo
        // hace falta refrescar la UI de Select2 para que la muestre seleccionada.
        if (cfg.productoId) {
            refreshSelect2($('#filtro-producto'));
        }
    });
})();
