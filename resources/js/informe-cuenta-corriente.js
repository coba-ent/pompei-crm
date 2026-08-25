/**
 * Informe de Cuenta Corriente (Clientes) — dos DataTables server-side
 * (Saldos Clientes / Movimientos) sobre un único shell con tabs Bootstrap.
 * Reutiliza el patrón de Informe de Stock: jQuery, Toastr, DataTables,
 * Select2 y bootstrap-daterangepicker cargados globalmente por el template
 * NexaDash (config/dz.php pagelevel 'informe-cuenta-corriente').
 */
(function () {
    'use strict';

    const $ = window.jQuery;
    if (!$) {
        console.error('[informe-cuenta-corriente] jQuery no está disponible.');
        return;
    }

    const cfg = window.InformeCuentaCorrienteConfig || {};
    const rutas = cfg.rutas || {};

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
        const $tablaSaldos = $('#tabla-saldos-clientes');
        if (!$tablaSaldos.length) {
            return;
        }

        const hasSelect2 = !!($.fn && $.fn.select2);
        function initSelect2($el, opts) {
            if (!hasSelect2 || !$el || !$el.length) { return; }
            $el.select2(Object.assign({ width: '100%', theme: 'default' }, opts || {}));
        }

        function fmtMoney(n) {
            return new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
        }
        function fmtFecha(val) {
            return val ? String(val).slice(0, 10).split('-').reverse().join('/') : '';
        }

        function clientePickerOptions(placeholder) {
            return {
                placeholder: placeholder || 'Todos',
                allowClear: true,
                minimumInputLength: 0,
                ajax: {
                    url: rutas.clientesOpciones,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) { return { q: params.term || '' }; },
                    processResults: function (data) {
                        return { results: (data.data || []).map(function (c) { return { id: c.id, text: c.nombre }; }) };
                    },
                },
            };
        }

        // --- Tab: Saldos Clientes ---
        initSelect2($('#filtro-saldos-cliente'), clientePickerOptions());

        const tablaSaldos = $tablaSaldos.DataTable({
            processing: true,
            serverSide: true,
            language: {
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ clientes',
                infoEmpty: 'Sin clientes con saldo pendiente',
                infoFiltered: '(filtrado de _MAX_ en total)',
                zeroRecords: 'No se encontraron clientes con saldo pendiente',
                paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' },
                processing: 'Cargando...',
            },
            ajax: {
                url: rutas.saldosData,
                data: function (d) {
                    d.cliente_id = $('#filtro-saldos-cliente').val();
                },
            },
            columns: [
                { data: 'cliente_nombre', name: 'cliente_nombre' },
                { data: 'a_vencer', name: 'a_vencer', className: 'text-end', render: fmtMoney },
                { data: 'vencido_0_30', name: 'vencido_0_30', className: 'text-end', render: fmtMoney },
                { data: 'vencido_31_60', name: 'vencido_31_60', className: 'text-end', render: fmtMoney },
                { data: 'vencido_61_90', name: 'vencido_61_90', className: 'text-end', render: fmtMoney },
                { data: 'vencido_mas_90', name: 'vencido_mas_90', className: 'text-end', render: fmtMoney },
                {
                    data: 'total', name: 'total', className: 'text-end fw-bold',
                    render: function (val, type, row) {
                        if (type !== 'display') { return val; }
                        const url = rutas.index + '?cliente_id=' + row.cliente_id;
                        return '<a href="' + url + '" class="link-saldo-total text-reset">' + fmtMoney(val) + '</a>';
                    },
                },
            ],
            order: [[6, 'desc']],
            stateSave: true,
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                },
            ],
        });

        $tablaSaldos.one('init.dt', function () {
            tablaSaldos.buttons().container().appendTo('#dt-buttons-saldos-clientes');
        });

        // El Total de cada fila es un <a> con href real a Movimientos filtrado por ese
        // cliente: la navegación normal la hace el navegador, y así funcionan tambien
        // el clic con la rueda / Ctrl+clic (abrir en pestaña nueva).

        $('#filtro-saldos-cliente').on('select2:select select2:clear change', function () {
            tablaSaldos.ajax.reload();
        });

        // --- Tab: Movimientos ---
        const $tablaMovimientos = $('#tabla-movimientos');
        initSelect2($('#filtro-movimientos-cliente'), Object.assign(clientePickerOptions(), { multiple: true }));
        initSelect2($('#filtro-movimientos-operacion'), { placeholder: 'Todas', allowClear: true, multiple: true });

        let fechaDesde = '';
        let fechaHasta = '';
        if ($.fn.daterangepicker) {
            // Presets compartidos (`resources/js/rango-emision.js`, spec 067 T002/T003): esta
            // pantalla no tenía `ranges`, así que gana los 7 accesos rápidos que el resto del
            // CRM ya ofrecía, con los mismos rótulos ("Borrar filtro", "Desde - Hasta").
            $('#filtro-movimientos-rango-fechas').daterangepicker(window.RangoEmision.opciones());
            $('#filtro-movimientos-rango-fechas').on('apply.daterangepicker', function (e, picker) {
                fechaDesde = picker.startDate.format('YYYY-MM-DD');
                fechaHasta = picker.endDate.format('YYYY-MM-DD');
                $(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
                tablaMovimientos.ajax.reload();
            });
            $('#filtro-movimientos-rango-fechas').on('cancel.daterangepicker', function () {
                fechaDesde = '';
                fechaHasta = '';
                $(this).val('');
                tablaMovimientos.ajax.reload();
            });
        }

        const ETIQUETAS_OPERACION = {
            venta: 'Venta',
            cobro: 'Cobro',
            nota_credito: 'Nota de Crédito',
            nota_debito: 'Nota de Débito',
            saldo_inicial: 'Saldo Inicial',
        };

        const tablaMovimientos = $tablaMovimientos.DataTable({
            processing: true,
            serverSide: true,
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
                url: rutas.movimientosData,
                data: function (d) {
                    d.cliente_id = $('#filtro-movimientos-cliente').val() || [];
                    d.operacion = $('#filtro-movimientos-operacion').val() || [];
                    d.fecha_desde = fechaDesde;
                    d.fecha_hasta = fechaHasta;
                },
            },
            columns: [
                { data: 'id', name: 'mov.id' },
                { data: 'fecha_emision', name: 'mov.fecha_emision', render: fmtFecha },
                { data: 'cliente', name: 'mov.cliente', defaultContent: '' },
                {
                    data: 'operacion', name: 'mov.operacion',
                    render: function (val) { return ETIQUETAS_OPERACION[val] || val; },
                },
                { data: 'categoria', name: 'mov.categoria', defaultContent: '' },
                { data: 'total_venta', name: 'mov.total_venta', className: 'text-end', render: function (v) { return v === null ? '' : fmtMoney(v); } },
                { data: 'cobrado', name: 'mov.cobrado', className: 'text-end', render: function (v) { return v === null ? '' : fmtMoney(v); } },
                { data: 'a_cobrar', name: 'mov.a_cobrar', className: 'text-end', render: function (v) { return v === null ? '' : fmtMoney(v); } },
                { data: 'nro_comprobante', name: 'mov.nro_comprobante', defaultContent: '' },
                { data: 'medio_cobro', name: 'mov.medio_cobro', defaultContent: '' },
                { data: 'descripcion', name: 'mov.descripcion', defaultContent: '' },
            ],
            order: [[1, 'desc']],
            stateSave: true,
            // La columna 2 era "cliente_id", técnica y oculta; ahora es el nombre del
            // cliente y va visible (como en Contagram). Sin esto, un estado guardado de
            // antes la seguiría escondiendo para siempre.
            stateLoadParams: function (settings, state) {
                if (state.columns && state.columns[2]) { state.columns[2].visible = true; }
            },
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                },
            ],
        });

        $tablaMovimientos.one('init.dt', function () {
            tablaMovimientos.buttons().container().appendTo('#dt-buttons-movimientos');
        });

        $('#filtro-movimientos-cliente, #filtro-movimientos-operacion').on('select2:select select2:unselect select2:clear change', function () {
            tablaMovimientos.ajax.reload();
        });

        // Recalcular el layout de las tablas al mostrar cada tab (DataTables
        // calcula anchos con el contenedor visible; en un tab oculto da 0).
        $('#tab-saldos-clientes-btn').on('shown.bs.tab', function () { tablaSaldos.columns.adjust(); });
        $('#tab-movimientos-btn').on('shown.bs.tab', function () { tablaMovimientos.columns.adjust(); });

        // Deep-link desde "Cta Cte" en el menú de fila de Clientes (?cliente_id=):
        // el <option selected> ya viene pre-renderizado (ver index.blade.php),
        // sólo hace falta refrescar la UI de Select2 para que lo muestre.
        if (cfg.clienteId) {
            $('#filtro-movimientos-cliente').trigger('change.select2');
        }

        // ------------------------------------------------------------------------ Exportar
        // Exportar/PDF sólo cubren la pestaña Saldos (igual que Contagram: el botón no
        // incluye Movimientos), así que sólo toman el filtro Cliente de esa pestaña.
        function filtros() {
            const clienteId = $('#filtro-saldos-cliente').val();
            return { cliente_id: clienteId ? [clienteId] : [] };
        }

        $('#btn-exportar').on('click', function () {
            window.location.assign(rutas.exportar + '?' + $.param(filtros(), true));
        });

        $('#btn-exportar-pdf').on('click', function () {
            const url = rutas.pdf + '?' + $.param(filtros(), true);
            if (window.AppPdf) {
                window.AppPdf.abrir(url, 'Cuenta Corriente Clientes');
            } else {
                window.open(url, '_blank');
            }
        });
    });
})();
