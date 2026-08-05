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
                { data: 'total', name: 'total', className: 'text-end fw-bold', render: fmtMoney },
            ],
            order: [[0, 'asc']],
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

        $('#filtro-saldos-cliente').on('select2:select select2:clear change', function () {
            tablaSaldos.ajax.reload();
        });

        // --- Tab: Movimientos ---
        const $tablaMovimientos = $('#tabla-movimientos');
        initSelect2($('#filtro-movimientos-cliente'), clientePickerOptions());
        initSelect2($('#filtro-movimientos-operacion'));

        let fechaDesde = '';
        let fechaHasta = '';
        if ($.fn.daterangepicker) {
            $('#filtro-movimientos-rango-fechas').daterangepicker({
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
                    d.cliente_id = $('#filtro-movimientos-cliente').val();
                    d.operacion = $('#filtro-movimientos-operacion').val();
                    d.fecha_desde = fechaDesde;
                    d.fecha_hasta = fechaHasta;
                },
            },
            columns: [
                { data: 'id', name: 'mov.id' },
                { data: 'fecha_emision', name: 'mov.fecha_emision', render: fmtFecha },
                { data: 'cliente_id', name: 'mov.cliente_id', visible: false },
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
            buttons: [
                {
                    extend: 'colvis',
                    text: '<i class="fas fa-table-columns"></i>',
                    className: 'btn btn-outline-secondary',
                    // "cliente_id" (idx 2) es una columna técnica oculta usada para
                    // el deep-link, no se ofrece en el selector.
                    columns: function (idx) { return idx !== 2; },
                },
            ],
        });

        $tablaMovimientos.one('init.dt', function () {
            tablaMovimientos.buttons().container().appendTo('#dt-buttons-movimientos');
        });

        $('#filtro-movimientos-cliente, #filtro-movimientos-operacion').on('select2:select select2:clear change', function () {
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
    });
})();
